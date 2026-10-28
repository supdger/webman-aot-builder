<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Guided;

final class ProcessRunner
{
    public function __construct(private readonly string $log)
    {
    }

    /**
     * @param list<string> $command
     * @param array<string,string> $environment
     * @return array{code:int,stdout:string,stderr:string}
     */
    public function run(array $command, string $stage, ?string $cwd = null, array $environment = [], bool $machineOutput = false): array
    {
        $started = microtime(true);
        fwrite(STDOUT, "[开始] {$stage}\n");
        $handle = fopen($this->log, 'ab');
        if ($handle === false) {
            throw new \RuntimeException('无法写入日志：' . $this->log);
        }
        fwrite($handle, "\n[{$stage}] " . json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $output = [1 => '', 2 => ''];
        try {
            $exitCode = \WebmanAotBuilder\Cli\ProcessOutput::run(
                $command,
                $cwd,
                array_merge(getenv(), $environment),
                ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                static function (int $index, string $chunk) use (&$output, $machineOutput, $handle): void {
                    $output[$index] .= $chunk;
                    if (!$machineOutput) {
                        $output[$index] = substr($output[$index], -65536);
                    }
                    fwrite($handle, $chunk);
                    fflush($handle);
                    if (!$machineOutput || $index === 2) {
                        $stream = $index === 1 ? STDOUT : STDERR;
                        fwrite($stream, $chunk);
                        fflush($stream);
                    }
                },
                static function (float $elapsed, float $silent) use ($stage, $handle): void {
                    $message = sprintf("[等待输出] %s，进程仍在运行；已耗时 %.0f 秒，连续 %.0f 秒无新输出，无法据此确认工作进度。\n", $stage, $elapsed, $silent);
                    fwrite(STDOUT, $message);
                    fflush(STDOUT);
                    fwrite($handle, $message);
                    fflush($handle);
                }
            );
        } catch (\Throwable $failure) {
            $message = sprintf("[失败] %s，耗时 %.1f 秒：%s\n", $stage, microtime(true) - $started, $failure->getMessage());
            fwrite(STDERR, $message);
            fwrite($handle, $message);
            fclose($handle);
            throw $failure;
        }
        $summary = sprintf("[%s] %s，耗时 %.1f 秒，退出码 %d\n", $exitCode === 0 ? '成功' : '失败', $stage, microtime(true) - $started, $exitCode);
        fwrite(STDOUT, $summary);
        fwrite($handle, $summary);
        fclose($handle);
        return ['code' => $exitCode, 'stdout' => $output[1], 'stderr' => $output[2]];
    }
}
