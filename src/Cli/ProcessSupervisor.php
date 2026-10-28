<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Cli;

/** Keep the command and its children inside one private lifetime boundary. */
final class ProcessSupervisor
{
    /** @param list<string> $command @param array<string,string>|null $environment */
    public static function command(array $command, ?string $directory, ?array $environment, mixed &$privateFile): array
    {
        $root = dirname(__DIR__, 2);
        if (PHP_OS_FAMILY === 'Darwin') {
            $helper = $root . '/bin/process-supervisor';
            if (!is_file($helper) || !is_executable($helper)) {
                throw new \RuntimeException('命令清理组件缺失，请重新安装本版工具。');
            }
            return [$helper, (string) getmypid(), '--', ...$command];
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return $command;
        }
        $script = $root . '/installer/process-supervisor/windows.ps1';
        if (!is_file($script)) {
            throw new \RuntimeException('命令清理组件缺失，请重新安装本版工具。');
        }
        $command[0] = self::executable($command[0], $directory, $environment);
        $privateFile = tmpfile();
        if ($privateFile === false || fwrite($privateFile, json_encode($command, JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException('unable to create private command arguments');
        }
        fflush($privateFile);
        $system = $environment['SystemRoot'] ?? $environment['SYSTEMROOT'] ?? getenv('SystemRoot');
        $powershell = rtrim((string) $system, '/\\') . '/System32/WindowsPowerShell/v1.0/powershell.exe';
        if (!is_file($powershell)) {
            throw new \RuntimeException('Windows 命令运行组件缺失。');
        }
        return [$powershell, '-NoLogo', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script,
            '-CommandFile', stream_get_meta_data($privateFile)['uri'], '-OwnerPid', (string) getmypid()];
    }

    /** @param array<string,string>|null $environment */
    private static function executable(string $command, ?string $directory, ?array $environment): string
    {
        if (str_contains($command, '/') || str_contains($command, '\\')) {
            $path = preg_match('/^(?:[A-Za-z]:|[\\\\\/]{2})/', $command) === 1 ? $command : ($directory ?? getcwd()) . '/' . $command;
            $resolved = realpath($path);
            if ($resolved !== false && is_file($resolved)) {
                return $resolved;
            }
        } else {
            $path = getenv('PATH');
            if ($environment !== null) {
                $path = '';
                foreach ($environment as $name => $value) {
                    if (strcasecmp($name, 'PATH') === 0) { $path = $value; break; }
                }
            }
            foreach (explode(PATH_SEPARATOR, (string) $path) as $entry) {
                foreach (str_ends_with(strtolower($command), '.exe') ? [''] : ['.exe', ''] as $extension) {
                    $candidate = rtrim(trim($entry, '"'), '/\\') . '/' . $command . $extension;
                    $resolved = realpath($candidate);
                    if ($resolved !== false && is_file($resolved)) { return $resolved; }
                }
            }
        }
        throw new \RuntimeException('unable to find command executable: ' . $command);
    }
}
