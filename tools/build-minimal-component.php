<?php

declare(strict_types=1);

/**
 * Build a host-specific, source-verified toolchain component from an already
 * prepared generation. The resulting ZIP is shared by small and full installers.
 */

/** Create a new generation using a locked released component, not local caches. */
function reuseComponent(array $arguments, string $repository): array
{
    $options = [];
    foreach (array_slice($arguments, 3) as $argument) {
        if (!preg_match('/^--(reuse|typephp|sdk)=(.+)$/D', $argument, $match)
            || isset($options[$match[1]])) {
            throw new RuntimeException('Unknown or duplicate component reuse option');
        }
        $options[$match[1]] = $match[2];
    }
    if (count($options) !== 3) {
        throw new RuntimeException('Reuse requires --reuse=ZIP --typephp=SOURCE --sdk=TAR.XZ');
    }
    $lock = json_decode(file_get_contents($repository . '/toolchain.lock.json'), true, flags: JSON_THROW_ON_ERROR);
    $zip = new ZipArchive();
    if (!is_file($options['reuse']) || is_link($options['reuse']) || $zip->open($options['reuse']) !== true) {
        throw new RuntimeException('Predecessor component archive is missing');
    }
    try {
        $json = $zip->getFromName('minimal-component.json');
        $manifest = is_string($json) ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : null;
        $host = $manifest['host'] ?? null;
        $policy = $lock['evidence']['patchedSdk']['componentInputs'][$host ?? ''] ?? null;
        if (!is_array($policy) || !is_string($json)
            || hash_file('sha256', $options['reuse']) !== ($policy['sha256'] ?? null)
            || hash('sha256', $json) !== ($policy['manifestSha256'] ?? null)
            || ($manifest['toolchainLockSha256'] ?? null) !== ($policy['toolchainLockSha256'] ?? null)) {
            throw new RuntimeException('Predecessor differs from the approved released component');
        }
    } finally {
        $zip->close();
    }
    spl_autoload_register(static function (string $class) use ($repository): void {
        $prefix = 'WebmanAotBuilder\\';
        if (str_starts_with($class, $prefix)) {
            require $repository . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });
    $lockErrors = (new WebmanAotBuilder\Toolchain\LockValidator())->validate($lock);
    if ($lockErrors !== []) { throw new RuntimeException('Component reuse lock is invalid: ' . implode('; ', $lockErrors)); }
    $component = new WebmanAotBuilder\Toolchain\MinimalComponent();
    fwrite(STDERR, "[reuse] Verify every predecessor file, link and executable mode\n");
    $component->extract($options['reuse'], $policy['sha256'], $arguments[1], $host,
        $policy['toolchainLockSha256'], static fn (string $message) => fwrite(STDERR, "[reuse] {$message}\n"));
    $generation = realpath($arguments[1]);
    $predecessorLock = json_decode(file_get_contents($generation . '/toolchain.lock.json'), true, flags: JSON_THROW_ON_ERROR);
    $comparison = $lock;
    unset($comparison['evidence']['patchedSdk']);
    $predecessorComparison = $predecessorLock;
    unset($predecessorComparison['evidence']['patchedSdk']);
    foreach ($comparison['components'] as &$componentDefinition) {
        if ($componentDefinition['id'] === 'phpx-sdk-linux-x64') {
            foreach ($predecessorLock['components'] as $previousDefinition) {
                if ($previousDefinition['id'] === 'phpx-sdk-linux-x64') { $componentDefinition = $previousDefinition; }
            }
        }
    }
    unset($componentDefinition);
    $coveredIds = ['openssl', 'curl', 'libzip', 'icu', 'libpq', 'zlib', 'libxml2', 'oniguruma'];
    $seenCovered = [];
    $oldSdkDigest = null;
    foreach ($predecessorLock['components'] as $definition) {
        if ($definition['id'] === 'phpx-sdk-linux-x64') { $oldSdkDigest = $definition['sha256']; }
    }
    $newSdkDigest = null;
    foreach ($lock['components'] as $definition) {
        if ($definition['id'] === 'phpx-sdk-linux-x64') { $newSdkDigest = $definition['sha256']; }
    }
    foreach ($comparison['embeddedLibraries'] as &$componentDefinition) {
        if (($componentDefinition['coveredBy'] ?? null) === 'phpx-sdk-linux-x64') {
            if (!in_array($componentDefinition['id'], $coveredIds, true)
                || $componentDefinition['artifactSha256'] !== $newSdkDigest) {
                throw new RuntimeException('Embedded library derived SDK binding drifted');
            }
            $seenCovered[] = $componentDefinition['id'];
            foreach ($predecessorLock['embeddedLibraries'] as $previousDefinition) {
                if ($previousDefinition['id'] === $componentDefinition['id']) {
                    if ($previousDefinition['artifactSha256'] !== $oldSdkDigest) {
                        throw new RuntimeException('Predecessor embedded SDK binding drifted');
                    }
                    $componentDefinition['artifactSha256'] = $previousDefinition['artifactSha256'];
                }
            }
        }
    }
    unset($componentDefinition);
    sort($coveredIds); sort($seenCovered);
    if ($coveredIds !== $seenCovered || $comparison !== $predecessorComparison) {
        throw new RuntimeException('Component reuse cannot change other locked toolchain inputs');
    }
    $prepared = json_decode(file_get_contents($generation . '/prepared/prepared-toolchain.json'), true, flags: JSON_THROW_ON_ERROR);
    $typephp = $generation . '/prepared/' . $prepared['typephp'];
    $source = realpath($options['typephp']);
    (new WebmanAotBuilder\Toolchain\TypePhpPatchSourceVerifier())->verify(
        $options['typephp'], $repository . '/toolchain/patches/typephp/0.9.2/manifest.json');
    $rules = json_decode(file_get_contents($repository . '/toolchain/patches/typephp/0.9.2/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $replacements = [];
    foreach ($rules['rules'] as $rule) {
        $target = $typephp . '/' . $rule['path'];
        if (is_link($target) || !is_dir(dirname($target))) {
            throw new RuntimeException('Approved source replacement has an unsafe parent');
        }
        if (!copy($source . '/' . $rule['path'], $target)) {
            throw new RuntimeException('Cannot install approved TypePHP source');
        }
        $replacements[substr($target, strlen($generation) + 1)] = $rule['afterSha256'];
    }
    (new WebmanAotBuilder\Toolchain\TypePhpPatchSourceVerifier())->verify(
        $typephp, $repository . '/toolchain/patches/typephp/0.9.2/manifest.json');
    $sdkLock = null;
    foreach ($lock['components'] as $entry) {
        if ($entry['id'] === 'phpx-sdk-linux-x64') { $sdkLock = $entry; }
    }
    if (!is_array($sdkLock) || !is_file($options['sdk']) || is_link($options['sdk'])
        || hash_file('sha256', $options['sdk']) !== $sdkLock['sha256']) {
        throw new RuntimeException('Derived SDK archive differs from the approved lock');
    }
    $sdk = $generation . '/prepared/' . $prepared['phpx'] . '/full-static/sdk';
    $sdkPrefix = substr($sdk, strlen($generation) + 1) . '/';
    $staging = $generation . '-sdk-input';
    if (file_exists($staging) || !mkdir($staging, 0700)) { throw new RuntimeException('SDK staging must be new'); }
    fwrite(STDERR, "[reuse] Extract the approved derived SDK archive\n");
    $tar = PHP_OS_FAMILY === 'Windows' ? 'tar.exe' : '/usr/bin/tar';
    $process = proc_open([$tar, '-xf', $options['sdk'], '-C', $staging], [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) { throw new RuntimeException('Derived SDK extraction failed'); }
    $sdkRoot = $staging . '/' . $lock['evidence']['patchedSdk']['archiveRoot'];
    if (!is_dir($sdkRoot) || is_link($sdkRoot)
        || !rename($sdk, $generation . '-predecessor-sdk') || !rename($sdkRoot, $sdk)) {
        throw new RuntimeException('Cannot replace the private derived SDK');
    }
    $guard = new WebmanAotBuilder\Toolchain\SdkArchiveGuard();
    $guard->assertDerivation($sdk, $lock['evidence']['patchedSdk']);
    if ((new WebmanAotBuilder\Toolchain\StaticSdkFingerprint())->digest($sdk)
        !== $lock['evidence']['patchedSdk']['sdkSha256']) {
        throw new RuntimeException('Derived SDK fingerprint differs');
    }
    foreach ($manifest['entries'] as $relative => $entry) {
        if (str_starts_with($relative, $sdkPrefix) || isset($replacements[$relative])) { continue; }
        $path = $generation . '/' . $relative;
        if ($entry['type'] === 'file' && (!is_file($path) || is_link($path)
            || hash_file('sha256', $path) !== $entry['sha256'] || filesize($path) !== $entry['size']
            || is_executable($path) !== $entry['executable'])) {
            throw new RuntimeException('Unapproved predecessor file changed: ' . $relative);
        }
        if ($entry['type'] === 'link' && (!is_link($path) || readlink($path) !== $entry['target'])) {
            throw new RuntimeException('Unapproved predecessor link changed: ' . $relative);
        }
    }
    copy($repository . '/toolchain.lock.json', $generation . '/toolchain.lock.json');
    $generationData = json_decode(file_get_contents($generation . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $generationData['lockSha256'] = hash_file('sha256', $repository . '/toolchain.lock.json');
    $generationData['generation'] = basename($generation);
    foreach ($generationData['components'] as &$entry) {
        if ($entry['id'] === 'phpx-sdk-linux-x64') {
            $entry['version'] = $sdkLock['version'];
            $entry['artifact'] = basename(parse_url($sdkLock['sourceUrl'], PHP_URL_PATH));
            $entry['sha256'] = $sdkLock['sha256'];
        }
    }
    unset($entry);
    $prepared['lockSha256'] = $generationData['lockSha256'];
    $prepared['sdkSha256'] = $lock['evidence']['patchedSdk']['sdkSha256'];
    foreach (['manifest.json' => $generationData, 'prepared/prepared-toolchain.json' => $prepared] as $path => $data) {
        file_put_contents($generation . '/' . $path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    $ledger = ['schema' => 'webman-aot-builder-component-derivation-v1', 'host' => $host,
        'predecessor' => $policy, 'sourceReplacements' => $replacements,
        'patchManifestSha256' => hash_file('sha256', $repository . '/toolchain/patches/typephp/0.9.2/manifest.json'),
        'sdkArchiveSha256' => $sdkLock['sha256'], 'sdkSha256' => $prepared['sdkSha256'],
        'toolchainLockSha256' => $prepared['lockSha256']];
    file_put_contents($generation . '/component-derivation.json', json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    fwrite(STDERR, "[reuse] Predecessor verified; approved source and complete SDK replaced\n");
    return $ledger;
}

$reuse = $argc === 6 ? reuseComponent($argv, dirname(__DIR__)) : null;

if ($argc !== 3 && $argc !== 6) {
    fwrite(STDERR, "Usage: php tools/build-minimal-component.php GENERATION OUTPUT.zip [--reuse=OLD.zip --typephp=SOURCE --sdk=NEW.tar.xz]\n");
    exit(64);
}

$generation = realpath($argv[1]);
$output = $argv[2];
if (!is_string($generation) || !is_dir($generation . '/prepared')
    || !str_ends_with(strtolower($output), '.zip')
) {
    throw new RuntimeException('prepared generation or output ZIP is invalid');
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
    $path = $generation . '/artifacts/' . $name;
    $digest = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
    if ($reuse !== null && $id !== 'webman-typephp-generator-source') {
        continue;
    }
    if ($name === '' || !is_string($digest)
        || !hash_equals((string) ($component['sha256'] ?? ''), $digest)
    ) {
        throw new RuntimeException("original source artifact is missing or corrupt: {$id}");
    }
    if ($id === 'webman-typephp-generator-source') {
        $selected['artifacts/' . $name] = $path;
        $generatorFound = true;
    }
    fwrite(STDERR, "[source] Verified {$id}\n");
}
if (!$generatorFound) {
    throw new RuntimeException('locked Webman generator archive is missing');
}

if ($reuse !== null) { $selected['component-derivation.json'] = $generation . '/component-derivation.json'; }
foreach (['manifest.json', 'toolchain.lock.json', 'prepared/prepared-toolchain.json'] as $relative) {
    $selected[$relative] = $generation . '/' . $relative;
}
$prepared = $generation . '/prepared';
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
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($generation) + 1));
        if ($relative === 'prepared/sysroot/usr/lib/bfd-plugins/liblto_plugin.so') {
            if (!$item->isLink()
                || preg_match('~^//usr/libexec/gcc/x86_64-alpine-linux-musl/[^/]+/liblto_plugin\.so$~D', (string) readlink($item->getPathname())) !== 1
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
        if ($item->isFile() || $item->isLink()) {
            $selected[$relative] = $item->getPathname();
        }
    }
}

$llvmRelative = 'llvm';
if ($host !== 'macos-arm64') {
    $roots = glob($prepared . '/llvm-payload/*', GLOB_ONLYDIR) ?: [];
    if (count($roots) !== 1 || is_link($roots[0])) { throw new RuntimeException('prepared LLVM needs one safe payload root'); }
    $llvmRelative = 'llvm-payload/' . basename($roots[0]);
}
$llvm = $prepared . '/' . $llvmRelative;
if (!is_dir($llvm) || is_link($llvm)) {
    throw new RuntimeException('prepared LLVM directory is missing or unsafe');
}
$llvmTools = $host === 'macos-arm64'
    ? ['clang++', 'clang', 'ld.lld', 'lld', 'llvm-nm', 'llvm-objcopy']
    : ['clang++.exe', 'clang.exe', 'ld.lld.exe', 'lld.exe', 'lld-link.exe', 'llvm-ar.exe',
        'llvm-nm.exe', 'llvm-objcopy.exe', 'llvm-ranlib.exe'];
foreach ($llvmTools as $name) {
    $path = $llvm . '/bin/' . $name;
    if ((!is_file($path) && !is_link($path)) || is_dir($path)) {
        throw new RuntimeException("required LLVM tool is missing: {$name}");
    }
    $selected['prepared/' . $llvmRelative . '/bin/' . $name] = $path;
}
$resourceRoots = glob($llvm . '/lib/clang/*', GLOB_ONLYDIR) ?: [];
if (count($resourceRoots) !== 1) { throw new RuntimeException('selected LLVM needs one resource directory'); }
$clangResources = $resourceRoots[0];
if (!is_dir($clangResources) || is_link($clangResources)) {
    throw new RuntimeException('locked Clang resource directory is missing');
}
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($clangResources, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($iterator as $item) {
    if ($item->isFile() || $item->isLink()) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($generation) + 1));
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
try {
    $done = 0;
    $total = count($selected);
    foreach ($selected as $relative => $path) {
        $done++;
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
    $manifest = [
        'schema' => 'webman-aot-builder-minimal-component-v1',
        'host' => $host,
        'toolchainLockSha256' => hash_file('sha256', $generation . '/toolchain.lock.json'),
        'entries' => $entries,
        'omittedDanglingLinks' => $omittedLinks,
    ];
    $manifestJson = json_encode(
        $manifest,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . "\n";
    if (!$zip->addFromString('minimal-component.json', $manifestJson)) {
        throw new RuntimeException('cannot add minimal component manifest');
    }
} finally {
    $zip->close();
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
