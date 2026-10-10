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
            '--no-progress',
        ];
        if ($objectCache === null) { $compile[] = '--force'; }
        if (($this->run)($compile, $mirror, $environment) !== 0) {
            throw new \RuntimeException('编译未完成；已完成单元已保留。修正上面的错误后重试 webman-aot build；需要全量重建时运行 webman-aot build --fresh。未完成单元将从头编译。');
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
        $tail = "";
        $code = \WebmanAotBuilder\Cli\ProcessOutput::run(
            $command, $directory, $environment, STDIN,
            static function (int $index, string $chunk) use (&$tail): void {
                $tail = substr($tail . $chunk, -32768);
                $stream = $index === 1 ? STDOUT : STDERR;
                fwrite($stream, $chunk);
                fflush($stream);
            },
            static function (float $elapsed, float $silent): void {
                fwrite(STDERR, sprintf("[build] Compiler process is running; elapsed %.0fs, no output for %.0fs; work progress unknown.\n", $elapsed, $silent));
            }
        );
        fwrite(STDERR, sprintf("[build] Compiler process %s in %.1fs; exit code %d.\n", $code === 0 ? 'completed' : 'failed', microtime(true) - $started, $code));
        if ($code !== 0 && str_ends_with($command[1] ?? '', '/bin/tpc.php')) {
            $hint = $this->missingDependencyHint($directory, $tail);
            if ($hint !== null) { fwrite(STDERR, $hint . "\n"); }
        }
        return $code;
    }

    private function diagnosticPackageMetadataValid(array $package): bool
    {
        if (!is_string($package['name'] ?? null) || !is_string($package['version'] ?? null)
            || str_contains($package['name'], "\0")) { return false; }
        if (isset($package['install-path']) && (!is_string($package['install-path'])
            || str_contains($package['install-path'], "\0"))) { return false; }
        foreach (['source', 'dist'] as $kind) {
            if (!array_key_exists($kind, $package)) { continue; }
            if (!is_array($package[$kind])) { return false; }
            if (isset($package[$kind]['reference']) && !is_string($package[$kind]['reference'])) { return false; }
        }
        $autoload = $package['autoload'] ?? [];
        if (!is_array($autoload)) { return false; }
        foreach (['psr-0', 'psr-4'] as $kind) {
            $namespaces = $autoload[$kind] ?? [];
            if (!is_array($namespaces)) { return false; }
            foreach ($namespaces as $prefix => $paths) {
                if (!is_string($prefix)) { return false; }
                if (is_string($paths)) { continue; }
                if (!is_array($paths)) { return false; }
                foreach ($paths as $path) { if (!is_string($path)) { return false; } }
            }
        }
        $suggestions = $package['suggest'] ?? [];
        if (!is_array($suggestions)) { return false; }
        foreach ($suggestions as $name => $reason) {
            if (!is_string($name) || !is_string($reason)) { return false; }
        }
        return true;
    }

    private function missingDependencyHint(string $mirror, string $output): ?string
    {
        if (preg_match('/Trait [`\']([^`\']+)[`\'] not found in ([^\r\n]+\.php):(\d+)/', $output, $match) !== 1) {
            return null;
        }
        if (str_contains($mirror, "\0") || str_contains($match[2], "\0")) { return null; }
        $root = realpath($mirror);
        $file = realpath($match[2]);
        if (!is_string($root) || !is_string($file) || !str_starts_with($file, $root . '/') || is_link($match[2])) {
            return null;
        }
        $parent = dirname($match[2]);
        while ($parent !== $root) {
            if (is_link($parent) || dirname($parent) === $parent) { return null; }
            $parent = dirname($parent);
        }
        $relative = substr($file, strlen($root) + 1);
        $installedFile = $root . '/vendor/composer/installed.json';
        $lockFile = $root . '/composer.lock';
        if (!is_file($installedFile) || is_link($installedFile) || !is_file($lockFile) || is_link($lockFile)) { return null; }
        $installed = json_decode((string) file_get_contents($installedFile), true);
        $lock = json_decode((string) file_get_contents($lockFile), true);
        if (!is_array($installed) || !is_array($lock)) { return null; }
        $packages = $installed['packages'] ?? $installed;
        if (!is_array($packages) || !is_array($lock['packages'] ?? null)) { return null; }
        $locked = [];
        foreach ($lock['packages'] as $package) {
            if (!is_array($package) || !$this->diagnosticPackageMetadataValid($package)) { return null; }
            $locked[$package['name']] = $package;
        }
        $devPackages = $installed['dev-package-names'] ?? [];
        if (!is_array($devPackages)) { return null; }
        foreach ($devPackages as $name) { if (!is_string($name)) { return null; } }
        $devNames = array_fill_keys($devPackages, true);
        $owner = null;
        $ownerLength = 0;
        $providers = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !$this->diagnosticPackageMetadataValid($package)) { return null; }
            if (isset($devNames[$package['name']])) { continue; }
            $identity = $locked[$package['name']] ?? null;
            if (!is_array($identity) || ($identity['version'] ?? null) !== ($package['version'] ?? null)
                || ($identity['source']['reference'] ?? null) !== ($package['source']['reference'] ?? null)
                || ($identity['dist']['reference'] ?? null) !== ($package['dist']['reference'] ?? null)
                || ($identity['autoload'] ?? []) !== ($package['autoload'] ?? [])) { return null; }
            $path = $package['install-path'] ?? '../' . $package['name'];
            if (!is_string($path)) { return null; }
            $directory = realpath(dirname($installedFile) . '/' . $path);
            if (!is_string($directory) || !str_starts_with($directory, $root . '/vendor/')) { return null; }
            if (str_starts_with($file, $directory . '/') && strlen($directory) > $ownerLength) {
                $owner = $identity;
                $ownerLength = strlen($directory);
            }
            foreach (['psr-4', 'psr-0'] as $kind) {
                foreach (array_keys($identity['autoload'][$kind] ?? []) as $prefix) {
                    if (is_string($prefix) && $prefix !== '' && str_starts_with($match[1], $prefix)) {
                        $providers[] = $identity['name'];
                    }
                }
            }
        }
        if (!is_array($owner)) { return null; }
        $message = sprintf('[build] 缺少 trait %s；引用位置 %s:%s，所属已锁定包 %s %s。', $match[1], $relative, $match[3], $owner['name'], $owner['version']);
        $providers = array_values(array_unique($providers));
        $message .= $providers === []
            ? ' 当前生产 Composer 锁中未找到覆盖此命名空间的已安装 PSR 来源。'
            : ' 已安装命名空间来源：' . implode(', ', $providers) . '；需核对该来源是否实际声明此 trait。';
        $parts = explode('\\', $match[1]);
        $candidate = count($parts) >= 2 ? strtolower($parts[0] . '/' . $parts[1]) : '';
        if ($candidate !== '' && isset($owner['suggest'][$candidate]) && !isset($locked[$candidate])) {
            $message .= sprintf(' 引用包另列可选依赖 %s（%s），这是元数据线索，尚未验证缺失符号归属。', $candidate, $owner['suggest'][$candidate]);
        }
        return $message . ' 原始 TypePHP 错误与退出码保留；工具未自动修改依赖或跳过该来源。';
    }
}
