#!/usr/bin/env php
<?php

declare(strict_types=1);

// macOS producer: rebuild the Linux runtime from guarded PHPX source and the original SDK.
// This producer tool is separate from installation: consumers extract the derived SDK.
use WebmanAotBuilder\Cli\ProcessOutput;
use WebmanAotBuilder\Toolchain\StaticSdkFingerprint;
use WebmanAotBuilder\Toolchain\TypePhpPatchSourceVerifier;

$repository = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($repository): void {
    if (str_starts_with($class, 'WebmanAotBuilder\\')) {
        require $repository . '/src/' . str_replace('\\', '/', substr($class, 17)) . '.php';
    }
});
function sdkRun(array $command, ?string $directory = null): void
{
    $exit = ProcessOutput::run($command, $directory, null, ['file', '/dev/null', 'r'],
        static function (int $channel, string $chunk): void { fwrite(STDERR, $chunk); fflush(STDERR); },
        static function (float $elapsed, float $silent): void {
            fwrite(STDERR, sprintf("[sdk] command active %.0fs; no output %.0fs; progress unknown\n", $elapsed, $silent));
        });
    if ($exit !== 0) { throw new RuntimeException('SDK producer command failed; exit ' . $exit); }
}
function sdkCopy(string $source, string $target): void
{
    if (is_link($source)) {
        $link = readlink($source);
        if (!is_string($link) || str_starts_with($link, '/') || str_contains($link, '..')) {
            throw new RuntimeException('unsafe SDK input symlink');
        }
        if (!symlink($link, $target)) { throw new RuntimeException('cannot copy SDK symlink'); }
    } elseif (is_dir($source)) {
        if (is_link($target) || (!is_dir($target) && !mkdir($target, 0755))) {
            throw new RuntimeException('cannot create SDK output directory');
        }
        foreach (new DirectoryIterator($source) as $entry) {
            if (!$entry->isDot()) { sdkCopy($entry->getPathname(), $target . '/' . $entry->getFilename()); }
        }
    } elseif (!copy($source, $target)) { throw new RuntimeException('cannot copy SDK input'); }
}

function sdkTree(string $root, array $ignored = []): array
{
    $entries = [];
    $visit = static function (string $directory, string $prefix) use (&$visit, &$entries, $root, $ignored): void {
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) { continue; }
            $path = $prefix . $entry->getFilename();
            foreach ($ignored as $skip) { if ($path === $skip || str_starts_with($path, $skip . '/')) { continue 2; } }
            if ($entry->isLink()) {
                $target = readlink($entry->getPathname()); $resolved = realpath($entry->getPathname());
                if (!is_string($target) || str_starts_with($target, '/') || !is_string($resolved) || !str_starts_with($resolved, $root . '/')) {
                    throw new RuntimeException('Source tree contains an unsafe or dangling link: ' . $path);
                }
                $entries[$path] = ['type' => 'link', 'target' => $target];
            } elseif ($entry->isDir()) { $visit($entry->getPathname(), $path . '/'); }
            elseif ($entry->isFile()) { $entries[$path] = ['type' => 'file', 'sha256' => hash_file('sha256', $entry->getPathname())]; }
            else { throw new RuntimeException('Source tree contains a special file: ' . $path); }
        }
    };
    $visit($root, ''); ksort($entries, SORT_STRING); return $entries;
}
function sdkVerifySource(string $baseline, string $candidate, array $rules, string $rulePrefix, array $ignored): string
{
    $original = sdkTree($baseline, $ignored); $actual = sdkTree($candidate, $ignored);
    foreach ($rules as $rule) {
        if (!str_starts_with($rule['path'], $rulePrefix)) { continue; }
        $path = substr($rule['path'], strlen($rulePrefix));
        if ($rulePrefix === '' && str_starts_with($path, 'vendor/')) { continue; }
        if (array_key_exists('added', $rule)) {
            if ($rule['added'] !== true || $rule['beforeSha256'] !== hash('sha256', '')
                || array_key_exists('preparedBeforeSha256', $rule) || array_key_exists($path, $original)) {
                throw new RuntimeException('Added patch source must be absent from official source: ' . $rule['path']);
            }
            $original[$path] = ['type' => 'file', 'sha256' => $rule['afterSha256']];
        } else {
            if (($original[$path]['sha256'] ?? null) !== $rule['beforeSha256']) {
                throw new RuntimeException('Patch before hash differs from official source: ' . $rule['path']);
            }
            $original[$path]['sha256'] = $rule['afterSha256'];
        }
    }
    if ($original !== $actual) {
        foreach (array_unique([...array_keys($original), ...array_keys($actual)]) as $path) {
            if (($original[$path] ?? null) !== ($actual[$path] ?? null)) { throw new RuntimeException('Unapproved whole-source drift: ' . $rulePrefix . $path); }
        }
    }
    return hash('sha256', json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
function sdkApplySourceRules(string $targetRoot, string $candidateRoot, array $rules): void
{
    // Check the complete source identity before replacing any source file.
    foreach ($rules as $rule) {
        $target = $targetRoot . '/' . $rule['path']; $candidate = $candidateRoot . '/' . $rule['path'];
        if (array_key_exists('added', $rule)) {
            if ($rule['added'] !== true || $rule['beforeSha256'] !== hash('sha256', '')
                || array_key_exists('preparedBeforeSha256', $rule) || file_exists($target) || is_link($target)) {
                throw new RuntimeException('Added patch input must be absent from fresh official source: ' . $rule['path']);
            }
        } elseif (!is_file($target) || is_link($target) || hash_file('sha256', $target) !== $rule['beforeSha256']) {
            throw new RuntimeException('Patch input differs from fresh official source: ' . $rule['path']);
        }
        if (!is_file($candidate) || is_link($candidate) || hash_file('sha256', $candidate) !== $rule['afterSha256']) {
            throw new RuntimeException('Approved candidate source differs: ' . $rule['path']);
        }
    }
    foreach ($rules as $rule) {
        $target = $targetRoot . '/' . $rule['path']; $candidate = $candidateRoot . '/' . $rule['path'];
        if (!copy($candidate, $target) || hash_file('sha256', $target) !== $rule['afterSha256']) {
            throw new RuntimeException('Cannot apply approved candidate source: ' . $rule['path']);
        }
    }
}

function sdkToolDigest(array $entries, string $tool): string
{
    $path = 'prepared/llvm/bin/' . $tool;
    $seen = [];
    while (($entries[$path]['type'] ?? null) === 'link') {
        $target = $entries[$path]['target'] ?? null;
        if (isset($seen[$path]) || !is_string($target)
            || preg_match('/^[A-Za-z0-9._+-]+$/D', $target) !== 1 || in_array($target, ['.', '..'], true)) {
            throw new RuntimeException('Selected LLVM tool link is unsafe or cyclic: ' . $tool);
        }
        $seen[$path] = true;
        $path = 'prepared/llvm/bin/' . $target;
    }
    $digest = $entries[$path]['sha256'] ?? null;
    if (($entries[$path]['type'] ?? null) !== 'file' || !is_string($digest)
        || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
        throw new RuntimeException('Selected LLVM tool material identity is missing: ' . $tool);
    }
    return $digest;
}
function sdkVerifySysroot(string $archive, string $root, array $expected): array
{
    if (hash_file('sha256', $archive) !== $expected['sha256']) { throw new RuntimeException('Sysroot input component archive differs'); }
    $raw = '';
    $exit = ProcessOutput::run(['/usr/bin/unzip', '-p', $archive, 'minimal-component.json'], null, null, ['file', '/dev/null', 'r'],
        static function (int $channel, string $chunk) use (&$raw): void { if ($channel === 1) { $raw .= $chunk; if (strlen($raw) > 32 * 1024 * 1024) { throw new RuntimeException('Sysroot component manifest is too large'); } } else { fwrite(STDERR, $chunk); } }, static function (float $elapsed, float $silent): void { fwrite(STDERR, sprintf('[sdk] Inspecting sysroot input %.0fs\n', $elapsed)); });
    if ($exit !== 0) { throw new RuntimeException('Cannot inspect sysroot component archive'); }
    if (!is_string($raw) || hash('sha256', $raw) !== $expected['manifestSha256']) { throw new RuntimeException('Sysroot component manifest differs'); }
    $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR); $entries = [];
    foreach ($manifest['entries'] as $path => $entry) {
        if (!str_starts_with($path, 'prepared/sysroot/')) { continue; }
        $relative = substr($path, strlen('prepared/sysroot/'));
        $entries[$relative] = $entry['type'] === 'link' ? ['type' => 'link', 'target' => $entry['target']] : ['type' => 'file', 'sha256' => $entry['sha256']];
    }
    ksort($entries, SORT_STRING); $actual = sdkTree($root);
    if ($entries === [] || $actual !== $entries) { throw new RuntimeException('Sysroot bytes or link targets differ from the locked input component'); }
    return ['archiveSha256' => $expected['sha256'], 'manifestSha256' => $expected['manifestSha256'], 'treeSha256' => hash('sha256', json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'tools' => ['clang' => sdkToolDigest($manifest['entries'], 'clang'), 'objcopy' => sdkToolDigest($manifest['entries'], 'llvm-objcopy')]];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) { return; }

try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!preg_match('/^--([a-z-]+)=(.+)$/sD', $argument, $match) || isset($options[$match[1]])) {
            throw new InvalidArgumentException('Use unique --name=value SDK producer options');
        }
        $options[$match[1]] = $match[2];
    }
    foreach (['typephp', 'upstream-sdk-archive', 'typephp-archive', 'phpx-archive', 'sysroot', 'sysroot-component-archive', 'clang', 'cmake', 'ar', 'ranlib', 'objcopy', 'output', 'archive-root'] as $key) {
        if (!isset($options[$key])) { throw new InvalidArgumentException('Missing --' . $key); }
    }
    $output = $options['output'];
    if (file_exists($output) || is_link($output) || !mkdir($output, 0700, true)) {
        throw new RuntimeException('SDK producer output must be a new private directory');
    }
    $lock = json_decode(file_get_contents($repository . '/toolchain.lock.json'), true, flags: JSON_THROW_ON_ERROR);
    $components = array_column($lock['components'], null, 'id');
    $upstream = $lock['evidence']['patchedSdk']['upstreamArchiveSha256'] ?? $components['phpx-sdk-linux-x64']['sha256'];
    foreach (['upstream-sdk-archive' => $upstream, 'typephp-archive' => $components['typephp-source']['sha256'], 'phpx-archive' => $components['phpx-source']['sha256']] as $name => $expected) {
        if (!is_file($options[$name]) || is_link($options[$name]) || hash_file('sha256', $options[$name]) !== $expected) {
            throw new RuntimeException('Original input archive differs: ' . $name);
        }
    }
    $typephp = realpath($options['typephp']);
    if (!is_string($typephp)) { throw new RuntimeException('TypePHP source directory is missing'); }
    $manifest = $repository . '/toolchain/patches/typephp/0.9.2/manifest.json';
    (new TypePhpPatchSourceVerifier())->verify($typephp, $manifest);
    $phpx = $typephp . '/vendor/swoole/phpx';
    $baseline = $output . '/source-baseline'; mkdir($baseline, 0700);
    foreach (['typephp-archive', 'phpx-archive'] as $name) { sdkRun(['/usr/bin/tar', '-xf', $options[$name], '-C', $baseline]); }
    $rules = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR)['rules'];
    // The minimal installed compiler omits examples and tests. Build from fresh complete
    // official trees, applying only manifest-authorized after bytes from the candidate.
    $inputTypephp = $typephp; $inputPhpx = $phpx;
    $sourceRoots = [];
    foreach (['typephp', 'phpx'] as $component) {
        $roots = glob($baseline . '/' . $component . '-*', GLOB_ONLYDIR) ?: [];
        if (count($roots) !== 1 || is_link($roots[0])) { throw new RuntimeException('Source archive requires one safe ' . $component . ' root'); }
        $sourceRoots[$component] = $roots[0];
    }
    sdkVerifySource($sourceRoots['typephp'] . '/src', $inputTypephp . '/src', $rules, 'src/', []);
    sdkVerifySource($sourceRoots['phpx'], $inputPhpx, $rules, 'vendor/swoole/phpx/', ['full-static/sdk', 'full-static/sdk-before-032', 'full-static/build']);
    $sourceOutput = $output . '/source'; mkdir($sourceOutput, 0700);
    $typephp = $sourceOutput . '/' . basename($sourceRoots['typephp']); sdkCopy($sourceRoots['typephp'], $typephp);
    mkdir($typephp . '/vendor', 0755); mkdir($typephp . '/vendor/swoole', 0755);
    $phpx = $typephp . '/vendor/swoole/phpx'; sdkCopy($sourceRoots['phpx'], $phpx);
    sdkApplySourceRules($typephp, $inputTypephp, $rules);
    (new TypePhpPatchSourceVerifier())->verify($typephp, $manifest);
    $sourceIdentity = [
        'typephpTreeSha256' => sdkVerifySource($sourceRoots['typephp'], $typephp, $rules, '', ['vendor']),
        'phpxTreeSha256' => sdkVerifySource($sourceRoots['phpx'], $phpx, $rules, 'vendor/swoole/phpx/', []),
    ];
    $minimalLock = json_decode(file_get_contents($repository . '/toolchain/minimal-components.lock.json'), true, flags: JSON_THROW_ON_ERROR);
    $sysrootInput = $lock['evidence']['patchedSdk']['sysrootInput'] ?? $minimalLock['components']['macos-arm64'];
    $sysrootIdentity = sdkVerifySysroot($options['sysroot-component-archive'], $options['sysroot'], $sysrootInput);

    if (!preg_match('/^webman-aot-builder-[0-9]+\.[0-9]+\.[0-9]+-derived-linux-x86_64-sdk$/D', $options['archive-root'])) {
        throw new RuntimeException('Derived SDK archive root is invalid');
    }
    foreach (['clang', 'cmake', 'ar', 'ranlib', 'objcopy'] as $name) {
        if (!is_file($options[$name]) || !is_executable($options[$name])) { throw new RuntimeException('SDK build tool missing: ' . $name); }
    }
    foreach (['clang', 'objcopy'] as $name) {
        if (!is_string($sysrootIdentity['tools'][$name]) || hash_file('sha256', $options[$name]) !== $sysrootIdentity['tools'][$name]) {
            throw new RuntimeException('SDK build tool differs from the locked original component: ' . $name);
        }
    }
    $options['clang++'] = $options['clang'] . '++';
    if (!is_file($options['clang++']) || !is_executable($options['clang++'])
        || hash_file('sha256', $options['clang++']) !== $sysrootIdentity['tools']['clang']) {
        throw new RuntimeException('SDK C++ compiler differs from the locked original component');
    }
    (new WebmanAotBuilder\Toolchain\ToolchainCapabilities())->assertCxx($options['clang++']);
    fwrite(STDERR, "[sdk] Verified official source archives and guarded patched source\n");
    $extraction = $output . '/upstream'; mkdir($extraction, 0700);
    sdkRun(['/usr/bin/tar', '-xf', $options['upstream-sdk-archive'], '-C', $extraction]);
    $roots = glob($extraction . '/*', GLOB_ONLYDIR);
    if (!is_array($roots) || count($roots) !== 1) { throw new RuntimeException('SDK archive requires one root'); }
    $sdk = $output . '/' . $options['archive-root'];
    sdkCopy($roots[0], $sdk);
    $ncurses = $sdk . '/include/ncursesw/ncurses.h';
    $ncursesTarget = $sdk . '/include/ncursesw/curses.h';
    $ncursesHash = 'd020361b2ac530ccf0494a479264976ee3a0c903ba30291b23c266ef926f85de';
    if (!is_link($ncurses) || readlink($ncurses) !== 'curses.h'
        || !is_file($ncursesTarget) || is_link($ncursesTarget)
        || hash_file('sha256', $ncursesTarget) !== $ncursesHash) {
        throw new RuntimeException('Official SDK ncurses compatibility link drifted');
    }
    if (!unlink($ncurses) || !copy($ncursesTarget, $ncurses)) {
        throw new RuntimeException('Cannot normalize the private SDK ncurses header');
    }
    fwrite(STDERR, "[sdk] Verified and normalized the locked ncurses header for both hosts\n");
    $build = $output . '/build';
    $layout = (new WebmanAotBuilder\Toolchain\StaticTargetLayout())->sysroot($options['sysroot']);
    $cxx = '-isystem "' . $layout['cxx'] . '" -isystem "' . $layout['targetInclude'] . '"';
    $prefixes = ' -ffile-prefix-map="' . $phpx . '=/usr/src/phpx" -ffile-prefix-map="' . $sdk . '=/usr/src/sdk" -ffile-prefix-map="' . $options['sysroot'] . '=/usr/src/sysroot"';
    fwrite(STDERR, "[sdk] Configure Linux x86_64 musl runtime\n");
    sdkRun([$options['cmake'], '-S', $phpx . '/full-static', '-B', $build,
        '-DCMAKE_BUILD_TYPE=Release', '-DCMAKE_SYSTEM_NAME=Linux', '-DCMAKE_SYSTEM_PROCESSOR=x86_64',
        '-DCMAKE_TRY_COMPILE_TARGET_TYPE=STATIC_LIBRARY', '-DCMAKE_C_COMPILER=' . $options['clang'],
        '-DCMAKE_CXX_COMPILER=' . $options['clang++'], '-DCMAKE_C_COMPILER_TARGET=x86_64-unknown-linux-musl',
        '-DCMAKE_CXX_COMPILER_TARGET=x86_64-unknown-linux-musl', '-DCMAKE_SYSROOT=' . $options['sysroot'],
        '-DCMAKE_AR=' . $options['ar'], '-DCMAKE_RANLIB=' . $options['ranlib'],
        '-DPHPX_PHP_INCLUDE_DIR=' . $sdk . '/include/php', '-DPHPX_GMP_INCLUDE_DIR=' . $sdk . '/include',
        '-DPHPX_MPFR_INCLUDE_DIR=' . $sdk . '/include', '-DPHPX_GMP_LIB_DIR=' . $sdk . '/lib',
        '-DPHPX_MPFR_LIB_DIR=' . $sdk . '/lib', '-DCMAKE_CXX_FLAGS=' . $cxx . $prefixes,
        '-DCMAKE_C_FLAGS=' . $prefixes, '-DCMAKE_EXPORT_COMPILE_COMMANDS=ON']);
    fwrite(STDERR, "[sdk] Build all PHPX runtime and bundled dependency objects\n");
    sdkRun([$options['cmake'], '--build', $build, '--parallel', '4']);
    $commands = json_decode(file_get_contents($build . '/compile_commands.json'), true, flags: JSON_THROW_ON_ERROR);
    $members = [];
    $objectDirectory = $output . '/objects'; mkdir($objectDirectory, 0700);
    foreach ($commands as $entry) {
        $source = realpath($entry['file']);
        if (!is_string($source) || !str_starts_with($source, $phpx . '/')) {
            throw new RuntimeException('SDK object source escaped the guarded PHPX tree');
        }
        $name = str_replace('/', '__', substr($source, strlen($phpx) + 1)) . '.o';
        if (preg_match('/(?:^|\s)-flto(?:[=\s]|$)/', $entry['command']) === 1) { throw new RuntimeException('SDK runtime objects must not use LTO'); }
        $file = $build . '/' . $entry['output'];
        $elf = is_file($file) ? file_get_contents($file, false, null, 0, 20) : false;
        if (!is_string($elf) || strlen($elf) !== 20 || substr($elf, 0, 6) !== "\x7fELF\x02\x01" || unpack('vtype/vmachine', substr($elf, 16, 4)) !== ['type' => 1, 'machine' => 62]) {
            throw new RuntimeException('SDK member must be ELF64 little-endian ET_REL x86_64');
        }
        if (isset($members[$name]) || !is_file($file) || is_link($file) || file_get_contents($file, false, null, 0, 4) !== "\x7fELF") {
            throw new RuntimeException('Archive members must be unique ordinary ELF objects');
        }
        $member = $objectDirectory . '/' . $name;
        if (!copy($file, $member)) { throw new RuntimeException('cannot copy canonical ELF member'); }
        $members[$name] = ['path' => $member, 'sha256' => hash_file('sha256', $member)];
    }
    ksort($members, SORT_STRING);
    $library = $output . '/libphpx.a';
    sdkRun([$options['ar'], '--format=gnu', 'rcsD', $library, ...array_column($members, 'path')]);
    sdkRun([$options['ranlib'], '-D', $library]);
    copy($library, $sdk . '/lib/libphpx.a');
    sdkCopy($phpx . '/include', $sdk . '/include/phpx');
    $libraries = ['libphp.a' => hash_file('sha256', $sdk . '/lib/libphp.a'), 'libphpx.a' => hash_file('sha256', $sdk . '/lib/libphpx.a')];
    sdkRun([PHP_BINARY, $repository . '/tools/strip-sdk-debug.php', '--sdk=' . $sdk, '--objcopy=' . $options['objcopy']]);
    $headers = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sdk . '/include/phpx', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && !$file->isLink()) { $headers[substr($file->getPathname(), strlen($sdk) + 1)] = hash_file('sha256', $file->getPathname()); }
    }
    ksort($headers, SORT_STRING);
    $tools = [];
    foreach (['clang', 'clang++', 'ar', 'ranlib', 'cmake', 'objcopy'] as $name) {
        $versionOutput = (string) shell_exec(escapeshellarg($options[$name]) . ' --version');
        if (preg_match('/(?:LLVM|clang|cmake) version ([0-9.]+)/', $versionOutput, $match) !== 1) {
            $match[1] = trim($versionOutput);
        }
        $tools[$name] = ['sha256' => hash_file('sha256', $options[$name]), 'version' => $match[1]];
    }
    $files = sdkTree($sdk); $sums = '';
    foreach ($files as $path => $entry) { if ($entry['type'] === 'file' && $path !== 'FILES.SHA256') { $sums .= $entry['sha256'] . '  ' . $path . "\n"; } }
    $originalFileManifest = hash_file('sha256', $sdk . '/FILES.SHA256');
    file_put_contents($sdk . '/FILES.SHA256', $sums);
    $normalizedCommands = str_replace([$phpx, $sdk, $build, $options['sysroot'], $options['clang']], ['/usr/src/phpx', '/usr/src/sdk', '/usr/src/build', '/usr/src/sysroot', '/usr/bin/clang'], file_get_contents($build . '/compile_commands.json'));
    $derivation = ['schema'  => 'webman-aot-builder-derived-sdk-v1', 'target' => 'x86_64-unknown-linux-musl', 'headerNormalizations' => ['include/ncursesw/ncurses.h' => ['originalTarget' => 'curses.h', 'sha256' => $ncursesHash, 'type' => 'file']],
        'upstreamSdkArchiveSha256' => $upstream, 'typephpArchiveSha256' => $components['typephp-source']['sha256'],
        'phpxArchiveSha256' => $components['phpx-source']['sha256'], 'phpxRevision' => $components['phpx-source']['revision'],
        'sourceIdentity' => $sourceIdentity, 'sysrootInput' => $sysrootIdentity, 'compileCommandsSha256' => hash('sha256', $normalizedCommands), 'compileCommands' => json_decode($normalizedCommands, true, flags: JSON_THROW_ON_ERROR), 'originalFilesManifestSha256' => $originalFileManifest,
        'patchManifestSha256' => hash_file('sha256', $manifest), 'librariesBeforeStripping' => $libraries,
        'libraries' => ['libphp.a' => hash_file('sha256', $sdk . '/lib/libphp.a'), 'libphpx.a' => hash_file('sha256', $sdk . '/lib/libphpx.a')],
        'sdkSha256' => (new StaticSdkFingerprint())->digest($sdk), 'allHeadersSha256' => hash('sha256', json_encode(sdkTree($sdk . '/include'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'headers' => $headers, 'buildTools' => $tools,
        'members' => array_map(static fn(array $entry): string => $entry['sha256'], $members)];
    file_put_contents($sdk . '/builder-derivation.json', json_encode($derivation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    file_put_contents($output . '/result.json', json_encode(['sdk' => $sdk, 'typephp' => $typephp, 'derivation' => $derivation, 'members' => $members], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fwrite(STDERR, '[sdk] Success: ' . $sdk . '; fingerprint ' . $derivation['sdkSha256'] . "\n");
} catch (Throwable $error) { fwrite(STDERR, '[sdk] Failed: ' . $error->getMessage() . "\n"); exit(1); }
