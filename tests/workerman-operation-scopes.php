<?php

declare(strict_types=1);

use WebmanAotBuilder\Compatibility\WorkermanGeneratorRule;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
function ensure(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
$project = $argv[1] ?? '';
$started = microtime(true);
$rule = new WorkermanGeneratorRule();
foreach (['Worker.php', 'Timer.php', 'Connection/TcpConnection.php', 'Connection/AsyncTcpConnection.php'] as $relative) {
    $path = 'vendor/workerman/workerman/src/' . $relative;
    $source = file_get_contents($project . '/' . $path);
    ensure(is_string($source), 'Pass a complete project fixture');
    $adapted = $rule->transform($path, $source);
    token_get_all($adapted, TOKEN_PARSE);
    ensure($rule->transform($path, $adapted) === $adapted, 'adapted operation is not idempotent: ' . $relative);
    echo "PASS actual {$relative} operation scopes and idempotence\n";
    if ($relative !== 'Worker.php') { continue; }
    $extra = 'public static function safeCompatibleExtra(): void { set_error_handler(static fn (): bool => true); restore_error_handler(); }';
    $variant = substr_replace($adapted, $extra, strrpos($adapted, '}'), 0);
    token_get_all($variant, TOKEN_PARSE);
    $completed = $rule->transform($path, $variant);
    ensure(str_contains($completed, 'safeCompatibleExtra(): void { set_error_handler(static fn (...$__err): bool => true); restore_error_handler(); }'), 'new safe operation was rejected or left incomplete');
    ensure($rule->transform($path, $completed) === $completed, 'mixed operation completion is not idempotent');
    $decoy = "\nclass UnrelatedScope { {$extra} }\n";
    ensure(str_ends_with($rule->transform($path, $variant . $decoy), $decoy), 'unrelated class operation was changed');
    echo "PASS additional safe handler, mixed original/adapted operations and unrelated class preservation\n";
}
printf("PASS Workerman operation scopes in %.3fs\n", microtime(true) - $started);
