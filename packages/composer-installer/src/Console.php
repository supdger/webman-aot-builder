<?php
declare(strict_types=1);

namespace Supdger\WebmanAotInstaller;

final class Console
{
    private static mixed $parent = null;

    public static function unattended(): bool
    {
        foreach (['COMPOSER_NO_INTERACTION', 'CI'] as $name) {
            if (in_array(strtolower((string) getenv($name)), ['1', 'true', 'yes'], true)) { return true; }
        }
        return false;
    }

    /** Restore only an explicitly requested guide in an already attached console. */
    public static function restore(array $argv): ?int
    {
        if (self::unattended() || getenv('WEBMAN_AOT_GUIDE_CONSOLE_DEPTH') === '1') {
            return null;
        }
        $devices = match (PHP_OS_FAMILY) {
            'Windows' => ['CONIN$', 'CONOUT$'],
            'Darwin' => ['/dev/tty', '/dev/tty'],
            default => null,
        };
        if ($devices === null) { return null; }
        $input = @fopen($devices[0], 'rb');
        $output = @fopen($devices[1], 'r+b');
        try {
            if (!is_resource($input) || !is_resource($output)
                || !stream_isatty($input) || !stream_isatty($output)) {
                return null;
            }
            $entry = dirname(__DIR__) . '/bin/webman-aot';
            if (!is_file($entry) || is_link($entry)) {
                throw new \RuntimeException('本 Composer 入口文件不可用；请重新安装 supdger/webman-aot-builder。');
            }
            $environment = getenv();
            if (!is_array($environment)) { $environment = []; }
            $environment['WEBMAN_AOT_GUIDE_CONSOLE_DEPTH'] = '1';
            $descriptors = [$input, $output, $output];
            if (PHP_OS_FAMILY === 'Darwin') {
                $descriptors[3] = ['pipe', 'r'];
                $environment['WEBMAN_AOT_GUIDE_PARENT_FD'] = '3';
            }
            $process = proc_open([PHP_BINARY, $entry, ...array_slice($argv, 1)],
                $descriptors, $pipes, getcwd() ?: null, $environment, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new \RuntimeException('无法在当前终端启动项目引导。');
            }
            // proc_close closes its pipes before waiting; keep the parent end open until the guide exits.
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $closed = proc_close($process);
                    return $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
                }
                usleep(100000);
            }
        } finally {
            if (isset($pipes[3]) && is_resource($pipes[3])) { fclose($pipes[3]); }
            if (is_resource($input)) { fclose($input); }
            if (is_resource($output)) { fclose($output); }
        }
    }

    public static function assertParent(): void
    {
        $parent = self::parent();
        if (!is_resource($parent)) { return; }
        fread($parent, 1);
        if (feof($parent)) {
            throw new \RuntimeException('启动引导的父入口已结束；已停止本次下载，保留缓存。请直接运行 PHP 代理重试。');
        }
    }

    /** Read menu input only while the restored console still has its parent. */
    public static function read(): string|false
    {
        $parent = self::parent();
        if (!is_resource($parent)) { return self::readLine(); }
        $pending = '';
        while (true) {
            self::assertParent();
            $read = [STDIN, $parent]; $write = $except = [];
            $selected = stream_select($read, $write, $except, 1);
            if ($selected === false) {
                throw new \RuntimeException('无法继续读取当前终端；本次流程已停止。');
            }
            self::assertParent();
            if ($selected !== 0 && !in_array(STDIN, $read, true)) { continue; }
            $blocked = stream_get_meta_data(STDIN)['blocked'];
            if (!stream_set_blocking(STDIN, false)) {
                throw new \RuntimeException('无法继续读取当前终端；本次流程已停止。');
            }
            try {
                $line = self::readLine();
            } finally {
                stream_set_blocking(STDIN, $blocked);
            }
            self::assertParent();
            if ($line === false) {
                if (feof(STDIN)) { return $pending === '' ? false : $pending; }
                continue;
            }
            $pending .= $line;
            if (str_ends_with($pending, "\n") || feof(STDIN)) { return $pending; }
        }
    }

    private static function readLine(): string|false
    {
        $failed = false;
        $previousHandler = null;
        $previousHandler = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$failed, &$previousHandler): bool {
            if (($severity === E_WARNING || $severity === E_NOTICE)
                && $file === __FILE__ && str_starts_with($message, 'fgets():')) {
                $failed = true;
                return true;
            }
            return $previousHandler !== null ? $previousHandler($severity, $message, $file, $line) !== false : false;
        });
        try {
            $line = fgets(STDIN);
        } finally {
            restore_error_handler();
        }
        if ($line === false && $failed) {
            throw new \RuntimeException('终端输入已断开，项目流程已停止。请在终端重新运行 webman-aot guide；需要通过 Composer 启动时，请先设置 COMPOSER_PROCESS_TIMEOUT=0。');
        }
        return $line;
    }

    private static function parent(): mixed
    {
        if (self::$parent !== null) { return self::$parent; }
        if (PHP_OS_FAMILY !== 'Darwin' || getenv('WEBMAN_AOT_GUIDE_CONSOLE_DEPTH') !== '1'
            || getenv('WEBMAN_AOT_GUIDE_PARENT_FD') !== '3') {
            return self::$parent = false;
        }
        $parent = @fopen('php://fd/3', 'rb');
        if (!is_resource($parent) || !stream_set_blocking($parent, false)) {
            throw new \RuntimeException('无法检查父入口状态；本次流程已停止。');
        }
        return self::$parent = $parent;
    }

}
