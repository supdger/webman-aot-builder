<?php

declare(strict_types=1);

/**
 * Build a host-specific, source-verified toolchain component from an already
 * prepared generation. The resulting ZIP is shared by small and full installers.
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/build-minimal-component.php GENERATION OUTPUT.zip [--prepared-root=PATH] [--artifacts-root=PATH] [--compiler-source-root=PATH]\n");
    exit(64);
}

function componentInputRoots(array $arguments, string $generation): array
{
    $roots = ['prepared-root' => $generation . '/prepared', 'artifacts-root' => $generation . '/artifacts'];
    $seen = [];
    foreach ($arguments as $argument) {
        if (preg_match('/^--(prepared-root|artifacts-root|compiler-source-root)=(.+)$/D', $argument, $match) !== 1
            || isset($seen[$match[1]])
        ) {
            throw new InvalidArgumentException('unknown or repeated minimal component input option: ' . $argument);
        }
        $path = $match[2];
        $canonical = realpath($path);
        if (!is_string($canonical) || !is_dir($canonical) || is_link($path)
            || $canonical !== rtrim($path, DIRECTORY_SEPARATOR)
        ) {
            throw new InvalidArgumentException('minimal component input root must be a canonical directory: ' . $path);
        }
        $roots[$match[1]] = $canonical;
        $seen[$match[1]] = true;
    }
    return $roots;
}

function componentPreparedEntry(string $path, string $prepared): string
{
    $prefix = str_replace('\\', '/', rtrim($prepared, '/\\')) . '/';
    $normalized = str_replace('\\', '/', $path);
    if (!str_starts_with($normalized, $prefix)) {
        throw new RuntimeException('prepared component entry escaped its declared input root');
    }
    return 'prepared/' . substr($normalized, strlen($prefix));
}

$generation = realpath($argv[1]);
$output = $argv[2];
if (!is_string($generation)
    || !str_ends_with(strtolower($output), '.zip')
) {
    throw new RuntimeException('prepared generation or output ZIP is invalid');
}
$inputRoots = componentInputRoots(array_slice($argv, 3), $generation);
$prepared = $inputRoots['prepared-root'];
$artifacts = $inputRoots['artifacts-root'];
if (!is_dir($prepared) || !is_dir($artifacts)) {
    throw new RuntimeException('prepared generation input directories are missing');
}
$root = dirname(__DIR__);
$lockContents = file_get_contents($generation . '/toolchain.lock.json');
$generationContents = file_get_contents($generation . '/manifest.json');
$lock = is_string($lockContents) ? json_decode($lockContents, true, flags: JSON_THROW_ON_ERROR) : null;
$generationManifest = is_string($generationContents)
    ? json_decode($generationContents, true, flags: JSON_THROW_ON_ERROR)
    : null;
$host = is_array($generationManifest) ? ($generationManifest['host'] ?? null) : null;
if (!is_array($lock) || !in_array($host, ['macos-arm64', 'windows-x86_64'], true)
    || hash_file('sha256', $root . '/toolchain.lock.json')
        !== hash_file('sha256', $generation . '/toolchain.lock.json')
) {
    throw new RuntimeException('generation host or toolchain lock differs from this source');
}

$selected = [];
$sourceArchives = [];
$archiveDigests = [];
$generatorFound = false;
foreach ($lock['components'] ?? [] as $component) {
    if (!is_array($component)) {
        throw new RuntimeException('locked component is malformed');
    }
    $id = (string) ($component['id'] ?? '');
    if ((str_contains($id, '-macos-') && $host !== 'macos-arm64')
        || (str_contains($id, '-windows-') && $host !== 'windows-x86_64')
    ) {
        continue;
    }
    $urlPath = parse_url((string) ($component['sourceUrl'] ?? ''), PHP_URL_PATH);
    $name = is_string($urlPath) ? basename($urlPath) : '';
    $path = $artifacts . '/' . $name;
    $digest = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
    if ($name === '' || !is_string($digest)
        || !hash_equals((string) ($component['sha256'] ?? ''), $digest)
    ) {
        throw new RuntimeException("original source artifact is missing or corrupt: {$id}");
    }
    $sourceArchives[$id] = $path;
    $archiveDigests[$id] = $digest;
    if ($id === 'webman-typephp-generator-source') {
        $selected['artifacts/' . $name] = $path;
        $generatorFound = true;
    }
    fwrite(STDERR, "[source] Verified {$id}\n");
}
if (!$generatorFound) {
    throw new RuntimeException('locked Webman generator archive is missing');
}

$composition = null;
$compilerSource = $inputRoots['compiler-source-root'] ?? null;
if (is_string($compilerSource)) {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'WebmanAotBuilder\\';
        if (str_starts_with($class, $prefix)) {
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require $file; }
        }
    });
    fwrite(STDERR, "[compose] Verifying complete compiler source and prepared runtime compatibility\n");
    $sourceVerifier = new WebmanAotBuilder\Toolchain\CompilerComponentSource();
    $composition = $sourceVerifier->verify($compilerSource, $prepared, $sourceArchives,
        $root . '/toolchain/patches/typephp/0.9.5/manifest.json');
    fwrite(STDERR, "[compose] Verified complete source, maintenance rules, runtime and authoritative Composer map\n");
}

foreach (['manifest.json', 'toolchain.lock.json'] as $relative) {
    $selected[$relative] = $generation . '/' . $relative;
}
$selected['prepared/prepared-toolchain.json'] = $prepared . '/prepared-toolchain.json';
$selected['prepared/php-config'] = $prepared . '/php-config';
if (!is_dir($selected['prepared/php-config']) || is_link($selected['prepared/php-config'])) {
    throw new RuntimeException('prepared PHP configuration directory is missing or unsafe');
}
foreach (['php-driver', 'sysroot', 'typephp-source'] as $directory) {
    $path = $prepared . '/' . $directory;
    if (!is_dir($path) || is_link($path)) {
        throw new RuntimeException("prepared directory is missing or unsafe: {$directory}");
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $item) {
        $relative = componentPreparedEntry($item->getPathname(), $prepared);
        if ($relative === 'prepared/sysroot/usr/lib/bfd-plugins/liblto_plugin.so') {
            if (!$item->isLink()
                || readlink($item->getPathname())
                    !== '//usr/libexec/gcc/x86_64-alpine-linux-musl/12.2.1/liblto_plugin.so'
            ) {
                throw new RuntimeException('locked sysroot bfd plugin link drifted');
            }
            continue;
        }
        if ($directory === 'typephp-source'
            && preg_match(
                '~^prepared/typephp-source/[^/]+/(?:benchmark|docs|examples|phpunit|tests|wasm)(?:/|$)~D',
                $relative
            ) === 1
        ) {
            continue;
        }
        if ($composition !== null && $directory === 'typephp-source') {
            $oldPrefix = 'prepared/' . substr($composition['oldCompilerRoot'], strlen($prepared) + 1) . '/';
            // The entire prepared SDK remains the runtime input; compiler source
            // replacement never replaces its libraries, headers or metadata.
            if (!str_starts_with($relative, $oldPrefix . 'vendor/swoole/phpx/full-static/sdk/')) { continue; }
        }
        if ($item->isFile() || $item->isLink()) {
            $selected[$relative] = $item->getPathname();
        }
    }
}

$compositionJson = null;
$inlineEntries = [];
if ($composition !== null) {
    $compilerEntry = 'prepared/' . substr($composition['oldCompilerRoot'], strlen($prepared) + 1) . '/';
    foreach ($composition['files'] as $relative => $_) {
        if (!str_starts_with($relative, 'vendor/swoole/phpx/full-static/sdk/')) {
            $selected[$compilerEntry . $relative] = $compilerSource . '/' . $relative;
        }
    }
    $compositionMetadata = $composition;
    unset($compositionMetadata['files'], $compositionMetadata['runtimeFiles'], $compositionMetadata['oldCompilerRoot']);
    $compositionJson = json_encode($compositionMetadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $preparedMetadata = json_decode(file_get_contents($prepared . '/prepared-toolchain.json'), true, flags: JSON_THROW_ON_ERROR);
    $preparedMetadata['compilerSourceProvenanceSha256'] = hash('sha256', $compositionJson);
    $inlineEntries['prepared/prepared-toolchain.json'] = json_encode($preparedMetadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

$llvmRelative = $host === 'macos-arm64'
    ? 'llvm'
    : 'llvm-payload/clang+llvm-19.1.7-x86_64-pc-windows-msvc';
$llvm = $prepared . '/' . $llvmRelative;
if (!is_dir($llvm) || is_link($llvm)) {
    throw new RuntimeException('prepared LLVM directory is missing or unsafe');
}
$llvmTools = $host === 'macos-arm64'
    ? ['clang++', 'clang', 'clang-19', 'ld.lld', 'lld', 'llvm-nm', 'llvm-objcopy']
    : ['clang++.exe', 'clang.exe', 'ld.lld.exe', 'lld.exe', 'lld-link.exe', 'llvm-ar.exe',
        'llvm-nm.exe', 'llvm-objcopy.exe', 'llvm-ranlib.exe'];
foreach ($llvmTools as $name) {
    $path = $llvm . '/bin/' . $name;
    if ((!is_file($path) && !is_link($path)) || is_dir($path)) {
        throw new RuntimeException("required LLVM tool is missing: {$name}");
    }
    $selected['prepared/' . $llvmRelative . '/bin/' . $name] = $path;
}
$clangResources = $llvm . '/lib/clang/19';
if (!is_dir($clangResources) || is_link($clangResources)) {
    throw new RuntimeException('locked Clang resource directory is missing');
}
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($clangResources, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($iterator as $item) {
    if ($item->isFile() || $item->isLink()) {
        $relative = componentPreparedEntry($item->getPathname(), $prepared);
        $selected[$relative] = $item->getPathname();
    }
}
ksort($selected, SORT_STRING);

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    throw new RuntimeException('cannot create minimal component ZIP; output may already exist');
}
$entries = [];
$omittedLinks = [];
$projectionFailure = null;
try {
    $done = 0;
    $total = count($selected);
    foreach ($selected as $relative => $path) {
        $done++;
        if (isset($inlineEntries[$relative])) {
            $contents = $inlineEntries[$relative];
            if (!$zip->addFromString($relative, $contents)) { throw new RuntimeException('cannot add composed prepared metadata'); }
            $entries[$relative] = ['type'=>'file', 'sha256'=>hash('sha256', $contents), 'size'=>strlen($contents), 'executable'=>false];
            continue;
        }
        if (is_dir($path) && !is_link($path)) {
            $entries[$relative] = ['type' => 'directory'];
            continue;
        }
        if (is_link($path)) {
            $target = readlink($path);
            if (!is_string($target) || $target === '' || str_starts_with($target, '/')
                || str_contains($target, '\\')
            ) {
                throw new RuntimeException("unsafe component symlink: {$relative}");
            }
            $segments = explode('/', dirname($relative) . '/' . $target);
            $resolved = [];
            foreach ($segments as $segment) {
                if ($segment === '.' || $segment === '') {
                    continue;
                }
                if ($segment === '..') {
                    if ($resolved === []) {
                        throw new RuntimeException("component symlink escapes the bundle: {$relative}");
                    }
                    array_pop($resolved);
                    continue;
                }
                $resolved[] = $segment;
            }
            $resolvedPath = implode('/', $resolved);
            $targetBundled = isset($selected[$resolvedPath]);
            if (!$targetBundled) {
                foreach ($selected as $candidate => $_) {
                    if (str_starts_with($candidate, $resolvedPath . '/')) {
                        $targetBundled = true;
                        break;
                    }
                }
            }
            if (!$targetBundled && realpath($path) === false) {
                $omittedLinks[$relative] = $target;
                fwrite(STDERR, "[bundle] Omitted dangling upstream link: {$relative} -> {$target}\n");
                continue;
            }
            if (!$targetBundled) {
                throw new RuntimeException("component symlink target is not bundled: {$relative}");
            }
            $entries[$relative] = ['type' => 'link', 'target' => $target];
            continue;
        }
        $digest = hash_file('sha256', $path);
        $size = filesize($path);
        if (!is_string($digest) || !is_int($size)) {
            throw new RuntimeException("cannot add minimal component file: {$relative}");
        }
        $added = $zip->addFile($path, $relative);
        if (!$added && PHP_OS_FAMILY === 'Windows' && $size <= 16 * 1048576) {
            $contents = file_get_contents($path);
            $added = is_string($contents) && $zip->addFromString($relative, $contents);
        }
        if (!$added) {
            throw new RuntimeException("cannot add minimal component file: {$relative}");
        }
        $entries[$relative] = [
            'type' => 'file',
            'sha256' => $digest,
            'size' => $size,
            'executable' => is_executable($path),
        ];
        if ($done % 250 === 0 || $done === $total) {
            fwrite(STDERR, "[bundle] Added {$done}/{$total} entries\n");
        }
    }
    if ($compositionJson !== null) {
        $relative = 'prepared/compiler-source-provenance.json';
        if (!$zip->addFromString($relative, $compositionJson)) {
            throw new RuntimeException('cannot add compiler source provenance');
        }
        $entries[$relative] = ['type' => 'file', 'sha256' => hash('sha256', $compositionJson),
            'size' => strlen($compositionJson), 'executable' => false];
    }
    $manifest = [
        'schema' => 'webman-aot-builder-minimal-component-v1',
        'host' => $host,
        'toolchainLockSha256' => hash_file('sha256', $generation . '/toolchain.lock.json'),
        'entries' => $entries,
        'omittedDanglingLinks' => $omittedLinks,
    ];
    if ($compositionJson !== null) { $manifest['compilerSourceProvenanceSha256'] = hash('sha256', $compositionJson); }
    $manifestJson = json_encode(
        $manifest,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . "\n";
    if (!$zip->addFromString('minimal-component.json', $manifestJson)) {
        throw new RuntimeException('cannot add minimal component manifest');
    }
} catch (Throwable $error) {
    $projectionFailure = $error;
    throw $error;
} finally {
    $closed = $zip->close();
    if ($composition !== null && !$closed && $projectionFailure === null) {
        throw new RuntimeException('composed component ZIP could not be finalized');
    }
}
if ($composition !== null) {
    $sourceVerifier->unchanged($compilerSource, $composition['files']);
    $sourceVerifier->unchanged($composition['oldCompilerRoot'] . '/vendor/swoole/phpx', $composition['runtimeFiles'], true);
    foreach ($sourceArchives as $id => $path) {
        if (hash_file('sha256', $path) !== $archiveDigests[$id]) {
            throw new RuntimeException('locked source archive changed during compiler composition');
        }
    }
}
$bundleDigest = hash_file('sha256', $output);
$bundleSize = filesize($output);
if (!is_string($bundleDigest) || !is_int($bundleSize)) {
    throw new RuntimeException('cannot inspect generated component ZIP');
}
fwrite(STDOUT, json_encode([
    'host' => $host,
    'path' => $output,
    'sha256' => $bundleDigest,
    'size' => $bundleSize,
    'files' => count($entries),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
