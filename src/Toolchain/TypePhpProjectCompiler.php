<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;

final class TypePhpProjectCompiler
{
    /** @var \Closure(list<string>,string,array<string,string>):int */
    private readonly \Closure $run;

    /**
     * @param (\Closure(list<string>,string,array<string,string>):int)|null $run
     */
    public function __construct(?\Closure $run = null)
    {
        $this->run = $run ?? $this->runProcess(...);
    }

    /**
     * @param array{php:string,typephp:string,phpx:string,compiler:string,objcopy:string,sysroot:string,phprc:string,sdkSha256:string} $tools
     * @return array{artifact:string,sha256:string,size:int}
     */
    public function compile(string $mirrorDirectory, string $outputName, array $tools, ?string $objectCache = null, ?string $identity = null, bool $fresh = false): array
    {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
            || is_link($mirrorDirectory)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $outputName) !== 1
        ) {
            throw new ConfigurationException('TypePHP requires a safe isolated project mirror and output name');
        }
        $project = $mirror . '/project.linux.yml';
        $contents = is_file($project) && !is_link($project)
            ? file_get_contents($project)
            : false;
        $sysroot = realpath($tools['sysroot']);
        $sysrootFlag = is_string($sysroot)
            ? '--sysroot="' . str_replace('\\', '/', $sysroot) . '"'
            : '';
        if (!is_string($contents)
            || preg_match(
                '/^output: build\/' . preg_quote($outputName, '/') . '$/m',
                $contents
            ) !== 1
            || !str_contains($contents, "\nbuild-dir: build\n")
            || !str_contains($contents, "\ntarget-platform: x86_64-unknown-linux-musl\n")
            || !str_contains($contents, 'reproducible-source-prefix: /usr/src/webman-aot-builder')
            || $sysrootFlag === ''
            || substr_count($contents, $sysrootFlag) !== 4
        ) {
            throw new ConfigurationException('TypePHP project lacks the locked full-static target configuration');
        }
        foreach (['php', 'compiler', 'objcopy'] as $name) {
            if (!is_file($tools[$name]) || !is_executable($tools[$name])) {
                throw new ConfigurationException("locked {$name} executable is missing");
            }
        }
        foreach (['typephp', 'phpx', 'sysroot', 'phprc'] as $name) {
            if (!is_dir($tools[$name])) {
                throw new ConfigurationException("locked {$name} directory is missing");
            }
        }
        if (!is_file($tools['typephp'] . '/bin/tpc.php')
            || realpath($tools['phpx'] . '/full-static/sdk') === false
        ) {
            throw new ConfigurationException('locked TypePHP full-static SDK is incomplete');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $tools['sdkSha256']) !== 1
            || (new StaticSdkFingerprint())->digest($tools['phpx'] . '/full-static/sdk')
                !== $tools['sdkSha256']
        ) {
            throw new ConfigurationException('full-static SDK fingerprint differs from the locked input');
        }

        $lock = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/toolchain.lock.json'), true, flags: JSON_THROW_ON_ERROR);
        $sdkPolicy = $lock['evidence']['patchedSdk'] ?? null;
        if ($sdkPolicy !== null) {
            if (!is_array($sdkPolicy)) { throw new ConfigurationException('patched SDK approval is malformed'); }
            if (($sdkPolicy['sdkSha256'] ?? null) !== $tools['sdkSha256']) {
                throw new ConfigurationException('full-static SDK differs from the approved patched SDK');
            }
            (new SdkArchiveGuard())->assertDerivation($tools['phpx'] . '/full-static/sdk', $sdkPolicy);
        }

        $environment = [];
        foreach (getenv() as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $environment[$name] = $value;
            }
        }
        if ($objectCache !== null) {
            (new TypePhpPatchSourceVerifier())->verify($tools['typephp'], dirname(__DIR__, 2) . '/toolchain/patches/typephp/0.9.2/manifest.json');
            $build = dirname($mirror);
            if (basename($build) !== 'build') { $build = dirname($build); }
            $cacheParent = realpath(dirname($objectCache));
            if (!is_string($cacheParent) || $cacheParent !== realpath(dirname($build) . '/cache')
                || basename($objectCache) !== 'objects') {
                throw new ConfigurationException('object checkpoint cache escaped the project workspace');
            }
            if (!is_string($identity) || preg_match('/^[a-f0-9]{64}$/D', $identity) !== 1
                || is_link($objectCache) || (!is_dir($objectCache) && !mkdir($objectCache, 0700, true) && !is_dir($objectCache))) {
                throw new ConfigurationException('unsafe object checkpoint cache');
            }
            $environment['WEBMAN_AOT_OBJECT_CACHE'] = $objectCache;
            $environment['WEBMAN_AOT_CACHE_IDENTITY'] = $identity;
            $environment['WEBMAN_AOT_PROJECT_ROOT'] = $mirror;
            $environment['WEBMAN_AOT_FRESH'] = $fresh ? '1' : '0';
        } else {
            foreach (['WEBMAN_AOT_OBJECT_CACHE', 'WEBMAN_AOT_CACHE_IDENTITY', 'WEBMAN_AOT_PROJECT_ROOT', 'WEBMAN_AOT_FRESH'] as $name) {
                unset($environment[$name]);
            }
        }
        $environment['PHPX_HOME'] = $tools['phpx'];
        $environment['PHP_HOME'] = dirname($tools['php']);
        $environment['PHPRC'] = $tools['phprc'];
        $separator = PHP_OS_FAMILY === 'Windows' ? ';' : ':';
        $privatePath = dirname($tools['compiler'])
            . $separator . dirname($tools['php'])
            . $separator . (PHP_OS_FAMILY === 'Windows'
                ? (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32'
                : '/usr/bin:/bin');
        $environment = self::withPrivatePath($environment, $privatePath, PHP_OS_FAMILY);
        $compile = [
            $tools['php'],
            $tools['typephp'] . '/bin/tpc.php',
            $project,
            '--full-static',
            '--compiler=' . self::compilerCommand(
                $tools['compiler'],
                PHP_OS_FAMILY,
                $environment['PATH']
            ),
            '--job=4',
        ];
        if ($objectCache === null) { $compile[] = '--force'; }
        $exitCode = ($this->run)($compile, $mirror, $environment);
        if ($exitCode !== 0) {
            throw new \RuntimeException('编译未完成；已完成单元已保留。修正上面的错误后重试 webman-aot build；需要全量重建时运行 webman-aot build --fresh。未完成单元将从头编译。', $exitCode);
        }

        $artifact = $mirror . '/build/' . $outputName;
        if (!is_file($artifact) || is_link($artifact)) {
            throw new \RuntimeException('TypePHP did not produce the expected ELF');
        }
        if (($this->run)(
            [$tools['objcopy'], '--remove-section=.comment', $artifact],
            $mirror,
            $environment
        ) !== 0) {
            throw new \RuntimeException('locked objcopy could not normalize the ELF');
        }
        (new ElfStaticVerifier())->assertFullyStaticX86_64($artifact);
        $this->assertNoEmbeddedBuildPaths($artifact, [
            $mirror,
            $mirrorDirectory,
            $tools['typephp'],
            $tools['phpx'],
            $tools['sysroot'],
            $tools['phprc'],
            dirname($tools['php']),
            dirname($tools['compiler']),
        ]);
        $digest = hash_file('sha256', $artifact);
        $size = filesize($artifact);
        if (!is_string($digest) || !is_int($size)) {
            throw new \RuntimeException('unable to hash the compiled ELF');
        }
        return ['artifact' => $artifact, 'sha256' => $digest, 'size' => $size];
    }

    private static function compilerCommand(string $path, string $host, string $searchPath): string
    {
        if ($host === 'Windows') {
            $normalized = str_replace('\\', '/', $path);
            if (preg_match('/^[A-Za-z]:\\//', $normalized) !== 1
                || basename($normalized) !== 'clang++.exe'
                || str_contains(dirname($normalized), ';')
                || str_contains($path, "\0")
            ) {
                throw new ConfigurationException(
                    'locked Windows compiler must be an absolute clang++.exe in a directory without ";"; '
                    . 'reinstall with -InstallRoot set to a writable path without ";" and use the same '
                    . 'WEBMAN_AOT_BUILDER_HOME'
                );
            }
            $directories = explode(';', $searchPath);
            if (count($directories) !== 3) {
                throw new ConfigurationException(
                    'private compiler PATH contains an empty or relative directory'
                );
            }
            foreach ($directories as $directory) {
                if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $directory) !== 1) {
                    throw new ConfigurationException(
                        'private compiler PATH contains an empty or relative directory'
                    );
                }
            }

            return 'clang++.exe';
        }

        if (!str_starts_with($path, '/')
            || basename($path) !== 'clang++'
            || str_contains(dirname($path), ':')
            || str_contains($path, "\0")
        ) {
            throw new ConfigurationException(
                'locked macOS compiler must be an absolute clang++ in a directory without ":"; '
                . 'reinstall with --home set to a writable path without ":" and use the same '
                . 'WEBMAN_AOT_BUILDER_HOME'
            );
        }
        foreach (explode(':', $searchPath) as $directory) {
            if ($directory === '' || !str_starts_with($directory, '/')) {
                throw new ConfigurationException(
                    'private compiler PATH contains an empty or relative directory'
                );
            }
        }

        return 'clang++';
    }

    /**
     * @param array<string, string> $environment
     * @return array<string, string>
     */
    private static function withPrivatePath(array $environment, string $path, string $host): array
    {
        if ($host === 'Windows') {
            foreach (array_keys($environment) as $name) {
                if (strcasecmp($name, 'PATH') === 0
                    || strcasecmp($name, 'NoDefaultCurrentDirectoryInExePath') === 0
                ) {
                    unset($environment[$name]);
                }
            }
        }
        $environment['PATH'] = $path;
        if ($host === 'Windows') {
            $environment['NoDefaultCurrentDirectoryInExePath'] = '1';
        }

        return $environment;
    }

    /** @param list<string> $paths */
    private function assertNoEmbeddedBuildPaths(string $artifact, array $paths): void
    {
        $markers = [];
        foreach ($paths as $path) {
            if (strlen($path) < 8) {
                continue;
            }
            $markers[$path] = true;
            $markers[str_replace('\\', '/', $path)] = true;
            $markers[str_replace('/', '\\', $path)] = true;
        }
        $handle = fopen($artifact, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('compiled ELF cannot be checked for build paths');
        }
        $overlap = '';
        $overlapLength = max(array_map('strlen', array_keys($markers))) - 1;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) {
                    throw new \RuntimeException('compiled ELF cannot be checked for build paths');
                }
                $window = $overlap . $chunk;
                foreach ($markers as $marker => $_) {
                    if (str_contains($window, $marker)) {
                        throw new ConfigurationException(
                            'compiled ELF embeds a private build path'
                        );
                    }
                }
                $overlap = substr($window, -$overlapLength);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<string> $command
     * @param array<string,string> $environment
     */
    private function runProcess(array $command, string $directory, array $environment): int
    {
        $started = microtime(true);
        $code = \WebmanAotBuilder\Cli\ProcessOutput::run(
            $command, $directory, $environment, STDIN,
            static function (int $index, string $chunk): void {
                $stream = $index === 1 ? STDOUT : STDERR;
                fwrite($stream, $chunk);
                fflush($stream);
            },
            static function (float $elapsed, float $silent): void {
                fwrite(STDERR, sprintf("[build] Compiler process is running; elapsed %.0fs, no output for %.0fs; work progress unknown.\n", $elapsed, $silent));
            }
        );
        fwrite(STDERR, sprintf("[build] Compiler process %s in %.1fs; exit code %d.\n", $code === 0 ? 'completed' : 'failed', microtime(true) - $started, $code));
        return $code;
    }

}
