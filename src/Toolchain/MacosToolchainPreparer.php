<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Cli\UnavailableException;

final class MacosToolchainPreparer implements ToolchainPreparer
{
    public function __construct(
        private readonly string $script,
        private readonly string $privateRoot
    ) {
    }

    public function prepare(string $candidate, ?\Closure $progress = null): void
    {
        if (PHP_OS_FAMILY !== 'Darwin' || php_uname('m') !== 'arm64') {
            throw new UnavailableException('macOS toolchain preparation requires macOS ARM64');
        }
        $root = realpath($this->privateRoot);
        $directory = realpath($candidate);
        if (!is_string($root)
            || !is_string($directory)
            || is_link($candidate)
            || !str_starts_with($directory, rtrim($root, '/') . '/toolchains/candidates/')
            || !is_dir($candidate . '/artifacts')
            || !is_file($this->script)
            || is_link($this->script)
        ) {
            throw new ConfigurationException('macOS toolchain candidate is missing or unsafe');
        }
        $privatePhp = $root . '/current/runtime/bin/php';
        if (!is_file($privatePhp) || is_link($privatePhp) || !is_executable($privatePhp)) {
            throw new UnavailableException('installed private macOS PHP runtime is missing');
        }
        $compilerPhp = $this->compilerDriver();
        $command = [
            $privatePhp,
            $this->script,
            '--artifacts=' . $candidate . '/artifacts',
            '--work-root=' . $candidate . '/prepared',
            '--lock=' . $candidate . '/toolchain.lock.json',
            '--php=' . $compilerPhp,
        ];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname($this->script)
        );
        if (!is_resource($process)) {
            throw new UnavailableException('unable to start private macOS toolchain preparation');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $tail = '';
        $exit = null;
        $nextHeartbeat = microtime(true) + 30;
        $pendingStages = '';
        $reportStages = static function (string $chunk) use ($progress, &$pendingStages): void {
            if ($progress === null) {
                return;
            }
            $pendingStages .= $chunk;
            while (($newline = strpos($pendingStages, "\n")) !== false) {
                $line = trim(substr($pendingStages, 0, $newline));
                $pendingStages = substr($pendingStages, $newline + 1);
                if (str_starts_with($line, '[prepare] ')) {
                    $progress(substr($line, 10));
                }
            }
        };
        while (true) {
            $status = proc_get_status($process);
            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index]);
                if (is_string($chunk) && $chunk !== '') {
                    $tail = substr($tail . $chunk, -8192);
                    if ($index === 2) {
                        $reportStages($chunk);
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
            stream_set_blocking($pipes[$index], true);
            $chunk = stream_get_contents($pipes[$index]);
            if (is_string($chunk) && $chunk !== '') {
                $tail = substr($tail . $chunk, -8192);
                if ($index === 2) {
                    $reportStages($chunk);
                }
            }
            fclose($pipes[$index]);
        }
        $closed = proc_close($process);
        if (($exit >= 0 ? $exit : $closed) !== 0) {
            throw new UnavailableException(
                'macOS private toolchain preparation failed: ' . trim($tail)
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
            'macos-arm64'
        );
        (new TypePhpPatchSourceVerifier())->verify(
            $tools['typephp'],
            dirname($this->script, 2) . '/toolchain/patches/typephp/0.9.5/manifest.json'
        );
        $installed = $this->compilerDriver();
        if (hash_file('sha256', $tools['php']) !== hash_file('sha256', $installed)) {
            throw new ConfigurationException('prepared macOS compiler PHP differs from the installed runtime lock');
        }
    }

    private function compilerDriver(): string
    {
        $root = realpath($this->privateRoot);
        if (!is_string($root)) {
            throw new UnavailableException('private macOS tool directory is missing');
        }
        $lockFile = $root . '/current/app/installer/runtime.lock.json';
        $driver = $root . '/current/runtime/bin/php-compiler';
        $contents = is_file($lockFile) && !is_link($lockFile)
            ? file_get_contents($lockFile)
            : false;
        $lock = is_string($contents) ? json_decode($contents, true) : null;
        $expected = is_array($lock)
            ? ($lock['runtimes']['macos-arm64']['compilerDriver']['binarySha256'] ?? null)
            : null;
        $actual = is_file($driver) && !is_link($driver) && is_executable($driver)
            ? hash_file('sha256', $driver)
            : false;
        if (!is_string($expected)
            || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
            || !is_string($actual)
            || !hash_equals($expected, $actual)
        ) {
            throw new UnavailableException('installed private macOS compiler PHP differs from its lock');
        }
        return $driver;
    }
}
