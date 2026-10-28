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
        $tail = '';
        $started = microtime(true);
        $code = \WebmanAotBuilder\Cli\ProcessOutput::run(
            $command, dirname($this->script), $environment,
            ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            static function (int $index, string $chunk) use (&$tail): void {
                $tail = substr($tail . $chunk, -8192);
                // Keep CLI JSON stdout clean; all preparation diagnostics go to stderr.
                fwrite(STDERR, $chunk);
                fflush(STDERR);
            },
            static function (float $elapsed, float $silent) use ($progress): void {
                $message = sprintf('SDK process is running; elapsed %.0fs, no output for %.0fs; work progress unknown.', $elapsed, $silent);
                if ($progress !== null) {
                    $progress($message);
                } else {
                    fwrite(STDERR, $message . PHP_EOL);
                }
            }
        );
        fwrite(STDERR, sprintf("[prepare] SDK process %s in %.1fs; exit code %d.\n", $code === 0 ? 'completed' : 'failed', microtime(true) - $started, $code));
        if ($code !== 0) {
            throw new UnavailableException(
                'Windows private toolchain preparation failed (exit code ' . $code . '): ' . trim($tail)
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
