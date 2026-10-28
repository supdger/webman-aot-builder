<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Cli\UnavailableException;

final class WindowsToolchainPreparer implements ToolchainPreparer
{
    public function __construct(
        private readonly string $script,
        private readonly string $privateRoot
    ) {
    }

    public function prepare(string $candidate, ?\Closure $progress = null): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || PHP_INT_SIZE !== 8) {
            throw new UnavailableException('Windows toolchain preparation requires Windows x64');
        }
        $root = realpath($this->privateRoot);
        $directory = realpath($candidate);
        if (!is_string($root)
            || !is_string($directory)
            || is_link($candidate)
            || !str_starts_with(
                strtolower(str_replace('\\', '/', $directory)),
                strtolower(rtrim(str_replace('\\', '/', $root), '/') . '/toolchains/candidates/')
            )
            || !is_dir($candidate . '/artifacts')
            || !is_file($this->script)
            || is_link($this->script)
        ) {
            throw new ConfigurationException('Windows toolchain candidate is missing or unsafe');
        }
        $systemRoot = getenv('SystemRoot');
        if (!is_string($systemRoot) || $systemRoot === '') {
            throw new UnavailableException('Windows system PowerShell location is unavailable');
        }
        $powershell = rtrim($systemRoot, '/\\')
            . '/System32/WindowsPowerShell/v1.0/powershell.exe';
        if (!is_file($powershell)) {
            throw new UnavailableException('Windows system PowerShell is unavailable');
        }
        $systemModules = rtrim($systemRoot, '/\\')
            . '/System32/WindowsPowerShell/v1.0/Modules';
        if (!is_dir($systemModules)) {
            throw new UnavailableException('Windows system PowerShell modules are unavailable');
        }
        $environment = getenv();
        if (!is_array($environment)) {
            throw new UnavailableException('Windows environment is unavailable');
        }
        $modulePath = getenv('PSModulePath');
        $environment['PSModulePath'] = $systemModules
            . (is_string($modulePath) && $modulePath !== '' ? ';' . $modulePath : '');
        $command = [
            $powershell,
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $this->script,
            '-Artifacts',
            $candidate . '/artifacts',
            '-WorkRoot',
            $candidate . '/prepared',
            '-LockFile',
            $candidate . '/toolchain.lock.json',
            '-PrepareOnly',
            '-Offline',
        ];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname($this->script),
            $environment
        );
        if (!is_resource($process)) {
            throw new UnavailableException('unable to start private Windows toolchain preparation');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $tail = '';
        $exit = null;
        $output = '';
        $nextHeartbeat = microtime(true) + 30;
        $reportOutput = static function (string $chunk) use (&$output, $progress): void {
            if ($progress === null) {
                return;
            }
            $output .= str_replace("\r", "\n", $chunk);
            while (($newline = strpos($output, "\n")) !== false) {
                $line = trim(substr($output, 0, $newline));
                $output = substr($output, $newline + 1);
                if (preg_match('/^(Downloading|Extracting|Stripping|Applying|Assembling|Calculating|Building)\b/', $line) === 1) {
                    $progress('SDK: ' . $line);
                }
            }
            $output = substr($output, -4096);
        };
        while (true) {
            $status = proc_get_status($process);
            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index]);
                if (is_string($chunk) && $chunk !== '') {
                    $tail = substr($tail . $chunk, -8192);
                    if ($index === 1) {
                        $reportOutput($chunk);
                    }
                }
            }
            if (!$status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if ($progress !== null && microtime(true) >= $nextHeartbeat) {
                $progress('SDK preparation is still running...');
                $nextHeartbeat = microtime(true) + 30;
            }
            usleep(20000);
        }
        foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if (is_string($chunk) && $chunk !== '') {
                $tail = substr($tail . $chunk, -8192);
                if ($index === 1) {
                    $reportOutput($chunk);
                }
            }
            fclose($pipes[$index]);
        }
        $closed = proc_close($process);
        if (($exit >= 0 ? $exit : $closed) !== 0) {
            throw new UnavailableException(
                'Windows private toolchain preparation failed: ' . trim($tail)
            );
        }
        $this->assertReady($candidate);
    }

    public function assertReady(string $generation): void
    {
        $tools = (new PreparedToolchain())->load(
            $generation . '/prepared/prepared-toolchain.json',
            $this->privateRoot,
            $generation . '/toolchain.lock.json',
            'windows-x86_64'
        );
        (new TypePhpPatchSourceVerifier())->verify(
            $tools['typephp'],
            dirname($this->script, 2) . '/toolchain/patches/typephp/0.9.5/manifest.json'
        );
    }
}
