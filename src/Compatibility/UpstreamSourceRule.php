<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class UpstreamSourceRule
{
    /** @param array<string,string> $replacements */
    public function replace(string $path, string $source, array $replacements, bool $preserveNestedFunctions = false): string
    {
        if ($path === 'vendor/nesbot/carbon/src/Carbon/Traits/Localization.php') { return $this->carbonLocalization($source, $replacements); }
        if ($path === 'vendor/symfony/http-kernel/EventListener/ErrorListener.php') { return $this->errorListener($source, $replacements); }
        if ($path === 'vendor/nesbot/carbon/src/Carbon/Traits/Creator.php') { return $this->carbonCreator($source, $replacements); }
        if ($path === 'vendor/illuminate/database/Schema/Blueprint.php') { return $this->blueprint($source, $replacements); }
        if (str_starts_with($path, 'vendor/nesbot/carbon/lazy/Carbon/') && str_ends_with($path, 'StrongType.php')) { return $this->carbonLazyDeclaration($path, $source); }
        [$source, $replacements] = $this->scopedReplacements($path, $source, $replacements);
        if ($path === 'vendor/workerman/workerman/src/Protocols/Websocket.php') {
            return $this->websocketHeaders($source);
        }
        if (in_array($path, ['vendor/symfony/console/Helper/QuestionHelper.php', 'vendor/symfony/http-kernel/Controller/ControllerResolver.php'], true)) {
            $method = str_contains($path, 'QuestionHelper') ? 'doAsk' : 'createController';
            $independent = [];
            foreach ($replacements as $before => $after) {
                if (str_starts_with(trim($before), 'mb_strlen(')) { $independent[$before] = $after; unset($replacements[$before]); }
            }
            [$start, $end] = $this->methodSpan($source, $method, true);
            $body = substr($source, $start, $end - $start);
            $scoped = $this->replace($path . '#' . $method, '<?php ' . $body, $this->contextualReplacements($path, $replacements), true);
            $source = substr_replace($source, substr($scoped, 6), $start, $end - $start);
            return $this->replace($path . '#independent', $source, $independent);
        }
        $replacements = $this->contextualReplacements($path, $replacements);
        foreach ($replacements as $before => $after) {
            $tokens = $this->tokens($source);
            $needle = $this->tokens('<?php ' . $before);
            $replacement = $this->tokens('<?php ' . $after);
            if ($needle === []) {
                if ($path === 'vendor/symfony/http-foundation/Request.php') {
                    $source = $this->requestFormats($source);
                    continue;
                }
                throw new ConfigurationException('upstream conversion has no executable source contract: ' . $path);
            }
            $match = fn (array $haystack, array $pattern): array => $this->scopedMatches($haystack, $pattern, $preserveNestedFunctions, str_ends_with($path, '#switch'));
            $adapted = $match($tokens, $replacement);
            $original = $this->unadapted($match($tokens, $needle), count($needle), $adapted, count($replacement));
            if ($original === [] && $adapted === []) {
                if ($replacement === [] && $this->deletedSourceIsAbsent($tokens, $needle)) { continue; }
                throw new ConfigurationException('upstream source conversion structure drift: ' . $path . ' at ' . trim($before));
            }
            foreach (array_reverse($original) as $index) {
                $matched = array_slice($tokens, $index, count($needle));
                $start = $matched[0]['offset'];
                $end = $matched[count($matched) - 1]['end'];
                $fragment = substr($source, $start, $end - $start);
                $adaptation = $this->rewriteTokens($fragment, $matched, $start, $replacement, '<?php ' . $after);
                if ($this->tokenTexts($this->tokens('<?php ' . $adaptation)) !== $this->tokenTexts($replacement)) {
                    throw new ConfigurationException('upstream conversion replacement contract failed: ' . $path);
                }
                $source = substr_replace($source, $adaptation, $start, $end - $start);
            }
            $output = $this->tokens($source);
            if ($this->unadapted($match($output, $needle), count($needle), $match($output, $replacement), count($replacement)) !== []) {
                throw new ConfigurationException('upstream conversion left an unsupported occurrence: ' . $path);
            }
        }
        return $source;
    }

    private function scopedReplacements(string $path, string $source, array $rules): array
    {
        $methods = [];
        $switches = [];
        foreach ($rules as $before => $after) {
            $owners = GeneratorSourceScope::methods($path, $before, $after);
            if ($owners === null) { continue; }
            if ($owners === ['*']) {
                $owners = [];
                $tokens = $this->tokens($source);
                foreach ($tokens as $index => $token) {
                    if ($token['id'] === T_FUNCTION && ($tokens[$index + 1]['id'] ?? null) === T_STRING) {
                        $owners[] = $tokens[$index + 1]['text'];
                    }
                }
                $owners = array_values(array_filter($owners, function (string $method) use ($source, $before): bool {
                    [$start, $end] = $this->methodSpan($source, $method, true);
                    return $this->scopedMatches($this->tokens('<?php ' . substr($source, $start, $end - $start)), $this->tokens('<?php ' . $before), true) !== [];
                }));
            }
            foreach ($owners as $method) {
                $expression = GeneratorSourceScope::switchExpression($path, $method, $before);
                if ($expression === null) { $methods[$method][$before] = $after; }
                else { $key = $method . ':' . $expression; $switches[$key] = ['method' => $method, 'expression' => $expression, 'rules' => ($switches[$key]['rules'] ?? []) + [$before => $after]]; }
            }
            unset($rules[$before]);
        }
        foreach ($methods as $method => $scoped) { $source = $this->replaceMethod($path, $source, $method, $scoped); }
        foreach ($switches as $scope) { $source = $this->replaceSwitch($path, $source, $scope['method'], $scope['expression'], $scope['rules']); }
        return [$source, $rules];
    }

    private function errorListener(string $source, array $rules): string
    {
        $logRules = [];
        $reflectionRules = [];
        foreach ($rules as $before => $after) {
            $tokens = implode('', array_column($this->tokens('<?php ' . $before), 'text'));
            if (str_contains($tokens, 'functionlogException(') || str_starts_with($tokens, '$logChannel=')) {
                $logRules[$before] = $after;
            } elseif (!str_starts_with($tokens, '$class=new\ReflectionClass($interface)')
                && !str_starts_with($tokens, 'if($attributes=$reflectionClass->getAttributes')
            ) {
                $reflectionRules[$before] = $after;
            }
        }
        $source = $this->replaceMethod('error-listener#logging', $source, 'logException', $logRules);
        [$start, $end] = $this->methodSpan($source, 'getInheritedAttribute', true);
        $method = '<?php ' . substr($source, $start, $end - $start);
        $tokens = $this->tokens($method);
        $prefix = $this->tokens('<?php foreach ($ownInterfaces as $interface) {');
        $loops = $this->scopedMatches($tokens, $prefix, true);
        if (count($loops) !== 1) { throw new ConfigurationException('ErrorListener interface reflection loop binding is unsupported'); }
        $open = $loops[0] + count($prefix) - 1;
        $close = $this->closingScope($tokens, $open, '{', '}');
        $at = $tokens[$open]['end'];
        $length = $tokens[$close]['offset'] - $at;
        $body = '<?php ' . substr($method, $at, $length);
        $body = $this->replace('error-listener#interface-reflection', $body, [
            '$class = new \ReflectionClass($interface);' => '$interfaceReflection = new \ReflectionClass($interface);',
            '$class->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF)' => '$interfaceReflection->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF)',
        ], true);
        $method = substr_replace($method, substr($body, 6), $at, $length);
        $tokens = $this->tokens($method);
        $doLoops = $this->scopedMatches($tokens, $this->tokens('<?php do {'), true);
        if (count($doLoops) !== 1) { throw new ConfigurationException('ErrorListener class lineage loop binding is unsupported'); }
        $open = $doLoops[0] + 1;
        $close = $this->closingScope($tokens, $open, '{', '}');
        $while = $close + 1;
        if (($tokens[$while]['id'] ?? null) !== T_WHILE || ($tokens[$while + 1]['id'] ?? null) !== '(') {
            throw new ConfigurationException('ErrorListener class lineage continuation is unsupported');
        }
        $conditionEnd = $this->closingScope($tokens, $while + 1, '(', ')');
        if (($tokens[$conditionEnd + 1]['id'] ?? null) !== ';') { throw new ConfigurationException('ErrorListener class lineage continuation is unsupported'); }
        $initializer = [];
        foreach ($reflectionRules as $before => $after) {
            if (str_contains(implode('', array_column($this->tokens('<?php ' . $before), 'text')), 'new\ReflectionClass')) {
                $initializer[$before] = $after;
                unset($reflectionRules[$before]);
            }
        }
        $at = $tokens[$doLoops[0]]['offset'];
        $length = $tokens[$conditionEnd + 1]['end'] - $at;
        $lineage = $this->replace('error-listener#class-lineage', '<?php ' . substr($method, $at, $length), $reflectionRules, true);
        $method = substr_replace($method, substr($lineage, 6), $at, $length);
        $prefix = $this->replace('error-listener#class-initializer', substr($method, 0, $at), $initializer, true);
        $method = $prefix . substr($method, $at);
        return substr_replace($source, substr($method, 6), $start, $end - $start);
    }

    private function carbonCreator(string $source, array $rules): string
    {
        [$start, $end] = $this->methodSpan($source, 'createSafe', true);
        $method = '<?php ' . substr($source, $start, $end - $start);
        $initializers = [];
        $fieldRules = [];
        foreach ($rules as $before => $after) {
            if (implode('', array_column($this->tokens('<?php ' . $before), 'text')) === '$$field') {
                $fieldRules[$before] = $after;
            } else { $initializers[$before] = $after; }
        }
        $tokens = $this->tokens($method);
        $parameters = array_column(array_slice($tokens, 0, array_search('{', array_column($tokens, 'id'), true)), 'text');
        foreach (['$year', '$month', '$day', '$hour', '$minute', '$second'] as $parameter) {
            if (!in_array($parameter, $parameters, true)) { throw new ConfigurationException('Carbon safe creator field parameter binding is unsupported'); }
        }
        $method = $this->replace('carbon-creator#field-values', $method, $initializers, true);
        foreach (['foreach ($fields as $field => $range) {', 'foreach (array_reverse($fields) as $field => $range) {'] as $prefix) {
            $tokens = $this->tokens($method);
            $needle = $this->tokens('<?php ' . $prefix);
            $loops = $this->scopedMatches($tokens, $needle, true);
            if ($loops === []) { throw new ConfigurationException('Carbon safe creator field range binding is unsupported'); }
            foreach (array_reverse($loops) as $loop) {
                $open = $loop + count($needle) - 1;
                $close = $this->closingScope($tokens, $open, '{', '}');
                $at = $tokens[$open]['end'];
                $length = $tokens[$close]['offset'] - $at;
                $body = $this->replace('carbon-creator#field-range', '<?php ' . substr($method, $at, $length), $fieldRules, true);
                $method = substr_replace($method, substr($body, 6), $at, $length);
            }
        }
        return substr_replace($source, substr($method, 6), $start, $end - $start);
    }

    private function blueprint(string $source, array $rules): string
    {
        $compact = [];
        $drop = [];
        foreach ($rules as $before => $after) {
            if (str_starts_with(implode('', array_column($this->tokens('<?php ' . $before), 'text')), 'compact(')) { $compact[$before] = $after; }
            else { $drop[$before] = $after; }
        }
        $source = $this->replaceMethod('blueprint#drop-column', $source, 'dropColumn', $drop);
        $tokens = $this->tokens($source);
        $methods = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_FUNCTION && ($tokens[$index + 1]['id'] ?? null) === T_STRING) { $methods[] = $tokens[$index + 1]['text']; }
        }
        foreach ($methods as $name) {
            [$start, $end] = $this->methodSpan($source, $name, true);
            $method = '<?php ' . substr($source, $start, $end - $start);
            $tokens = $this->tokens($method);
            $supported = false;
            foreach ($compact as $before => $after) {
                $supported = $supported || $this->scopedMatches($tokens, $this->tokens('<?php ' . $before), true) !== []
                    || $this->scopedMatches($tokens, $this->tokens('<?php ' . $after), true) !== [];
            }
            if (!$supported) { continue; }
            $parameters = array_column(array_slice($tokens, 0, array_search('{', array_column($tokens, 'id'), true)), 'text');
            if (!in_array('$autoIncrement', $parameters, true) || !in_array('$unsigned', $parameters, true)) {
                if (in_array($name, ['integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger'], true)) {
                    throw new ConfigurationException('Blueprint integer column parameter binding is unsupported: ' . $name);
                }
                continue;
            }
            $source = $this->replaceMethod('blueprint#integer-column', $source, $name, $compact);
        }
        return $source;
    }

    private function replaceMethod(string $path, string $source, string $method, array $rules): string
    {
        [$start, $end] = $this->methodSpan($source, $method, true);
        $body = '<?php ' . substr($source, $start, $end - $start);
        if (str_ends_with($path, '/Carbon/Traits/Date.php') && $method === 'executeStaticCallable') {
            $tokens = $this->tokens($body);
            $prefix = $this->tokens('<?php return static::bindMacroContext(null, function () use (&$macro, &$parameters) {');
            $callbacks = $this->matches($tokens, $prefix);
            if (count($callbacks) !== 1) { throw new ConfigurationException('Carbon static macro context callback binding is unsupported'); }
            $open = $callbacks[0] + count($prefix) - 1;
            $close = $this->closingScope($tokens, $open, '{', '}');
            $at = $tokens[$open]['end'];
            $length = $tokens[$close]['offset'] - $at;
            $callback = $this->replace($path . '#' . $method . '#callback', '<?php ' . substr($body, $at, $length), $rules, true);
            $body = substr_replace($body, substr($callback, 6), $at, $length);
            return substr_replace($source, substr($body, 6), $start, $end - $start);
        }
        $adapted = $this->replace($path . '#' . $method, $body, $rules, true);
        return substr_replace($source, substr($adapted, 6), $start, $end - $start);
    }

    private function replaceSwitch(string $path, string $source, string $method, string $expression, array $rules): string
    {
        [$methodStart, $methodEnd] = $this->methodSpan($source, $method, true);
        $body = '<?php ' . substr($source, $methodStart, $methodEnd - $methodStart);
        $tokens = $this->tokens($body);
        $needle = $this->tokens('<?php switch (' . $expression . ') {');
        $switches = $this->scopedMatches($tokens, $needle, true);
        $depth = 0;
        $depths = [];
        foreach ($tokens as $index => $token) {
            $depths[$index] = $depth;
            if ($token['id'] === '{') { ++$depth; }
            elseif ($token['id'] === '}') { --$depth; }
        }
        $switches = array_values(array_filter($switches, static fn (int $index): bool => $depths[$index] === 1));
        if (count($switches) !== 1) { throw new ConfigurationException('upstream conversion switch binding is unsupported: ' . $path . '#' . $method); }
        $open = $switches[0] + count($needle) - 1;
        $close = $this->closingScope($tokens, $open, '{', '}');
        $start = $tokens[$switches[0]]['offset'];
        $end = $tokens[$close]['end'];
        $switchBody = '<?php ' . substr($body, $start, $end - $start);
        foreach ($rules as $before => $after) {
            $pattern = $this->tokens('<?php ' . $before);
            if (str_ends_with($path, '/Carbon/Traits/Date.php') && $method === 'set'
                && implode('', array_column($pattern, 'text')) === '$result->$name=$value;'
            ) {
                $switchTokens = $this->tokens($switchBody);
                $adaptedPattern = $this->tokens('<?php ' . $after);
                $terminal = null;
                foreach ([$pattern, $adaptedPattern] as $shape) {
                    $at = count($switchTokens) - count($shape) - 1;
                    if ($at < 0 || $this->tokenTexts(array_slice($switchTokens, $at, count($shape))) !== $this->tokenTexts($shape)) { continue; }
                    $depth = 0;
                    $lastLabel = null;
                    for ($index = 0; $index <= $at; ++$index) {
                        $id = $switchTokens[$index]['id'];
                        if ($depth === 1 && in_array($id, [T_CASE, T_DEFAULT], true)) { $lastLabel = $id; }
                        if ($id === '{') { ++$depth; }
                        elseif ($id === '}') { --$depth; }
                    }
                    if ($depth !== 1 || $lastLabel !== T_DEFAULT) { continue; }
                    $terminal = [$switchTokens[$at]['offset'], $switchTokens[$at + count($shape) - 1]['end']];
                    break;
                }
                if ($terminal === null) { throw new ConfigurationException('Carbon setter default terminal assignment is unsupported'); }
                $switchBody = substr_replace($switchBody, $after, $terminal[0], $terminal[1] - $terminal[0]);
                unset($rules[$before]);
                continue;
            }
            // The three closing braces/newline terminal refer to this switch's end.
            if (in_array(implode('', array_column($pattern, 'text')), ['}}}', '"\n";}'], true)) {
                $switchTokens = $this->tokens($switchBody);
                $tail = array_slice($switchTokens, -count($pattern));
                if ($this->tokenTexts($tail) !== $this->tokenTexts($pattern)) {
                    throw new ConfigurationException('upstream conversion switch terminal is unsupported: ' . $path);
                }
                $at = $tail[0]['offset'];
                $switchBody = substr_replace($switchBody, $after, $at, strlen($switchBody) - $at);
                unset($rules[$before]);
            }
        }
        $switchBody = $this->replace($path . '#' . $method . '#switch', $switchBody, $rules, true);
        $body = substr_replace($body, substr($switchBody, 6), $start, $end - $start);
        return substr_replace($source, substr($body, 6), $methodStart, $methodEnd - $methodStart);
    }

    private function carbonLazyDeclaration(string $path, string $source): string
    {
        $class = str_contains($path, 'MessageFormatterMapper') ? 'LazyMessageFormatter' : 'LazyTranslator';
        $tokens = $this->tokens($source);
        $prefix = $this->tokens('<?php if (!class_exists(' . $class . '::class, false)) {');
        $guards = $this->matches($tokens, $prefix);
        if (count($guards) !== 1) { throw new ConfigurationException('Carbon lazy class guard is unsupported: ' . $class); }
        $open = $guards[0] + count($prefix) - 1;
        $close = $this->closingScope($tokens, $open, '{', '}');
        $body = array_slice($tokens, $open + 1, $close - $open - 1);
        $declaration = ($body[0]['id'] ?? null) === T_ABSTRACT ? 1 : 0;
        if (($body[$declaration]['id'] ?? null) !== T_CLASS || ($body[$declaration + 1]['text'] ?? null) !== $class) {
            throw new ConfigurationException('Carbon lazy guarded class declaration is missing');
        }
        for ($classOpen = $declaration + 2; isset($body[$classOpen]) && $body[$classOpen]['id'] !== '{'; ++$classOpen) {}
        if ($this->closingScope($body, $classOpen, '{', '}') !== count($body) - 1) {
            throw new ConfigurationException('Carbon lazy guard contains unsupported execution');
        }
        $source = substr_replace($source, '', $tokens[$close]['offset'], $tokens[$close]['end'] - $tokens[$close]['offset']);
        return substr_replace($source, '', $tokens[$guards[0]]['offset'], $tokens[$open]['end'] - $tokens[$guards[0]]['offset']);
    }

    private function scopedMatches(array $tokens, array $needle, bool $preserveNestedFunctions, bool $directSwitch = false): array
    {
        $matches = $this->matches($tokens, $needle);
        if ($directSwitch) {
            $labels = [];
            foreach ($needle as $index => $token) { if (in_array($token['id'], [T_CASE, T_DEFAULT], true)) { $labels[] = $index; } }
            if ($labels !== []) {
                $depths = [];
                $depth = 0;
                foreach ($tokens as $index => $token) {
                    $depths[$index] = $depth;
                    if ($token['id'] === '{') { ++$depth; }
                    elseif ($token['id'] === '}') { --$depth; }
                }
                $matches = array_values(array_filter($matches, static function (int $index) use ($labels, $depths): bool {
                    foreach ($labels as $offset) { if (($depths[$index + $offset] ?? null) !== 1) { return false; } }
                    return true;
                }));
            }
        }
        if (!$preserveNestedFunctions || $matches === []) { return $matches; }
        $root = 0;
        while (isset($tokens[$root]) && in_array($tokens[$root]['id'], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL], true)) { ++$root; }
        $ranges = [];
        foreach ($tokens as $index => $token) {
            if (!in_array($token['id'], [T_FUNCTION, T_FN], true) || $index === $root && $token['id'] === T_FUNCTION) { continue; }
            if ($token['id'] === T_FUNCTION) {
                for ($open = $index + 1; isset($tokens[$open]) && !in_array($tokens[$open]['id'], ['{', ';'], true); ++$open) {}
                if (($tokens[$open]['id'] ?? null) !== '{') { continue; }
                $ranges[] = [$index, $this->closingScope($tokens, $open, '{', '}')];
            } else {
                for ($arrow = $index + 1; isset($tokens[$arrow]) && $tokens[$arrow]['id'] !== T_DOUBLE_ARROW; ++$arrow) {}
                $depth = 0;
                for ($end = $arrow + 1; isset($tokens[$end]); ++$end) {
                    $id = $tokens[$end]['id'];
                    if (in_array($id, ['(', '[', '{'], true)) { ++$depth; }
                    elseif (in_array($id, [')', ']', '}'], true)) { if ($depth === 0) { break; } --$depth; }
                    elseif ($depth === 0 && in_array($id, [',', ';'], true)) { break; }
                }
                $ranges[] = [$index, $end - 1];
            }
        }
        return array_values(array_filter($matches, static function (int $index) use ($ranges, $needle): bool {
            foreach ($ranges as [$start, $end]) { if ($index <= $end && $index + count($needle) - 1 >= $start) { return false; } }
            return true;
        }));
    }

    private function closingScope(array $tokens, int $start, string $open, string $close): int
    {
        if (($tokens[$start]['id'] ?? null) !== $open) { throw new ConfigurationException('upstream scoped conversion opening token is missing'); }
        $depth = 1;
        for ($index = $start + 1; isset($tokens[$index]); ++$index) {
            if ($tokens[$index]['id'] === $open) { ++$depth; }
            elseif ($tokens[$index]['id'] === $close && --$depth === 0) { return $index; }
        }
        throw new ConfigurationException('upstream scoped conversion body is unbalanced');
    }

    private function carbonLocalization(string $source, array $rules): string
    {
        [$start, $end] = $this->methodSpan($source, 'translateTimeString', true);
        $method = '<?php ' . substr($source, $start, $end - $start);
        $other = [];
        foreach ($rules as $before => $after) {
            $text = implode('', array_column($this->tokens('<?php ' . $before), 'text'));
            if (!in_array($text, ['$$translationKey=array_merge(', ');}'], true)) { $other[$before] = $after; }
        }
        $tokens = $this->tokens($method);
        $prefix = $this->tokens("<?php foreach (['from', 'to'] as \$key) {");
        $loops = $this->scopedMatches($tokens, $prefix, true);
        if (count($loops) !== 1) { throw new ConfigurationException('Carbon translation source and destination loop is unsupported'); }
        $open = $loops[0] + count($prefix) - 1;
        $close = $this->closingScope($tokens, $open, '{', '}');
        $loop = array_slice($tokens, $open + 1, $close - $open - 1);
        $bindings = $this->scopedMatches($loop, $this->tokens("<?php \$translationKey = \$key.'Translations';"), true);
        if (count($bindings) !== 1 || count($this->scopedMatches($loop, $this->tokens('<?php $translationKey'), true)) !== 2) {
            throw new ConfigurationException('Carbon translation result variable binding is unsupported');
        }
        $original = $this->scopedMatches($loop, $this->tokens('<?php $$translationKey = array_merge('), true);
        if (count($original) !== 1 || $this->scopedMatches($tokens, $this->tokens('<?php $translatedWords'), true) !== []) {
            throw new ConfigurationException('Carbon translation merge result scope is unsupported');
        }
        foreach ($this->scopedMatches($loop, $this->tokens('<?php $key'), true) as $index) {
            if (in_array($loop[$index + 1]['text'] ?? null, ['=', '+=', '-=', '.=', '++', '--'], true)
                || in_array($loop[$index - 1]['text'] ?? null, ['&', '++', '--'], true)) {
                throw new ConfigurationException('Carbon translation language key is mutated');
            }
        }
        $call = $open + 1 + $original[0];
        $depth = 0;
        for ($index = $open + 1; $index < $call; ++$index) {
            if ($tokens[$index]['id'] === '{') { ++$depth; }
            elseif ($tokens[$index]['id'] === '}') { --$depth; }
        }
        if ($depth !== 0 || $original[0] <= $bindings[0]) {
            throw new ConfigurationException('Carbon translation merge is outside its direct binding scope');
        }
        $callEnd = $this->closingScope($tokens, $call + 4, '(', ')');
        if (($tokens[$callEnd + 1]['text'] ?? null) !== ';' || $callEnd >= $close) {
            throw new ConfigurationException('Carbon translation merge statement is unsupported');
        }
        $at = $tokens[$callEnd + 1]['end'];
        $method = substr_replace($method, "\n            if (\$key === 'from') {\n                \$fromTranslations = \$translatedWords;\n            } else {\n                \$toTranslations = \$translatedWords;\n            }", $at, 0);
        $method = substr_replace($method, '$translatedWords', $tokens[$call]['offset'], $tokens[$call + 1]['end'] - $tokens[$call]['offset']);
        $method = $this->replace('carbon-localization#translateTimeString', $method, $other, true);
        if ($this->scopedMatches($this->tokens($method), $this->tokens('<?php $$translationKey ='), true) !== []) {
            throw new ConfigurationException('Carbon translation dynamic result remains');
        }
        return substr_replace($source, substr($method, 6), $start, $end - $start);
    }

    private function methodSpan(string $source, string $method, bool $allowCallbacks = false): array
    {
        $tokens = $this->tokens($source);
        $spans = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_FUNCTION || ($tokens[$index + 1]['text'] ?? '') !== $method) { continue; }
            for ($body = $index + 2; isset($tokens[$body]) && $tokens[$body]['text'] !== '{'; ++$body) {}
            $depth = 1;
            $end = $body + 1;
            while (isset($tokens[$end]) && $depth > 0) {
                if (!$allowCallbacks && in_array($tokens[$end]['id'], [T_FUNCTION, T_FN], true)) { throw new ConfigurationException('upstream local conversion escapes into a callback: ' . $method); }
                if ($tokens[$end]['text'] === '{') { ++$depth; }
                elseif ($tokens[$end]['text'] === '}') { --$depth; }
                ++$end;
            }
            if ($depth !== 0) { throw new ConfigurationException('upstream method conversion scope is unbalanced'); }
            $start = $index;
            while ($start > 0 && in_array($tokens[$start - 1]['id'], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL], true)) { --$start; }
            $spans[] = [$tokens[$start]['offset'], $tokens[$end - 1]['end']];
        }
        if (count($spans) !== 1) { throw new ConfigurationException('upstream local conversion method declaration is unsupported: ' . $method); }
        return $spans[0];
    }

    private function tokenTexts(array $tokens): array
    {
        return array_map(static fn (array $token): array => [$token['id'], $token['text']], $tokens);
    }

    private function unadapted(array $original, int $beforeLength, array $adapted, int $afterLength): array
    {
        return array_values(array_filter($original, static function (int $index) use ($beforeLength, $adapted, $afterLength): bool {
            foreach ($adapted as $start) {
                if ($index >= $start && $index + $beforeLength <= $start + $afterLength) { return false; }
            }
            return true;
        }));
    }

    private function deletedSourceIsAbsent(array $source, array $needle): bool
    {
        // A deleted loader/call is already adapted only when its callee is absent.
        foreach ($needle as $index => $token) {
            if (in_array($token['id'], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
                foreach ($source as $actual) { if ($actual['id'] === $token['id']) { return false; } }
                return true;
            }
            if (in_array($token['id'], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ($needle[$index + 1]['text'] ?? '') === '(') {
                return $this->matches($source, array_slice($needle, $index, 2)) === [];
            }
        }
        return false;
    }

    private function rewriteTokens(string $fragment, array $before, int $offset, array $after, string $replacement): string
    {
        if ($after === []) { return ''; }
        $n = count($before);
        $m = count($after);
        $lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $lengths[$i][$j] = $before[$i]['id'] === $after[$j]['id'] && $before[$i]['text'] === $after[$j]['text']
                    ? 1 + $lengths[$i + 1][$j + 1]
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }
        $pairs = [[-1, -1]];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($before[$i]['id'] === $after[$j]['id'] && $before[$i]['text'] === $after[$j]['text']) {
                $pairs[] = [$i, $j];
                ++$i;
                ++$j;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                ++$i;
            } else {
                ++$j;
            }
        }
        $pairs[] = [$n, $m];
        $edits = [];
        for ($pair = 1; $pair < count($pairs); ++$pair) {
            [$leftBefore, $leftAfter] = $pairs[$pair - 1];
            [$rightBefore, $rightAfter] = $pairs[$pair];
            for ($old = $leftBefore + 1; $old < $rightBefore; ++$old) {
                $edits[] = [$before[$old]['offset'] - $offset, $before[$old]['end'] - $before[$old]['offset'], ''];
            }
            if ($rightAfter > $leftAfter + 1) {
                $start = $leftAfter < 0 ? $after[0]['offset'] : $after[$leftAfter]['end'];
                $end = $rightAfter === $m ? $after[$m - 1]['end'] : $after[$rightAfter]['offset'];
                $at = $leftBefore + 1 < $n ? $before[$leftBefore + 1]['offset'] - $offset : strlen($fragment);
                $edits[] = [$at, 0, substr($replacement, $start, $end - $start)];
            }
        }
        usort($edits, static fn (array $a, array $b): int => ($b[0] <=> $a[0]) ?: ($b[1] <=> $a[1]));
        foreach ($edits as [$at, $length, $text]) {
            $fragment = substr_replace($fragment, $text, $at, $length);
        }
        return $fragment;
    }

    private function requestFormats(string $source): string
    {
        $tokens = $this->tokens($source);
        $method = $this->tokens('<?php public static function resetFormatsForAot(): void { self::$formats = null; }');
        if ($this->matches($tokens, $method) !== []) { return $source; }
        $anchors = $this->matches($tokens, $this->tokens('<?php public function setFormat('));
        if (count($anchors) !== 1 || $this->matches($tokens, $this->tokens('<?php static function resetFormatsForAot(')) !== []) {
            throw new ConfigurationException('Symfony Request format reset declaration is unsupported');
        }
        return substr_replace($source, "public static function resetFormatsForAot(): void { self::\$formats = null; }\n\n    ", $tokens[$anchors[0]]['offset'], 0);
    }

    private function contextualReplacements(string $path, array $rules): array
    {
        if ($path === 'vendor/symfony/console/Helper/QuestionHelper.php') {
            foreach (array_keys($rules) as $before) {
                $text = implode('', array_column($this->tokens('<?php ' . $before), 'text'));
                if (in_array($text, ['if(false===$ret){', '$ret=$this->readInput($inputStream,$question);'], true)) { unset($rules[$before]); }
            }
            $rules = [
                'if (false === $ret) { $isBlocked = stream_get_meta_data($inputStream)' => 'if (!$hasAnswer) { $isBlocked = stream_get_meta_data($inputStream)',
                '$ret = $this->readInput($inputStream, $question);' => '$inputAnswer = $this->readInput($inputStream, $question);',
                "if (false === \$ret) { throw new MissingInputException('Aborted.');" => "if (false === \$inputAnswer) { throw new MissingInputException('Aborted.');",
            ] + $rules;
        }
        if ($path === 'vendor/symfony/http-kernel/Controller/ControllerResolver.php') {
            $combined = [];
            $assignment = null;
            foreach ($rules as $before => $after) {
                if (str_starts_with(trim($before), '$controller =')) { $assignment = [$before, $after]; continue; }
                if ($assignment !== null && str_starts_with(trim($before), 'if (!')) {
                    if (str_contains($assignment[0], '($class)')) {
                        $combined[$assignment[0]] = $assignment[1];
                        $combined['throw $e; } ' . $before] = 'throw $e; } ' . $after;
                    } else {
                        $combined[$assignment[0] . $before] = $assignment[1] . $after;
                    }
                    $assignment = null;
                } else { $combined[$before] = $after; }
            }
            if ($assignment !== null) { throw new ConfigurationException('ControllerResolver local binding contract is incomplete'); }
            return $combined;
        }
        return $rules;
    }

    private function websocketHeaders(string $source): string
    {
        $tokens = $this->tokens($source);
        $loops = [];
        $prefix = $this->tokens('<?php foreach ($connection->headers as');
        foreach ($this->matches($tokens, $prefix) as $start) {
            $variable = $tokens[$start + count($prefix)] ?? null;
            if (is_array($variable) && $variable['id'] === T_VARIABLE
                && preg_match('/^\$(?:header|responseHeader[0-9]*)$/D', $variable['text']) === 1
                && ($tokens[$start + count($prefix) + 1]['text'] ?? null) === ')'
                && ($tokens[$start + count($prefix) + 2]['text'] ?? null) === '{'
            ) { $loops[] = [$start, $start + count($prefix) + 3, $variable['text']]; }
        }
        if (count($loops) !== 1) { throw new ConfigurationException('Websocket response header loop source structure drift'); }
        [$start, $body, $name] = $loops[0];
        $depth = 1;
        $end = $body;
        while ($end < count($tokens) && $depth > 0) {
            if ($tokens[$end]['text'] === '{') { ++$depth; }
            if ($tokens[$end]['text'] === '}') { --$depth; }
            if (in_array($tokens[$end]['id'], [T_FUNCTION, T_FN], true)) {
                throw new ConfigurationException('Websocket response header escapes into an unsupported callback');
            }
            ++$end;
        }
        if ($depth !== 0) { throw new ConfigurationException('Websocket response header loop is unbalanced'); }
        for ($function = $start; $function >= 0 && $tokens[$function]['id'] !== T_FUNCTION; --$function) {}
        if ($function < 0) { throw new ConfigurationException('Websocket response header loop is outside a method'); }
        for ($methodBody = $function; $methodBody < $start && $tokens[$methodBody]['text'] !== '{'; ++$methodBody) {}
        $methodDepth = 1;
        $methodEnd = $methodBody + 1;
        while ($methodEnd < count($tokens) && $methodDepth > 0) {
            if ($tokens[$methodEnd]['text'] === '{') { ++$methodDepth; }
            if ($tokens[$methodEnd]['text'] === '}') { --$methodDepth; }
            ++$methodEnd;
        }
        for ($index = $end; $index < $methodEnd; ++$index) {
            if ($tokens[$index]['id'] === T_VARIABLE && $tokens[$index]['text'] === $name) {
                throw new ConfigurationException('Websocket response header is used after its loop');
            }
        }
        if ($name !== '$header') { return $source; }
        $variables = [];
        foreach (array_slice($tokens, $function, $methodEnd - $function) as $token) {
            if ($token['id'] === T_VARIABLE) { $variables[$token['text']] = true; }
        }
        $replacement = '$responseHeader';
        for ($suffix = 1; isset($variables[$replacement]); ++$suffix) { $replacement = '$responseHeader' . $suffix; }
        for ($index = $end - 1; $index >= $start; --$index) {
            $token = $tokens[$index];
            if ($token['id'] === T_VARIABLE && $token['text'] === $name) {
                $source = substr_replace($source, $replacement, $token['offset'], $token['end'] - $token['offset']);
            }
        }
        return $source;
    }

    public function compactCalls(string $source): string
    {
        $tokens = $this->tokens($source);
        $edits = [];
        foreach ($tokens as $index => $token) {
            if (!in_array($token['id'], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || strtolower(ltrim($token['text'], '\\')) !== 'compact'
                || in_array($tokens[$index - 1]['id'] ?? null, [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
                || ($tokens[$index + 1]['text'] ?? null) !== '('
            ) { continue; }
            $cursor = $index + 2;
            $keys = [];
            while (true) {
                $key = $tokens[$cursor] ?? null;
                if (!is_array($key) || $key['id'] !== T_CONSTANT_ENCAPSED_STRING
                    || preg_match('/^([\'"])([A-Za-z_][A-Za-z0-9_]*)\1$/D', $key['text'], $match) !== 1
                ) {
                    throw new ConfigurationException('Illuminate compact call has unsupported arguments');
                }
                $keys[] = $match[2];
                ++$cursor;
                if (($tokens[$cursor]['text'] ?? null) === ')') { break; }
                if (($tokens[$cursor]['text'] ?? null) !== ',') {
                    throw new ConfigurationException('Illuminate compact call argument structure drift');
                }
                ++$cursor;
                if (($tokens[$cursor]['text'] ?? null) === ')') { break; }
            }
            $defined = $this->definedVariables($tokens, $index);
            foreach ($keys as $key) {
                if (!isset($defined['$' . $key])) {
                    throw new ConfigurationException('Illuminate compact variable is not proven defined: $' . $key);
                }
            }
            $pairs = array_map(static fn (string $key): string => "'{$key}' => \${$key}", $keys);
            $edits[] = [$token['offset'], $tokens[$cursor]['end'], '[' . implode(', ', $pairs) . ']'];
        }
        foreach (array_reverse($edits) as [$start, $end, $replacement]) {
            $source = substr_replace($source, $replacement, $start, $end - $start);
        }
        return $source;
    }

    /** Prove local bindings on the current braced path; uncertain assignments never authorize a rewrite. */
    private function definedVariables(array $tokens, int $call): array
    {
        $scope = null;
        for ($index = 0; $index < $call; ++$index) {
            if ($tokens[$index]['id'] !== T_FUNCTION && $tokens[$index]['id'] !== T_FN) { continue; }
            $open = $index + 1;
            while ($open < $call && $tokens[$open]['text'] !== '(') { ++$open; }
            $depth = 1;
            $close = $open + 1;
            while ($close < count($tokens) && $depth > 0) {
                if ($tokens[$close]['text'] === '(') { ++$depth; }
                if ($tokens[$close]['text'] === ')') { --$depth; }
                ++$close;
            }
            $body = $close;
            while ($body < $call && !in_array($tokens[$body]['text'], ['{', ';', '=>'], true)) { ++$body; }
            if ($tokens[$index]['id'] === T_FN && ($tokens[$body]['text'] ?? null) === '=>') {
                $nesting = [];
                for ($expression = $body + 1; $expression <= $call; ++$expression) {
                    $text = $tokens[$expression]['text'];
                    if ($nesting === [] && in_array($text, [',', ';', ')', ']', '}'], true)) { break; }
                    if ($expression === $call) { return []; }
                    if (in_array($text, ['(', '[', '{'], true)) { $nesting[] = $text; }
                    elseif (in_array($text, [')', ']', '}'], true)) { array_pop($nesting); }
                }
                continue;
            }
            if (($tokens[$body]['text'] ?? null) !== '{') { continue; }
            $depth = 1;
            $end = $body + 1;
            while ($end < count($tokens) && $depth > 0) {
                if ($tokens[$end]['text'] === '{') { ++$depth; }
                if ($tokens[$end]['text'] === '}') { --$depth; }
                ++$end;
            }
            if ($call > $body && $call < $end) { $scope = [$open, $close, $body]; }
        }
        if ($scope === null) { return []; }
        [$open, $close, $body] = $scope;
        $defined = [];
        foreach (array_slice($tokens, $open + 1, $body - $open - 1) as $token) {
            if ($token['id'] === T_VARIABLE) { $defined[$token['text']] = true; }
        }
        $scopes = [$defined];
        $scopeKinds = [['kind' => null, 'alternatives' => []]];
        $closedBranches = [];
        $foreachBindings = [];
        $uncertain = [];
        for ($index = $body + 1; $index < $call; ++$index) {
            $token = $tokens[$index];
            if (in_array($token['id'], [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) { return []; }
            if ($token['id'] === T_UNSET) {
                $cursor = $index + 1;
                while ($cursor < $call && $tokens[$cursor]['text'] !== ';') {
                    if ($tokens[$cursor]['id'] === T_VARIABLE) { $uncertain[$tokens[$cursor]['text']] = true; }
                    ++$cursor;
                }
            }
            if ($token['id'] === T_FOREACH) {
                $cursor = $index + 1;
                $parentheses = 0;
                $targets = [];
                $afterAs = false;
                while ($cursor < $call) {
                    if ($tokens[$cursor]['text'] === '(') { ++$parentheses; }
                    if ($tokens[$cursor]['text'] === ')' && --$parentheses === 0) { break; }
                    if ($tokens[$cursor]['id'] === T_AS) { $afterAs = true; }
                    if ($afterAs && $tokens[$cursor]['id'] === T_VARIABLE) { $targets[$tokens[$cursor]['text']] = true; }
                    ++$cursor;
                }
                if (($tokens[$cursor + 1]['text'] ?? null) === '{') { $foreachBindings[$cursor + 1] = $targets; }
            }
            if ($token['text'] === '{') {
                $owner = $index - 1;
                if (($tokens[$owner]['text'] ?? null) === ')') {
                    $parentheses = 1;
                    --$owner;
                    while ($owner >= $body && $parentheses > 0) {
                        if ($tokens[$owner]['text'] === ')') { ++$parentheses; }
                        if ($tokens[$owner]['text'] === '(') { --$parentheses; }
                        --$owner;
                    }
                }
                $kind = $tokens[$owner]['id'] ?? null;
                $alternatives = in_array($kind, [T_ELSE, T_ELSEIF], true) ? ($closedBranches[$owner - 1] ?? []) : [];
                $scopeKinds[] = ['kind' => $kind, 'alternatives' => $alternatives];
                $scopes[] = array_replace(end($scopes), $foreachBindings[$index] ?? []);
            } elseif ($token['text'] === '}') {
                if (count($scopes) > 1) {
                    $branch = array_pop($scopes);
                    $scopeKind = array_pop($scopeKinds);
                    if (in_array($scopeKind['kind'], [T_IF, T_ELSEIF], true)) {
                        $closedBranches[$index] = array_merge($scopeKind['alternatives'], [$branch]);
                    } elseif ($scopeKind['kind'] === T_ELSE && $scopeKind['alternatives'] !== []) {
                        $guaranteed = $branch;
                        foreach ($scopeKind['alternatives'] as $alternative) { $guaranteed = array_intersect_key($guaranteed, $alternative); }
                        $scopes[count($scopes) - 1] = array_replace(end($scopes), $guaranteed);
                    }
                }
            } elseif (in_array($tokens[$index - 1]['text'] ?? null, [';', '{', '}'], true)) {
                if ($token['id'] === T_VARIABLE && ($tokens[$index + 1]['text'] ?? null) === '=') {
                    $scopes[count($scopes) - 1][$token['text']] = true;
                } elseif (($token['id'] === T_VARIABLE || $token['id'] === T_STRING)
                    && ($tokens[$index + 1]['text'] ?? null) === '('
                ) {
                    $parentheses = 1;
                    $cursor = $index + 2;
                    while ($cursor < $call && $parentheses > 0) {
                        if ($tokens[$cursor]['text'] === '(') { ++$parentheses; }
                        if ($tokens[$cursor]['text'] === ')') { --$parentheses; }
                        if ($parentheses === 1 && $tokens[$cursor]['id'] === T_VARIABLE
                            && ($tokens[$cursor + 1]['text'] ?? null) === '='
                            && in_array($tokens[$cursor - 1]['text'] ?? null, ['(', ','], true)
                        ) { $scopes[count($scopes) - 1][$tokens[$cursor]['text']] = true; }
                        ++$cursor;
                    }
                } elseif ($token['text'] === '[') {
                    $cursor = $index + 1;
                    $targets = [];
                    while ($cursor < $call && $tokens[$cursor]['text'] !== ']') {
                        if ($tokens[$cursor]['id'] === T_VARIABLE) { $targets[$tokens[$cursor]['text']] = true; }
                        elseif (!in_array($tokens[$cursor]['text'], [',', '&'], true)) { $targets = []; break; }
                        ++$cursor;
                    }
                    if (($tokens[$cursor + 1]['text'] ?? null) === '=') { $scopes[count($scopes) - 1] = array_replace(end($scopes), $targets); }
                }
            }
        }
        return array_diff_key(end($scopes), $uncertain);
    }

    public function prepareIntl(string $path, string $source): string
    {
        $tokens = $this->tokens($source);
        $edits = [];
        $depth = 0;
        for ($index = 0; $index < count($tokens); ++$index) {
            $token = $tokens[$index];
            if ($depth === 0 && $token['id'] === T_IF) {
                $open = $index + 1;
                if (($tokens[$open]['text'] ?? null) !== '(') { throw new ConfigurationException('Intl bootstrap condition structure drift'); }
                $cursor = $open + 1;
                $parentheses = 1;
                while ($cursor < count($tokens) && $parentheses > 0) {
                    if ($tokens[$cursor]['text'] === '(') { ++$parentheses; }
                    if ($tokens[$cursor]['text'] === ')') { --$parentheses; }
                    ++$cursor;
                }
                $condition = array_slice($tokens, $open + 1, $cursor - $open - 2);
                if (($tokens[$cursor]['text'] ?? null) !== '{') { throw new ConfigurationException('Intl bootstrap guarded body structure drift'); }
                $bodyStart = ++$cursor;
                $braces = 1;
                while ($cursor < count($tokens) && $braces > 0) {
                    if ($tokens[$cursor]['text'] === '{') { ++$braces; }
                    if ($tokens[$cursor]['text'] === '}') { --$braces; }
                    ++$cursor;
                }
                $body = array_slice($tokens, $bodyStart, $cursor - $bodyStart - 1);
                $conditionText = str_replace('\\defined', 'defined', implode('', array_column($condition, 'text')));
                $replacement = null;
                if (preg_match('/^!defined\(\'([A-Z][A-Z0-9_]*)\'\)$/D', $conditionText, $match) === 1) {
                    $name = $match[1];
                    $bodyText = str_replace('\\define', 'define', implode('', array_column($body, 'text')));
                    if (preg_match('/^define\(\'' . preg_quote($name, '/') . '\',([0-9]+)\);$/D', $bodyText, $value) !== 1) {
                        throw new ConfigurationException('Intl bootstrap constant declaration structure drift');
                    }
                    $replacement = 'const ' . $name . ' = ' . $value[1] . ';';
                } elseif ($path === 'vendor/symfony/polyfill-intl-grapheme/bootstrap80.php'
                    && $conditionText === "extension_loaded('intl')"
                ) {
                    if (implode('', array_column($body, 'text')) !== 'return;') { throw new ConfigurationException('Intl extension guard body structure drift'); }
                    $replacement = '';
                } elseif ($path === 'vendor/symfony/polyfill-intl-grapheme/bootstrap80.php'
                    && in_array($conditionText, ['\\PHP_VERSION_ID>=80500', 'PHP_VERSION_ID>=80500'], true)
                ) {
                    if (implode('', array_column($body, 'text')) !== "returnrequire__DIR__.'/bootstrap85.php';") { throw new ConfigurationException('Intl PHP85 guard body structure drift'); }
                    $replacement = '';
                }
                if ($replacement !== null) {
                    $edits[] = [$token['offset'], $tokens[$cursor - 1]['end'], $replacement];
                    $index = $cursor - 1;
                    continue;
                }
            }
            if ($token['text'] === '{') { ++$depth; }
            if ($token['text'] === '}') { --$depth; }
        }
        foreach (array_reverse($edits) as [$start, $end, $replacement]) {
            $source = substr_replace($source, $replacement, $start, $end - $start);
        }
        return $source;
    }

    private function tokens(string $source): array
    {
        $result = [];
        $offset = 0;
        foreach (token_get_all($source) as $token) {
            $id = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;
            $end = $offset + strlen($text);
            if (!in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $result[] = ['id' => $id, 'text' => $text, 'offset' => $offset, 'end' => $end];
            }
            $offset = $end;
        }
        return $result;
    }

    private function matches(array $source, array $needle): array
    {
        if ($needle === []) { return []; }
        $result = [];
        for ($index = 0; $index <= count($source) - count($needle); ++$index) {
            foreach ($needle as $relative => $token) {
                if ($source[$index + $relative]['id'] !== $token['id'] || $source[$index + $relative]['text'] !== $token['text']) { continue 2; }
            }
            $result[] = $index;
            $index += count($needle) - 1;
        }
        return $result;
    }
}
