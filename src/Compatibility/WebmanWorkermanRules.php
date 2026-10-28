<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

final class WebmanWorkermanRules
{
    /**
     * Source tokens and postconditions determine compatibility. The version values
     * record the oldest verified input; they are not runtime version gates.
     *
     * @return list<CompatibilityRule>
     */
    public static function knownRules(): array
    {
        return [
            new BoundedTextRule(
                'webman-405-handler-variadic',
                'workerman/webman-framework',
                'vendor/workerman/webman-framework/src/App.php',
                new MinimumVersion('2.2.3'),
                [
                    '$allowHeader = implode(',
                    "return new Response(405, ['Allow' => \$allowHeader], '405 Method Not Allowed');",
                ],
                'return static function () use ($allowHeader) {',
                'return static function (...$arguments) use ($allowHeader) {',
                1,
                ['return static function (...$arguments) use ($allowHeader) {'],
                ['defaultRouteMethodNotAllowedResponse']
            ),
            new BoundedTextRule(
                'webman-fallback-handler-variadic',
                'workerman/webman-framework',
                'vendor/workerman/webman-framework/src/App.php',
                new MinimumVersion('2.2.3'),
                ['function getFallback(', 'throw new PageNotFoundException();'],
                'return Route::getFallback($plugin, $status) ?: function () {',
                'return Route::getFallback($plugin, $status) ?: function (...$arguments) {',
                1,
                ['return Route::getFallback($plugin, $status) ?: function (...$arguments) {'],
                ['getFallback']
            ),
            new BoundedTextRule(
                'webman-include-handler-variadic',
                'workerman/webman-framework',
                'vendor/workerman/webman-framework/src/App.php',
                new MinimumVersion('2.2.3'),
                ['function findFile(', 'return static::execPhpFile($file);'],
                'static::collectCallbacks($key, [function () use ($file) {',
                'static::collectCallbacks($key, [function (...$arguments) use ($file) {',
                1,
                ['static::collectCallbacks($key, [function (...$arguments) use ($file) {'],
                ['findFile']
            ),
            new BoundedTextRule(
                'webman-object-switch-terminal',
                'workerman/webman-framework',
                'vendor/workerman/webman-framework/src/App.php',
                new MinimumVersion('2.2.3'),
                ['function stringify($data): string', "case 'object':"],
                "                if (!method_exists(\$data, '__toString')) {\n"
                    . "                    return 'Object';\n"
                    . "                }\n"
                    . "            default:",
                "                if (!method_exists(\$data, '__toString')) {\n"
                    . "                    return 'Object';\n"
                    . "                }\n"
                    . "                return (string)\$data;\n"
                    . "            default:",
                1,
                ["                return (string)\$data;\n            default:"],
                ['stringify']
            ),
            new BoundedTextRule(
                'webman-file-error-handler-variadic',
                'workerman/webman-framework',
                'vendor/workerman/webman-framework/src/File.php',
                new MinimumVersion('2.2.3'),
                ['public function move(string $destination): File', '$error = $msg;'],
                'set_error_handler(function ($type, $msg) use (&$error) {',
                'set_error_handler(function ($type, $msg, ...$__err) use (&$error) {',
                1,
                ['set_error_handler(function ($type, $msg, ...$__err) use (&$error) {'],
                ['move']
            ),
            new BoundedTextRule(
                'webman-config-directory-object',
                'workerman/webman-framework',
                'vendor/workerman/webman-framework/src/Config.php',
                new MinimumVersion('2.2.3'),
                ['class Config', 'function loadFromDir('],
                'if (is_dir($file) ||',
                'if ($file->isDir() ||',
                1,
                ['if ($file->isDir() ||'],
                ['loadFromDir']
            ),
            new BoundedTextRule(
                'webman-config-path-string',
                'workerman/webman-framework',
                'vendor/workerman/webman-framework/src/Config.php',
                new MinimumVersion('2.2.3'),
                ['class Config', 'function loadFromDir('],
                'substr($file, 0, -4)',
                'substr((string) $file, 0, -4)',
                1,
                ['substr((string) $file, 0, -4)'],
                ['loadFromDir']
            ),
            new BoundedTextRule(
                'webman-config-include-path-string',
                'workerman/webman-framework',
                'vendor/workerman/webman-framework/src/Config.php',
                new MinimumVersion('2.2.3'),
                ['class Config', 'function loadFromDir('],
                '$config = include $file;',
                '$config = include (string) $file;',
                2,
                ['$config = include (string) $file;'],
                ['loadFromDir', 'read']
            ),
            new BoundedTextRule(
                'workerman-timer-signal-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Timer.php',
                new MinimumVersion('5.2.1'),
                ['public static function init(?EventInterface $event = null): void', 'public static function signalHandle('],
                'pcntl_signal(SIGALRM, self::signalHandle(...), false);',
                'pcntl_signal(SIGALRM, static fn (...$__sig) => self::signalHandle(), false);',
                1,
                ['pcntl_signal(SIGALRM, static fn (...$__sig) => self::signalHandle(), false);'],
                ['init']
            ),
            new BoundedTextRule(
                'workerman-select-signal-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Events/Select.php',
                new MinimumVersion('5.2.1'),
                ['public function onSignal(int $signal, callable $func)', '$this->signalEvents[$signal]'],
                'pcntl_signal($signal, fn () => $this->safeCall($this->signalEvents[$signal], [$signal]));',
                'pcntl_signal($signal, fn (...$__sig) => $this->safeCall($this->signalEvents[$signal], [$signal]));',
                1,
                ['pcntl_signal($signal, fn (...$__sig) => $this->safeCall($this->signalEvents[$signal], [$signal]));'],
                ['onSignal']
            ),
            new BoundedTextRule(
                'workerman-worker-pid-runtime-path',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Worker.php',
                new MinimumVersion('5.2.1'),
                ['class Worker', '$startFileDir = dirname(static::$startFile);'],
                '$file = __DIR__ . "/../../$unique_prefix.pid";',
                '$file = getcwd() . "/vendor/workerman/$unique_prefix.pid";',
                1,
                ['$file = getcwd() . "/vendor/workerman/$unique_prefix.pid";'],
                ['init']
            ),
            new BoundedTextRule(
                'workerman-worker-target-loadavg-call',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Worker.php',
                new MinimumVersion('5.2.1'),
                ['class Worker', 'function writeStatisticsToStatusFile()'],
                "array_map(round(...), sys_getloadavg(), [2, 2, 2])",
                "array_map(round(...), call_user_func('sys_getloadavg'), [2, 2, 2])",
                1,
                ["array_map(round(...), call_user_func('sys_getloadavg'), [2, 2, 2])"],
                ['writeStatisticsToStatusFile']
            ),
            new BoundedTextRule(
                'workerman-worker-error-suppressor-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Worker.php',
                new MinimumVersion('5.2.1'),
                ['class Worker', 'restore_error_handler();'],
                'set_error_handler(static fn (): bool => true);',
                'set_error_handler(static fn (...$__err): bool => true);',
                7,
                ['set_error_handler(static fn (...$__err): bool => true);'],
                ['*']
            ),
            new BoundedTextRule(
                'workerman-worker-error-handler-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Worker.php',
                new MinimumVersion('5.2.1'),
                ['class Worker', 'restore_error_handler();'],
                'set_error_handler(function ($code, $msg) {',
                'set_error_handler(function ($code, $msg, ...$__err) {',
                1,
                ['set_error_handler(function ($code, $msg, ...$__err) {'],
                ['checkPortAvailable']
            ),
            new BoundedTextRule(
                'workerman-worker-walk-stop-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Worker.php',
                new MinimumVersion('5.2.1'),
                ['class Worker', '$workers'],
                'array_walk($workers, static fn (Worker $worker) => $worker->stop(false));',
                'array_walk($workers, static fn (Worker $worker, ...$__walk) => $worker->stop(false));',
                1,
                ['array_walk($workers, static fn (Worker $worker, ...$__walk) => $worker->stop(false));'],
                ['stopAll']
            ),
            new BoundedTextRule(
                'workerman-worker-walk-pid-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Worker.php',
                new MinimumVersion('5.2.1'),
                ['class Worker', '$workerPidArray'],
                'array_walk($workerPidArray, static fn ($pid) => posix_kill($pid, $sig));',
                'array_walk($workerPidArray, static fn ($pid, ...$__walk) => posix_kill($pid, $sig));',
                1,
                ['array_walk($workerPidArray, static fn ($pid, ...$__walk) => posix_kill($pid, $sig));'],
                ['reload']
            ),
            new BoundedTextRule(
                'workerman-worker-signal-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Worker.php',
                new MinimumVersion('5.2.1'),
                ['class Worker', 'function signalHandler(int $signal'],
                'pcntl_signal($signal, static::signalHandler(...), false);',
                'pcntl_signal($signal, static fn (...$__sig) => static::signalHandler($__sig[0]), false);',
                1,
                ['pcntl_signal($signal, static fn (...$__sig) => static::signalHandler($__sig[0]), false);'],
                ['installSignal', 'reinstallSignal']
            ),
            new WorkermanCallbackRule('tcp'),
            new WorkermanCallbackRule('async-tcp'),
        ];
    }
}
