<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class WorkermanGeneratorRule
{
    public function transform(string $path, string $source): string
    {
        if ($path === 'vendor/workerman/workerman/src/Timer.php') {
            $tokens = $this->tokens($source);
            foreach ($tokens as &$token) {
                if ($token['id'] === T_VARIABLE && preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $token['text']) === 1) {
                    $token['text'] = '$parameter';
                }
            }
            unset($token);
            $allowed = false;
            foreach (['public static function signalHandle(): void', 'public static function signalHandle(...$parameter): void', 'public static function signalHandle(mixed ...$parameter): void'] as $signature) {
                $allowed = $allowed || $this->find($tokens, $this->tokens('<?php ' . $signature)) !== [];
            }
            if (!$allowed) {
                throw new ConfigurationException('Workerman timer signalHandle signature structure drift');
            }
        }
        foreach (WebmanWorkermanRules::knownRules() as $rule) {
            if ($rule->sourcePath() === $path
                && !in_array($rule->id(), ['workerman-worker-pid-runtime-path', 'workerman-worker-target-loadavg-call'], true)
            ) {
                $source = $rule->transform($source, 'source-validated');
            }
        }
        if ($path === 'vendor/workerman/workerman/src/Worker.php') {
            $source = $this->resetStandardStreams($source);
            $before = <<<'PHP'
static::safeEcho("\nPress Ctrl+C to quit.\n\n"); } case 'connections':
PHP;
            $after = <<<'PHP'
static::safeEcho("\nPress Ctrl+C to quit.\n\n"); } exit(0); case 'connections':
PHP;
            $source = (new BoundedTextRule('workerman-worker-status-terminal', 'workerman/workerman', $path, new MinimumVersion('5.2.1'), ['class Worker'], $before, $after, 1, [$after], ['parseCommand']))->transform($source, 'source-validated');
        }
        return $source;
    }

    private function resetStandardStreams(string $source): string
    {
        $old = <<<'PHP'
if (is_resource(STDOUT)) { fclose(STDOUT); }
if (is_resource(STDERR)) { fclose(STDERR); }
if (is_resource(static::$outputStream)) { fclose(static::$outputStream); }
PHP;
        $new = <<<'PHP'
if (defined('STDOUT') && is_resource(STDOUT) && get_resource_type(STDOUT) === 'stream') {
    try { @fclose(STDOUT); } catch (Throwable) {}
}
if (defined('STDERR') && is_resource(STDERR) && get_resource_type(STDERR) === 'stream') {
    try { @fclose(STDERR); } catch (Throwable) {}
}
if (is_resource(static::$outputStream) && get_resource_type(static::$outputStream) === 'stream') {
    try { @fclose(static::$outputStream); } catch (Throwable) {}
}
PHP;
        $tokens = $this->tokens($source);
        $start = $this->find($tokens, $this->tokens('<?php public static function resetStd(): void {'));
        if (count($start) !== 1) {
            throw new ConfigurationException('Workerman resetStd method source structure drift');
        }
        $bodyStart = $start[0] + count($this->tokens('<?php public static function resetStd(): void {'));
        $depth = 1;
        $end = $bodyStart;
        for (; $end < count($tokens); ++$end) {
            if ($tokens[$end]['text'] === '{') { ++$depth; }
            if ($tokens[$end]['text'] === '}' && --$depth === 0) { break; }
        }
        $body = array_slice($tokens, $bodyStart, $end - $bodyStart);
        $matches = [];
        foreach ([$old, $new] as $shape) {
            $needle = $this->tokens('<?php ' . $shape);
            foreach ($this->find($body, $needle) as $offset) {
                $matches[] = [$body[$offset]['offset'], $body[$offset + count($needle) - 1]['end']];
            }
        }
        $closures = 0;
        foreach ($body as $token) { if ($token['id'] === T_STRING && strtolower($token['text']) === 'fclose') { ++$closures; } }
        if (count($matches) === 1 && $closures === 3) {
            [$offset, $endOffset] = $matches[0];
            return substr_replace($source, '', $offset, $endOffset - $offset);
        }
        if ($matches === [] && $closures === 0
            && $this->find($body, $this->tokens("<?php fopen(static::\$stdoutFile, 'a')")) !== []
        ) {
            return $source;
        }
        throw new ConfigurationException('Workerman resetStd standard-stream close structure drift');
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

    private function find(array $source, array $needle): array
    {
        $matches = [];
        for ($index = 0; $index <= count($source) - count($needle); ++$index) {
            foreach ($needle as $relative => $token) {
                if ($source[$index + $relative]['id'] !== $token['id'] || $source[$index + $relative]['text'] !== $token['text']) { continue 2; }
            }
            $matches[] = $index;
        }
        return $matches;
    }
}
