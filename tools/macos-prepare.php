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
    run([$php, dirname(__DIR__) . '/tools/check-toolchain-capabilities.php', 'php', $php]);

    fwrite(STDERR, "[prepare] Extracting locked TypePHP and PHPX sources\n");
    $host = extractArchive($tar, $archives['typephp-macos-arm64'], $workRoot . '/typephp-host');
    $typephp = extractArchive($tar, $archives['typephp-source'], $workRoot . '/typephp-source');
    run(['/bin/cp', '-R', $host . '/vendor', $typephp . '/vendor']);
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
    if (!is_executable($compiler) || !is_executable($objcopy)) {
        throw new RuntimeException('selected private LLVM is incomplete');
    }

    run([$php, dirname(__DIR__) . '/tools/check-toolchain-capabilities.php', 'cxx', $compiler]);

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
    $approvedSdkDigest = $lock['evidence']['patchedSdk']['sdkSha256']
        ?? 'bc4b4053092176f8e046a5db0b66c659daa4f23468c09c982223d4fea24d7eeb';
    if (!is_string($approvedSdkDigest) || preg_match('/^[a-f0-9]{64}$/D', $approvedSdkDigest) !== 1
        || $sdkDigest !== $approvedSdkDigest) {
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
    exit(1);
}
