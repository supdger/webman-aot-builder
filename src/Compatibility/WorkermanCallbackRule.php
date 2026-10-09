<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class WorkermanCallbackRule implements CompatibilityRule
{
    public function __construct(private readonly string $kind) {}

    public function id(): string { return 'workerman-' . $this->kind . '-error-handler-variadic'; }
    public function dependency(): string { return 'workerman/workerman'; }
    public function sourcePath(): string
    {
        return 'vendor/workerman/workerman/src/Connection/'
            . ($this->kind === 'tcp' ? 'TcpConnection.php' : 'AsyncTcpConnection.php');
    }
    public function searchText(): string { return 'set_error_handler('; }
    public function expectedHits(): int { return 1; }

    public function transform(string $source, string $version): string
    {
        if ($this->kind === 'async-tcp') {
            foreach ([
                ['fn() => false', 'fn(...$__err) => false'],
                ['static fn(): bool => false', 'static fn(...$__err): bool => false'],
            ] as [$before, $after]) {
                try {
                    return $this->rule('set_error_handler(' . $before . ');', 'set_error_handler(' . $after . ');', ['class AsyncTcpConnection', 'STATUS_CONNECTING'])->transform($source, $version);
                } catch (ConfigurationException) {
                    // The other confirmed signature is checked next.
                }
            }
            throw new ConfigurationException('Workerman async error handler source structure drift: ' . $this->sourcePath());
        }
        if ($this->hasReasonCapture($source)) {
            $before = <<<'PHP'
$reason = '';
set_error_handler(static function (int $code, string $msg, ...$__err) use (&$reason): bool {
    $reason = $msg;
    return true;
});
try {
    $ret = stream_socket_enable_crypto($socket, true, $type);
} finally {
    restore_error_handler();
}
PHP;
            $after = <<<'PHP'
set_error_handler(static function (int $code, string $msg, ...$__err) use (&$reason): bool {
    $reason = $msg;
    return true;
});
try {
    $ret = stream_socket_enable_crypto($socket, true, $type);
} finally {
    restore_error_handler();
}
if ($reason === null) {
    $reason = '';
}
PHP;
            return $this->rule($before, $after, ['class TcpConnection', 'function doSslHandshake(', '$this->emitSslHandshakeFailure($reason);'])->transform($source, $version);
        }
        return $this->rule(
            'set_error_handler(static function (int $code, string $msg): bool {',
            'set_error_handler(static function (int $code, string $msg, ...$__err): bool {',
            ['class TcpConnection', 'stream_socket_enable_crypto($socket, true, $type)']
        )->transform($source, $version);
    }

    private function hasReasonCapture(string $source): bool
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            $id = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;
            if (!in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = ['id' => $id, 'text' => $text];
            }
        }
        $methods = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_FUNCTION && ($tokens[$index + 1]['text'] ?? null) === 'doSslHandshake') { $methods[] = $index; }
        }
        if (count($methods) !== 1) { throw new ConfigurationException('Workerman SSL handshake method structure drift'); }
        $start = $methods[0];
        $body = $start;
        while ($body < count($tokens) && $tokens[$body]['text'] !== '{') { ++$body; }
        $depth = 1;
        $end = $body + 1;
        while ($end < count($tokens) && $depth > 0) {
            if ($tokens[$end]['text'] === '{') { ++$depth; }
            if ($tokens[$end]['text'] === '}') { --$depth; }
            ++$end;
        }
        for ($index = $body + 1; $index < $end; ++$index) {
            if ($tokens[$index]['id'] !== T_USE
                || array_column(array_slice($tokens, $index, 5), 'text') !== ['use', '(', '&', '$reason', ')']
            ) { continue; }
            $handler = $index;
            while ($handler > $body && $tokens[$handler]['text'] !== 'set_error_handler') { --$handler; }
            $prefix = array_slice($tokens, $start, $handler - $start);
            $reasonUses = [];
            foreach ($prefix as $offset => $token) {
                if ($token['id'] === T_VARIABLE && $token['text'] === '$reason') { $reasonUses[] = $offset; }
                if ($token['text'] === '$' || in_array($token['id'], [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)
                    || ($token['id'] === T_STRING && strtolower($token['text']) === 'extract')
                ) { throw new ConfigurationException('Workerman SSL reason has unsupported prior local state'); }
            }
            if ($reasonUses !== [] && (count($reasonUses) !== 1
                || array_column(array_slice($prefix, $reasonUses[0], 4), 'text') !== ['$reason', '=', "''", ';'])
            ) { throw new ConfigurationException('Workerman SSL reason has unsupported prior local state'); }
            return true;
        }
        return false;
    }

    private function rule(string $before, string $after, array $markers): BoundedTextRule
    {
        return new BoundedTextRule($this->id(), $this->dependency(), $this->sourcePath(), new MinimumVersion('5.2.1'), $markers, $before, $after, 1, [$after], [$this->kind === 'tcp' ? 'doSslHandshake' : 'connect']);
    }
}
