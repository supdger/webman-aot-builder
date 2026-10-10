<?php
declare(strict_types=1);

namespace Supdger\WebmanAotInstaller;

final class Process
{
    /** @param list<string> $command @param array<string,string>|null $environment */
    public static function run(array $command, ?string $cwd = null, ?array $environment = null): int
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $cwd, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('无法启动所需系统命令：' . $command[0]);
        }
        return proc_close($process);
    }

    /** Wait for the owned curl process without losing its native progress output. */
    public static function download(array $command): int
    {
        require_once __DIR__ . '/Console.php';
        Console::assertParent();
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('无法启动下载命令：' . $command[0]);
        }
        try {
            while (true) {
                Console::assertParent();
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $closed = proc_close($process);
                    $process = null;
                    return $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
                }
                usleep(100000);
            }
        } finally {
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                    $deadline = microtime(true) + 1;
                    while (proc_get_status($process)['running'] && microtime(true) < $deadline) { usleep(10000); }
                    if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
                }
                proc_close($process);
            }
        }
    }

    /** @param list<string> $command */
    public static function output(array $command): string
    {
        $process = proc_open($command, [STDIN, ['pipe', 'w'], STDERR], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('无法检查归档：' . $command[0]);
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || !is_string($output)) {
            throw new \RuntimeException('归档检查失败；未执行包内代码。');
        }
        return $output;
    }
}
