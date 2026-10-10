<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Cli;

require_once __DIR__ . '/ProcessSupervisor.php';

/** Poll regular files: Windows anonymous process pipes cannot be made reliably nonblocking. */
final class ProcessOutput
{
    /**
     * @param list<string> $command
     * @param array<string,string>|null $environment
     * @param \Closure(int,string):void $output
     * @param \Closure(float,float):void $heartbeat elapsed and seconds without output
     */
    public static function run(array $command, ?string $directory, ?array $environment, mixed $input, \Closure $output, \Closure $heartbeat): int
    {
        $files = [];
        $process = null;
        $commandFile = null;
        try {
            foreach ([1, 2] as $index) {
                $files[$index] = tmpfile();
                if ($files[$index] === false) {
                    throw new \RuntimeException('unable to create private process output file');
                }
            }
            $command = ProcessSupervisor::command($command, $directory, $environment, $commandFile);
            $process = proc_open($command, [0 => $input, 1 => $files[1], 2 => $files[2]], $pipes, $directory, $environment, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new \RuntimeException('unable to start process');
            }
            $paths = [];
            foreach ($files as $index => $file) {
                $paths[$index] = stream_get_meta_data($file)['uri'];
            }
            $offsets = [1 => 0, 2 => 0];
            $started = $lastOutput = $lastHeartbeat = microtime(true);
            while (true) {
                $status = proc_get_status($process);
                foreach ($paths as $index => $path) {
                    // Bound each read so a busy stdout cannot starve stderr or status checks.
                    $chunk = file_get_contents($path, false, null, $offsets[$index], 65536);
                    if (is_string($chunk) && $chunk !== '') {
                        $offsets[$index] += strlen($chunk);
                        $output($index, $chunk);
                        $lastOutput = microtime(true);
                    }
                }
                if (!$status['running']) {
                    foreach ($paths as $index => $path) {
                        while (($chunk = file_get_contents($path, false, null, $offsets[$index], 65536)) !== false && $chunk !== '') {
                            $offsets[$index] += strlen($chunk);
                            $output($index, $chunk);
                        }
                    }
                    $closed = proc_close($process);
                    $process = null;
                    return $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
                }
                $now = microtime(true);
                if ($now - max($lastOutput, $lastHeartbeat) >= 5) {
                    $heartbeat($now - $started, $now - $lastOutput);
                    $lastHeartbeat = $now;
                }
                usleep(100000);
            }
        } finally {
            if (is_resource($process)) {
                if (PHP_OS_FAMILY === 'Windows' && is_resource($commandFile)) {
                    rewind($commandFile);
                    ftruncate($commandFile, 0);
                    fwrite($commandFile, 'cancel');
                    fflush($commandFile);
                    $deadline = microtime(true) + 6;
                    do {
                        $status = proc_get_status($process);
                        if (!$status['running']) { break; }
                        usleep(20000);
                    } while (microtime(true) < $deadline);
                }
                proc_terminate($process);
                proc_close($process);
            }
            if (is_resource($commandFile)) { fclose($commandFile); }
            foreach ($files as $file) {
                if (is_resource($file)) {
                    fclose($file); // tmpfile removes its private file on close, including failures.
                }
            }
        }
    }
}
