<?php

declare(strict_types=1);

function option(array $arguments, string $name): string
{
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, $prefix)) {
            $value = substr($argument, strlen($prefix));
            if ($value !== '') {
                return $value;
            }
        }
    }
    throw new InvalidArgumentException("missing {$prefix}PATH");
}

function run(array $command): string
{
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('unable to start private toolchain command');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || !is_string($stdout) || !is_string($stderr)) {
        throw new RuntimeException(
            'toolchain command failed: ' . basename($command[0]) . ': '
            . substr(trim((string) $stderr . "\n" . (string) $stdout), -2048)
        );
    }
    return $stdout;
}

function singleDirectory(string $parent): string
{
    $entries = array_values(array_filter(
        scandir($parent) ?: [],
        static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
    ));
    if (count($entries) !== 1 || !is_dir($parent . '/' . $entries[0])) {
        throw new RuntimeException("archive must contain exactly one root directory: {$parent}");
    }
    return $parent . '/' . $entries[0];
}

function makeDirectory(string $path): void
{
    if (is_link($path)) {
        throw new RuntimeException("private toolchain directory is a link: {$path}");
    }
    if (is_dir($path)) {
        return;
    }
    if (!mkdir($path, 0700, true)) {
        throw new RuntimeException("unable to create private toolchain directory: {$path}");
    }
}

function composerExecutable(array $arguments): string
{
    $candidates = [];
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--composer=')) {
            $candidates[] = substr($argument, strlen('--composer='));
            break;
        }
    }
    if ($candidates === []) {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
            if ($directory !== '') {
                $candidates[] = $directory . '/composer';
                $candidates[] = $directory . '/composer.phar';
            }
        }
    }
    foreach ($candidates as $candidate) {
        $path = realpath($candidate);
        if (is_string($path) && is_file($path) && is_readable($path) && is_executable($path)) {
            return $path;
        }
    }
    throw new RuntimeException('Composer is required to refresh patched compiler autoload metadata; provide --composer=PATH');
}

final class ComposerAutoloadException extends RuntimeException
{
}

function refreshCompilerAutoload(string $composer, string $typephp, string $workRoot, array $environment): void
{
    fwrite(STDERR, "[prepare] Regenerating patched compiler autoload metadata with Composer\n");
    $environment['COMPOSER_HOME'] = $workRoot . '/composer-home';
    $environment['COMPOSER_DISABLE_NETWORK'] = '1';
    $process = proc_open(
        [$composer, '--no-interaction', '--no-plugins', '--no-scripts', '--no-cache',
            '--working-dir=' . $typephp, 'dump-autoload', '--classmap-authoritative'],
        [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR],
        $pipes,
        $typephp,
        $environment
    );
    if (!is_resource($process)) {
        throw new ComposerAutoloadException('unable to start Composer autoload generation', 1);
    }
    $nextHeartbeat = microtime(true) + 30;
    do {
        $status = proc_get_status($process);
        if ($status['running'] && microtime(true) >= $nextHeartbeat) {
            fwrite(STDERR, "[prepare] Composer autoload generation is still running\n");
            $nextHeartbeat = microtime(true) + 30;
        }
        if ($status['running']) {
            usleep(20000);
        }
    } while ($status['running']);
    $closed = proc_close($process);
    $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
    if ($exit < 0 && $status['signaled']) {
        $exit = 128 + $status['termsig'];
    }
    if ($exit !== 0) {
        throw new ComposerAutoloadException('Composer autoload generation failed with exit ' . $exit, $exit > 0 ? $exit : 1);
    }
    fwrite(STDERR, "[prepare] Patched compiler autoload metadata regenerated successfully\n");
}

function removeVendoredPhpx(string $path, string $workRoot): void
{
    $resolved = realpath($path);
    if (!is_string($resolved) || is_link($path)
        || !str_starts_with($resolved, rtrim($workRoot, '/') . '/typephp-source/')
    ) {
        throw new RuntimeException('vendored PHPX directory is missing or unsafe');
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $pathname = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) {
            if (!rmdir($pathname)) {
                throw new RuntimeException('unable to replace vendored PHPX directory');
            }
        } elseif (!unlink($pathname)) {
            throw new RuntimeException('unable to replace vendored PHPX file');
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException('unable to replace vendored PHPX root');
    }
}

function extractArchive(string $tar, string $archive, string $destination): string
{
    makeDirectory($destination);
    run([$tar, '-xf', $archive, '-C', $destination]);
    return singleDirectory($destination);
}

function prepare(array $arguments): void
{
    if (PHP_OS_FAMILY !== 'Darwin' || php_uname('m') !== 'arm64') {
        throw new RuntimeException('macOS toolchain preparation requires macOS ARM64');
    }
    $artifacts = realpath(option($arguments, 'artifacts'));
    $lockFile = realpath(option($arguments, 'lock'));
    $driver = realpath(option($arguments, 'php'));
    $composer = composerExecutable($arguments);
    $composerEnvironment = getenv();
    $workRoot = option($arguments, 'work-root');
    if (!is_string($artifacts) || !is_string($lockFile) || !is_string($driver)
        || !is_file($driver) || is_link($driver)
        || (file_exists($workRoot) && !is_dir($workRoot))
        || (is_dir($workRoot) && (scandir($workRoot) ?: []) !== ['.', '..'])
    ) {
        throw new RuntimeException('private macOS preparation inputs are missing or work root is not empty');
    }
    if (!is_dir($workRoot)) {
        makeDirectory($workRoot);
    }
    $workRoot = realpath($workRoot);
    if (!is_string($workRoot)) {
        throw new RuntimeException('unable to resolve private work root');
    }
    $contents = file_get_contents($lockFile);
    if (!is_string($contents)) {
        throw new RuntimeException('locked toolchain manifest is unreadable');
    }
    $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($lock) || !is_array($lock['components'] ?? null)) {
        throw new RuntimeException('locked toolchain manifest is invalid');
    }
    $required = [
        'typephp-source',
        'typephp-macos-arm64',
        'typephp-vendor',
        'phpx-source',
        'phpx-sdk-linux-x64',
        'llvm-macos-arm64',
        'alpine-musl-dev-x86-64',
        'alpine-linux-headers-x86-64',
        'alpine-libstdcpp-dev-x86-64',
        'alpine-fortify-headers-x86-64',
        'alpine-gcc-x86-64',
    ];
    $archives = [];
    foreach ($required as $id) {
        $matches = array_values(array_filter(
            $lock['components'],
            static fn (mixed $component): bool => is_array($component)
                && ($component['id'] ?? null) === $id
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException("locked component must occur exactly once: {$id}");
        }
        $component = $matches[0];
        $path = parse_url((string) ($component['sourceUrl'] ?? ''), PHP_URL_PATH);
        $archive = $artifacts . '/' . basename(is_string($path) ? $path : '');
        $digest = is_file($archive) && !is_link($archive) ? hash_file('sha256', $archive) : false;
        if (!is_string($digest) || $digest !== ($component['sha256'] ?? null)) {
            throw new RuntimeException("locked archive is missing or differs: {$id}");
        }
        $archives[$id] = $archive;
    }
    $tar = '/usr/bin/tar';
    if (!is_executable($tar)) {
        throw new RuntimeException('macOS system tar is unavailable');
    }

    $phpHome = $workRoot . '/php-driver';
    makeDirectory($phpHome);
    $php = $phpHome . '/php';
    if (!copy($driver, $php) || !chmod($php, 0700)) {
        throw new RuntimeException('unable to install private PHP driver');
    }
    if (trim(run([$php, '-n', '-r', 'echo PHP_VERSION;'])) !== '8.4.26') {
        throw new RuntimeException('private PHP driver version differs from locked PHP');
    }
    // TypePHP 0.9.5 probes PHP_HOME for the embed SAPI runtime, so stage the
    // driver's install prefix pieces (libphp.a, php-config, headers) alongside
    // the binary instead of shipping the CLI executable alone.
    $driverPrefix = basename(dirname($driver)) === 'bin'
        ? dirname($driver, 2)
        : dirname($driver);
    foreach (['lib/libphp.a' => 'lib/libphp.a', 'bin/php-config' => 'bin/php-config'] as $from => $to) {
        $source = $driverPrefix . '/' . $from;
        if (is_file($source) && !is_link($source)) {
            makeDirectory(dirname($phpHome . '/' . $to));
            if (!copy($source, $phpHome . '/' . $to)) {
                throw new RuntimeException('unable to stage PHP embed runtime file: ' . $from);
            }
            if (str_ends_with($to, 'php-config')) {
                chmod($phpHome . '/' . $to, 0700);
            }
        }
    }
    $include = $driverPrefix . '/include/php';
    if (is_dir($include) && !is_link($include)) {
        makeDirectory($phpHome . '/include');
        run(['/bin/cp', '-R', $include, $phpHome . '/include/php']);
    }
    $stagedConfig = $phpHome . '/bin/php-config';
    if (is_file($stagedConfig)) {
        $config = file_get_contents($stagedConfig);
        if (is_string($config)) {
            $config = preg_replace(
                '/^prefix=.*$/m',
                "prefix='" . str_replace("'", "'\\''", $phpHome) . "'",
                $config
            );
            if (!is_string($config)
                || file_put_contents($stagedConfig, $config, LOCK_EX) === false
            ) {
                throw new RuntimeException('unable to rewrite staged php-config prefix');
            }
        }
    }
    if (!is_file($phpHome . '/lib/libphp.a')) {
        throw new RuntimeException(
            'private PHP driver lacks the embed SAPI static library; build PHP with --enable-embed=static'
        );
    }

    fwrite(STDERR, "[prepare] Extracting locked TypePHP and PHPX sources\n");
    extractArchive($tar, $archives['typephp-macos-arm64'], $workRoot . '/typephp-host');
    $typephp = extractArchive($tar, $archives['typephp-source'], $workRoot . '/typephp-source');
    // The 0.9.5 host package is a bare tpc binary; the compiler's Composer
    // dependencies ship as the separate locked vendor bundle.
    $vendor = extractArchive($tar, $archives['typephp-vendor'], $workRoot . '/typephp-vendor');
    run(['/bin/cp', '-R', $vendor . '/vendor', $typephp . '/vendor']);
    $swoole = $typephp . '/vendor/swoole';
    makeDirectory($swoole);
    $phpxSource = extractArchive($tar, $archives['phpx-source'], $workRoot . '/phpx-source');
    $phpx = $swoole . '/phpx';
    if (file_exists($phpx)) {
        removeVendoredPhpx($phpx, $workRoot);
    }
    if (!rename($phpxSource, $phpx)) {
        throw new RuntimeException('unable to install locked PHPX source');
    }
    fwrite(STDERR, "[prepare] Extracting Linux static SDK\n");
    $sdkSource = extractArchive($tar, $archives['phpx-sdk-linux-x64'], $workRoot . '/sdk-extract');
    makeDirectory($phpx . '/full-static');
    $sdk = $phpx . '/full-static/sdk';
    if (!rename($sdkSource, $sdk)) {
        throw new RuntimeException('unable to install locked Linux static SDK');
    }
    fwrite(STDERR, "[prepare] Extracting private LLVM\n");
    $llvmSource = extractArchive($tar, $archives['llvm-macos-arm64'], $workRoot . '/llvm-extract');
    $llvm = $workRoot . '/llvm';
    if (!rename($llvmSource, $llvm)) {
        throw new RuntimeException('unable to install locked private LLVM');
    }
    $compiler = $llvm . '/bin/clang++';
    $objcopy = $llvm . '/bin/llvm-objcopy';
    if (!is_executable($compiler) || !is_executable($objcopy)
        || preg_match('/^clang version 19\.1\.7(?:\s|$)/', run([$compiler, '--version'])) !== 1
    ) {
        throw new RuntimeException('locked private LLVM is incomplete or has the wrong version');
    }

    fwrite(STDERR, "[prepare] Stripping and verifying static SDK\n");
    $repository = dirname(__DIR__);
    $stripped = json_decode(
        run([
            $php,
            $repository . '/tools/strip-sdk-debug.php',
            "--sdk={$sdk}",
            "--objcopy={$objcopy}",
        ]),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    $sdkDigest = $stripped['sha256'] ?? null;
    if ($sdkDigest !== '4d683d1c5e13f90e59f1486e70683cc3ca52f31b2878087bf212d233371c2623') {
        throw new RuntimeException('stripped private SDK digest differs from locked cross-host SDK');
    }
    $phprc = $workRoot . '/php-config';
    makeDirectory($phprc);
    putenv("PHP_HOME={$phpHome}");
    putenv("PHPX_HOME={$phpx}");
    putenv("PHPRC={$phprc}");
    putenv('PATH=' . $phpHome . ':' . $llvm . '/bin:' . (getenv('PATH') ?: ''));
    fwrite(STDERR, "[prepare] Applying guarded compiler patches\n");
    run([
        $php,
        $repository . '/tools/apply-typephp-patches.php',
        "--typephp={$typephp}",
        "--phpx={$phpx}",
    ]);
    // Preserve Composer's installed interpreter, before the compiler PATH override.
    refreshCompilerAutoload($composer, $typephp, $workRoot, $composerEnvironment);

    fwrite(STDERR, "[prepare] Assembling locked Linux sysroot\n");
    $sysroot = $workRoot . '/sysroot';
    run([
        $php,
        $repository . '/tools/assemble-sysroot.php',
        "--artifacts={$artifacts}",
        "--output={$sysroot}",
        "--tar={$tar}",
        "--lock={$lockFile}",
    ]);
    $relative = static function (string $path) use ($workRoot): string {
        $prefix = rtrim($workRoot, '/') . '/';
        if (!str_starts_with($path, $prefix)) {
            throw new RuntimeException('prepared tool path escaped the work root');
        }
        return substr($path, strlen($prefix));
    };
    $prepared = [
        'schema' => 'webman-aot-builder-prepared-toolchain-v1',
        'host' => 'macos-arm64',
        'lockSha256' => hash('sha256', $contents),
        'php' => $relative($php),
        'typephp' => $relative($typephp),
        'phpx' => $relative($phpx),
        'compiler' => $relative($compiler),
        'objcopy' => $relative($objcopy),
        'sysroot' => $relative($sysroot),
        'phprc' => $relative($phprc),
        'sdkSha256' => $sdkDigest,
    ];
    $encoded = json_encode($prepared, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($workRoot . '/prepared-toolchain.json', $encoded . "\n", LOCK_EX) === false) {
        throw new RuntimeException('unable to write prepared macOS toolchain manifest');
    }
    echo $encoded, PHP_EOL;
}

try {
    prepare(array_slice($argv, 1));
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . PHP_EOL);
    exit($exception instanceof ComposerAutoloadException ? $exception->getCode() : 1);
}
