<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class GeneratorRuntimeRule
{
    public function stripPreload(string $source): string
    {
        $tokens = $this->tokens($source);
        $depth = 0;
        $namespaceDepth = 0;
        $edits = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_NAMESPACE) {
                for ($cursor = $index + 1; isset($tokens[$cursor]) && !in_array($tokens[$cursor]['text'], [';', '{'], true); ++$cursor) {}
                $namespaceDepth = ($tokens[$cursor]['text'] ?? '') === '{' ? 1 : 0;
            }
            if ($depth === $namespaceDepth && in_array($token['id'], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                && strtolower(ltrim($token['text'], '\\')) === 'class_exists'
            ) {
                $cursor = $index + 1;
                if (($tokens[$cursor++]['text'] ?? '') !== '(') { throw new ConfigurationException('preload declaration call is unsupported'); }
                $class = $tokens[$cursor++] ?? [];
                if (!in_array($class['id'] ?? null, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    || ($tokens[$cursor++]['text'] ?? '') !== '::'
                    || strtolower($tokens[$cursor++]['text'] ?? '') !== 'class'
                    || ($tokens[$cursor++]['text'] ?? '') !== ')'
                    || ($tokens[$cursor]['text'] ?? '') !== ';'
                    || ($index > 0 && !in_array($tokens[$index - 1]['text'], [';', '}'], true))
                ) { throw new ConfigurationException('preload hint must be a standalone class declaration discovery call'); }
                $edits[] = [$token['offset'], $tokens[$cursor]['end']];
            }
            if ($token['text'] === '{') { ++$depth; }
            elseif ($token['text'] === '}') { --$depth; }
        }
        foreach (array_reverse($edits) as [$start, $end]) { $source = substr_replace($source, '', $start, $end - $start); }
        return $source;
    }

    public function stripBootstrap(string $source): string
    {
        $tokens = $this->tokens($source);
        $end = count($tokens);
        $calls = [];
        $namespace = '';
        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_NAMESPACE) {
                for ($cursor = $index + 1; isset($tokens[$cursor]) && !in_array($tokens[$cursor]['text'], [';', '{'], true); ++$cursor) { $namespace .= $tokens[$cursor]['text']; }
                break;
            }
        }
        $replacedByMain = [
            'Workerman\\Protocols\\Http\\Session::init',
            'Workerman\\Protocols\\Http\\Session\\FileSessionHandler::init',
            'Workerman\\Coroutine::init',
            'Workerman\\Coroutine\\Coroutine\\Fiber::init',
            'Workerman\\Coroutine\\Context::initDriver',
            'Workerman\\Coroutine\\Context\\Fiber::initContext',
        ];
        while ($end >= 6 && $tokens[$end - 1]['text'] === ';') {
            $call = array_column(array_slice($tokens, $end - 6, 6), 'text');
            if (!in_array($call[0], ['Session', 'FileSessionHandler', 'Fiber', 'Coroutine', 'Context'], true)
                || $call[1] !== '::' || !in_array($call[2], ['initContext', 'initDriver', 'init'], true)
                || array_slice($call, 3) !== ['(', ')', ';']
            ) { break; }
            $identity = $namespace . '\\' . $call[0] . '::' . $call[2];
            if (!in_array($identity, $replacedByMain, true)) { throw new ConfigurationException('runtime bootstrap call has no equivalent main replacement: ' . $identity); }
            if (isset($calls[$identity])) { throw new ConfigurationException('duplicate runtime bootstrap call cannot be replaced by main'); }
            $calls[$identity] = true;
            $end -= 6;
        }
        if ($calls === []) { return $source; }
        if (($tokens[$end - 1]['text'] ?? '') !== '}') { throw new ConfigurationException('runtime bootstrap call is not after its declaration'); }
        $depth = 0;
        foreach (array_slice($tokens, 0, $end) as $token) {
            if ($token['text'] === '{') { ++$depth; }
            elseif ($token['text'] === '}') { --$depth; }
        }
        if ($depth !== 0) { throw new ConfigurationException('runtime bootstrap call is nested in an unsupported scope'); }
        return substr($source, 0, $tokens[$end]['offset']);
    }

    public function nullableStatics(string $source): string
    {
        $tokens = $this->tokens($source);
        $edits = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_STATIC) { continue; }
            $type = $tokens[$index + 1] ?? [];
            $variable = $tokens[$index + 2] ?? [];
            if (in_array($tokens[$index - 1]['id'] ?? null, [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)
                && in_array($type['text'] ?? '', ['bool', 'int', 'float', 'string'], true)
                && ($variable['id'] ?? null) === T_VARIABLE
                && ($tokens[$index + 3]['text'] ?? '') === ';'
            ) {
                $edits[] = [$type['offset'], 0, '?'];
                $edits[] = [$variable['end'], 0, ' = null'];
            }
        }
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$at, $length, $text]) { $source = substr_replace($source, $text, $at, $length); }
        $tokens = $this->tokens($source);
        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_FUNCTION && ($tokens[$index + 1]['text'] ?? '') === 'wait') {
                $parameters = array_column(array_slice($tokens, $index + 2, 4), 'text');
                if (($parameters[0] ?? '') === '(' && ($parameters[2] ?? '') === '&' && ($parameters[3] ?? '') === '$barrier') {
                    if (!in_array($parameters[1] ?? '', ['object', 'mixed'], true)) { throw new ConfigurationException('Swoole Barrier reference parameter type is unsupported'); }
                    if ($parameters[1] === 'object') {
                        $source = substr_replace($source, 'mixed', $tokens[$index + 3]['offset'], $tokens[$index + 3]['end'] - $tokens[$index + 3]['offset']);
                    }
                }
            }
        }
        return $source;
    }

    public function contextWrites(string $source): string
    {
        return (new UpstreamSourceRule())->replace('vendor/workerman/coroutine/src/Context/Fiber.php', $source, [
            'static::$nonFiberContext[$name] = $value;' => 'static::$nonFiberContext->offsetSet($name, $value);',
            'static::$contexts[$fiber][$name] = $value;' => 'static::$contexts[$fiber]->offsetSet($name, $value);',
        ]);
    }

    public function cacheTags(string $source): string
    {
        $tokens = $this->tokens($source);
        $edits = [];
        $shape = 'if(is_array($input)){ $tags=[]; foreach($input as $id){ $tags[]=$cache[\'key\'].$id; } }else{ $tags=$cache[\'key\'].$input; } return Cache::tag($tags)->clear();';
        $needle = $this->tokens('<?php ' . $shape);
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_IF) { continue; }
            $bindings = [];
            foreach ($needle as $relative => $expected) {
                $actual = $tokens[$index + $relative] ?? [];
                if (in_array($expected['text'], ['$input', '$id', '$tags', '$cache', "'key'"], true)) {
                    if (($actual['id'] ?? null) !== $expected['id']
                        || (isset($bindings[$expected['text']]) && $bindings[$expected['text']] !== ($actual['text'] ?? null))
                    ) { continue 2; }
                    $bindings[$expected['text']] = $actual['text'];
                } elseif (($actual['id'] ?? null) !== $expected['id'] || ($actual['text'] ?? null) !== $expected['text']) { continue 2; }
            }
            $input = $bindings['$input']; $id = $bindings['$id']; $tags = $bindings['$tags']; $cache = $bindings['$cache']; $key = $bindings["'key'"];
            $tag = '$aotCacheTag';
            $variables = array_fill_keys(array_column(array_filter($tokens, static fn (array $actual): bool => $actual['id'] === T_VARIABLE), 'text'), true);
            while (isset($variables[$tag])) { $tag .= '_'; }
            $replacement = "if (is_array({$input})) { {$tags} = []; foreach ({$input} as {$id}) { {$tags}[] = {$cache}[{$key}] . {$id}; } return Cache::tag({$tags})->clear(); } {$tag} = {$cache}[{$key}] . {$input}; return Cache::tag({$tag})->clear();";
            $edits[] = [$token['offset'], $tokens[$index + count($needle) - 1]['end'], $replacement];
        }
        if ($edits === []) {
            $text = implode('', array_column($tokens, 'text'));
            if (str_contains($text, '$tags=$cache[')) { throw new ConfigurationException('cache tag array/scalar branch is unsupported'); }
            return $source;
        }
        foreach (array_reverse($edits) as [$start, $end, $replacement]) { $source = substr_replace($source, $replacement, $start, $end - $start); }
        return $source;
    }

    private function tokens(string $source): array
    {
        $tokens = []; $offset = 0;
        foreach (token_get_all($source) as $token) {
            $id = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;
            if (!in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = ['id' => $id, 'text' => $text, 'offset' => $offset, 'end' => $offset + strlen($text)];
            }
            $offset += strlen($text);
        }
        return $tokens;
    }
}
