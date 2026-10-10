<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Toolchain\StaticTargetLayout;
use WebmanAotBuilder\Toolchain\ToolchainCapabilities;
use WebmanAotBuilder\Toolchain\TypePhpPatchManifestFingerprint;
use WebmanAotBuilder\Toolchain\TypePhpPatchSourceVerifier;
use WebmanAotBuilder\Toolchain\ReproducibilityInput;
use WebmanAotBuilder\Toolchain\LockValidator;
use WebmanAotBuilder\Toolchain\PreparedToolchain;
use WebmanAotBuilder\Toolchain\StaticSdkFingerprint;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

function ensure(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function put(string $file, string $source): void
{
    if (!is_dir(dirname($file))) { mkdir(dirname($file), 0700, true); }
    ensure(file_put_contents($file, $source) !== false, 'cannot prepare fixture');
}
function refused(Closure $action, string $message): void
{
    try { $action(); } catch (ConfigurationException|RuntimeException $error) { return; }
    throw new RuntimeException($message);
}
function clean(string $root): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

$typephp = $argv[1] ?? '';
$sdk = $argv[2] ?? '';
$sysroot = $argv[3] ?? '';
$php = $argv[4] ?? '';
$compiler = $argv[5] ?? '';
ensure(is_dir($typephp) && is_dir($sdk) && is_dir($sysroot) && is_file($php) && is_file($compiler), 'Pass actual selected TypePHP, SDK, sysroot, PHP driver and C++ compiler');
$repository = dirname(__DIR__);
$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-toolchain-version-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$tests = 0;
try {
    $manifest = json_decode(file_get_contents($repository . '/toolchain/patches/typephp/0.9.2/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['0.9.2', '99.7.0', 'dev-selected-next'] as $label) {
        $manifest['version'] = $label;
        put($root . '/patch.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        (new TypePhpPatchSourceVerifier())->verify($typephp, $root . '/patch.json');
        ensure(strlen((new TypePhpPatchManifestFingerprint())->digest($root . '/patch.json')) === 64, 'selected manifest label rejected');
        echo "PASS selected patch material / {$label}\n";
        $tests++;
    }
    $manifest['rules'][0]['afterSha256'] = str_repeat('0', 64);
    put($root . '/patch.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    refused(fn() => (new TypePhpPatchSourceVerifier())->verify($typephp, $root . '/patch.json'), 'changed material digest accepted');
    $tests++; echo "PASS selected patch material SHA mismatch refused\n";
    $manifest['rules'][0]['path'] = '../outside.php';
    put($root . '/patch.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    refused(fn() => (new TypePhpPatchManifestFingerprint())->digest($root . '/patch.json'), 'unsafe manifest path accepted');
    $tests++; echo "PASS unsafe patch path refused\n";

    foreach (['12.2.1', '99.4-next'] as $directory) {
        $base = $root . '/sysroot-' . $directory;
        foreach (['usr/lib/libstdc++.a', 'usr/lib/gcc/x86_64-alpine-linux-musl/' . $directory . '/crtbegin.o',
            'usr/lib/gcc/x86_64-alpine-linux-musl/' . $directory . '/libgcc.a', 'usr/include/c++/' . $directory . '/vector',
            'usr/include/c++/' . $directory . '/x86_64-alpine-linux-musl/bits/c++config.h'] as $file) { put($base . '/' . $file, 'selected matching ABI fixture'); }
        $layout = (new StaticTargetLayout())->sysroot($base);
        ensure(basename($layout['gcc']) === $directory && str_contains($layout['cxx'], $directory), 'sysroot version directory pinned');
        $tests++; echo "PASS matching ABI sysroot / {$directory}\n";
        unlink($base . '/usr/lib/gcc/x86_64-alpine-linux-musl/' . $directory . '/libgcc.a');
        refused(fn() => (new StaticTargetLayout())->sysroot($base), 'incomplete ABI sysroot accepted');
        $tests++; echo "PASS incomplete sysroot / {$directory} refused\n";
    }
    foreach (['4', '5'] as $minor) {
        put($root . '/sdk/include/php/main/php_version.h', "#define PHP_MAJOR_VERSION 8\n#define PHP_MINOR_VERSION {$minor}\n");
        $abi = ['schema' => 'typephp-php-runtime-layer-v1', 'target' => 'linux-x64', 'zts' => true, 'php_version' => '8.' . $minor . '.25'];
        put($root . '/sdk/manifest.json', json_encode($abi, JSON_THROW_ON_ERROR));
        ensure((new StaticTargetLayout())->phpVersion($root . '/sdk') === '8.' . $minor, 'SDK ABI target version pinned');
        $tests++; echo "PASS selected SDK ABI header / 8.{$minor}\n";
        $abi['zts'] = false;
        put($root . '/sdk/manifest.json', json_encode($abi, JSON_THROW_ON_ERROR));
        refused(fn() => (new StaticTargetLayout())->phpVersion($root . '/sdk'), 'incompatible SDK ZTS ABI accepted');
        $tests++; echo "PASS incompatible SDK ZTS / 8.{$minor} refused\n";
    }
    put($root . '/sdk/include/php/main/php_version.h', "#define PHP_MAJOR_VERSION 8\n#define PHP_MINOR_VERSION 5\n#define PHP_RELEASE_VERSION 3\n#define PHP_VERSION_ID 80503\n");
    put($root . '/sdk/manifest.json', json_encode(['schema' => 'typephp-php-runtime-layer-v1', 'target' => 'linux-x64', 'zts' => true, 'php_version' => '8.5.3'], JSON_THROW_ON_ERROR));
    put($root . '/sdk/include/php/ext/redis/php_redis.h', '#define PHP_REDIS_VERSION "99.7.1"' . "\n");
    put($root . '/sdk/include/php/main/build-defs.h', '#define CONFIGURE_COMMAND "' . "'--disable-all' '--enable-intl'" . '"' . "\n");
    $standardTable = "static const zend_function_entry ext_functions[] = {\nZEND_FE_END\n};\nstatic void register_basic_functions_symbols(int module_number)\n{\n}\n";
    put($root . '/sdk/include/php/ext/standard/basic_functions_arginfo.h', $standardTable);
    $intlTable = "static const zend_function_entry ext_functions[] = {\nZEND_FE(locale_get_default, arginfo_locale_get_default)\nZEND_FE_END\n};\n";
    put($root . '/sdk/include/php/ext/intl/php_intl_arginfo.h', $intlTable);
    ensure((new StaticTargetLayout())->runtimeCapabilities($root . '/sdk') === ['phpVersionId' => 80503, 'redisVersion' => '99.7.1', 'deepcloneEnabled' => false, 'intlEnabled' => true, 'nativeLocaleIsRightToLeft' => false, 'nativeGraphemeLevenshtein' => false, 'nativeGraphemeStrrev' => false, 'nativeClamp' => false, 'nativeArrayFilterUseValue' => false], 'runtime capabilities did not come from selected SDK');
    $tests++; echo "PASS selected SDK PHP/Redis capabilities accept newer material labels\n";
    put($root . '/sdk/include/php/ext/redis/php_redis.h', '#define PHP_REDIS_VERSION "unknown"' . "\n");
    refused(fn() => (new StaticTargetLayout())->runtimeCapabilities($root . '/sdk'), 'unknown Redis runtime macro accepted');
    $tests++; echo "PASS incomplete SDK runtime macro refused\n";
    put($root . '/sdk/include/php/ext/redis/php_redis.h', '#define PHP_REDIS_VERSION "99.7.1"' . "\n");
    foreach (['locale_is_right_to_left', 'grapheme_levenshtein', 'grapheme_strrev'] as $function) {
        put($root . '/sdk/include/php/ext/intl/php_intl_arginfo.h', str_replace('ZEND_FE_END', "ZEND_FE({$function}, arginfo_{$function})\nZEND_FE_END", $intlTable));
        $runtime = (new StaticTargetLayout())->runtimeCapabilities($root . '/sdk');
        ensure($runtime['nativeLocaleIsRightToLeft'] === ($function === 'locale_is_right_to_left') && $runtime['nativeGraphemeLevenshtein'] === ($function === 'grapheme_levenshtein') && $runtime['nativeGraphemeStrrev'] === ($function === 'grapheme_strrev'), 'Intl capability not determined by registration');
        $tests++; echo "PASS selected SDK native registration / {$function}\n";
    }
    foreach (['missing-terminator' => str_replace('ZEND_FE_END', '', $intlTable),
        'unknown-registration' => str_replace('ZEND_FE_END', "OTHER_FUNCTIONS()\nZEND_FE_END", $intlTable),
        'conditional-native' => str_replace('ZEND_FE_END', "#if UNKNOWN_FEATURE\nZEND_FE(locale_is_right_to_left, arginfo_rtl)\n#endif\nZEND_FE_END", $intlTable)] as $name => $table) {
        put($root . '/sdk/include/php/ext/intl/php_intl_arginfo.h', $table);
        refused(fn() => (new StaticTargetLayout())->runtimeCapabilities($root . '/sdk'), 'unknown Intl registration accepted');
        $tests++; echo "PASS unknown SDK Intl capability / {$name} refused\n";
    }
    put($root . '/sdk/include/php/ext/intl/php_intl_arginfo.h', $intlTable);
    foreach (['missing-terminator' => str_replace('ZEND_FE_END', '', $standardTable),
        'after-terminator' => str_replace('ZEND_FE_END', "ZEND_FE_END\nZEND_FE(clamp, arginfo_clamp)", $standardTable),
        'negative-depth' => str_replace('ZEND_FE_END', "#endif\n#if UNKNOWN\nZEND_FE_END", $standardTable),
        'conditional-clamp' => str_replace('ZEND_FE_END', "#if UNKNOWN\nZEND_FE(clamp, arginfo_clamp)\n#endif\nZEND_FE_END", $standardTable)] as $name => $table) {
        put($root . '/sdk/include/php/ext/standard/basic_functions_arginfo.h', $table);
        refused(fn() => (new StaticTargetLayout())->runtimeCapabilities($root . '/sdk'), 'unknown standard registration accepted');
        $tests++; echo "PASS unknown SDK standard capability / {$name} refused\n";
    }
    put($root . '/sdk/include/php/ext/standard/basic_functions_arginfo.h', $standardTable);
    unlink($root . '/sdk/include/php/ext/intl/php_intl_arginfo.h');
    refused(fn() => (new StaticTargetLayout())->runtimeCapabilities($root . '/sdk'), 'missing Intl registration table accepted');
    $tests++; echo "PASS missing SDK Intl table refused\n";
    put($root . '/sdk/include/php/main/build-defs.h', '#define CONFIGURE_COMMAND "' . "'--disable-all' '--disable-intl'" . '"' . "\n");
    $runtime = (new StaticTargetLayout())->runtimeCapabilities($root . '/sdk');
    ensure($runtime['intlEnabled'] === false && $runtime['nativeLocaleIsRightToLeft'] === false && $runtime['nativeGraphemeLevenshtein'] === false, 'disabled Intl incorrectly registers native functions');
    $tests++; echo "PASS selected SDK explicitly disabled Intl\n";
    put($root . '/sdk/include/php/main/build-defs.h', '#define CONFIGURE_COMMAND "' . "'./configure'" . '"' . "\n");
    refused(fn() => (new StaticTargetLayout())->runtimeCapabilities($root . '/sdk'), 'unknown Intl enablement accepted');
    $tests++; echo "PASS unknown SDK Intl enablement refused\n";
    put($root . '/sdk/include/php/main/build-defs.h', '#define CONFIGURE_COMMAND "' . "'--disable-all' '--enable-intl=shared,/unknown'" . '"' . "\n");
    refused(fn() => (new StaticTargetLayout())->runtimeCapabilities($root . '/sdk'), 'unknown Intl linkage accepted');
    $tests++; echo "PASS unknown SDK Intl linkage refused\n";
    put($root . '/sdk/include/php/main/build-defs.h', '#define CONFIGURE_COMMAND "' . "'--disable-all' '--enable-deepclone'" . '"' . "\n");
    ensure((new StaticTargetLayout())->runtimeCapabilities($root . '/sdk')['deepcloneEnabled'] === true, 'selected native deepclone capability lost');
    $tests++; echo "PASS selected SDK enabled deepclone capability\n";
    put($root . '/sdk/include/php/main/build-defs.h', '#define CONFIGURE_COMMAND "' . "'--disable-all' '--enable-deepclone=shared'" . '"' . "\n");
    refused(fn() => (new StaticTargetLayout())->runtimeCapabilities($root . '/sdk'), 'unknown deepclone linkage accepted');
    $tests++; echo "PASS unknown SDK deepclone linkage refused\n";
    $actualRuntime = (new StaticTargetLayout())->runtimeCapabilities($sdk);
    ensure($actualRuntime === ['phpVersionId' => 80425, 'redisVersion' => '6.2.0', 'deepcloneEnabled' => false, 'intlEnabled' => true, 'nativeLocaleIsRightToLeft' => false, 'nativeGraphemeLevenshtein' => false, 'nativeGraphemeStrrev' => false, 'nativeClamp' => false, 'nativeArrayFilterUseValue' => false], 'actual selected runtime capabilities differ');
    $tests++; echo "PASS actual selected SDK PHP/Redis macros\n";
    $preparedRoot = dirname($sysroot);
    $actualTools = (new PreparedToolchain())->load($preparedRoot . '/prepared-toolchain.json', dirname($preparedRoot), dirname($preparedRoot) . '/toolchain.lock.json', 'macos-arm64');
    ensure($actualTools['sdkContext']['sdkSha256'] === $actualTools['sdkSha256']
        && $actualTools['sdkContext']['phpVersionId'] === 80425 && $actualTools['sdkContext']['deepcloneEnabled'] === false,
        'actual selected SDK approval context lost');
    $tests++; echo "PASS actual prepared SDK selected authority context\n";
    foreach (['selected-current', 'selected-other-material'] as $label) {
        $fixture = $root . '/' . $label;
        $prepared = $fixture . '/prepared';
        $sdkPath = $prepared . '/typephp/vendor/swoole/phpx/full-static/sdk';
        $headers = ['php/ext/standard/basic_functions_arginfo.h' => $standardTable, 'php/main/php_version.h' => "#define PHP_MAJOR_VERSION 8\n#define PHP_MINOR_VERSION 5\n#define PHP_RELEASE_VERSION 3\n#define PHP_VERSION_ID 80503\n",
            'php/main/build-defs.h' => '#define CONFIGURE_COMMAND "' . "'--disable-all'" . '"' . "\n",
            'php/ext/redis/php_redis.h' => '#define PHP_REDIS_VERSION "7.1.0"' . "\n", 'phpx/selected.h' => 'selected public header ' . $label];
        $headerEntries = [];
        foreach ($headers as $name => $bytes) {
            put($sdkPath . '/include/' . $name, $bytes);
            $headerEntries[$name] = ['type' => 'file', 'sha256' => hash('sha256', $bytes)];
        }
        ksort($headerEntries, SORT_STRING);
        put($sdkPath . '/manifest.json', json_encode(['schema' => 'typephp-php-runtime-layer-v1', 'target' => 'linux-x64', 'zts' => true, 'php_version' => '8.5.3'], JSON_THROW_ON_ERROR));
        put($sdkPath . '/lib/libphp.a', 'explicit selected static material / ' . $label);
        $sdkDigest = (new StaticSdkFingerprint())->digest($sdkPath);
        $derivation = ['schema' => 'webman-aot-builder-derived-sdk-v1', 'sdkSha256' => $sdkDigest,
            'allHeadersSha256' => hash('sha256', json_encode($headerEntries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'headers' => ['include/phpx/selected.h' => hash('sha256', $headers['phpx/selected.h'])]];
        put($sdkPath . '/builder-derivation.json', json_encode($derivation, JSON_THROW_ON_ERROR));
        $approval = ['sdkSha256' => $sdkDigest, 'derivationSha256' => hash_file('sha256', $sdkPath . '/builder-derivation.json')];
        $selectedLock = ['evidence' => ['patchedSdk' => $approval]];
        put($fixture . '/toolchain.lock.json', json_encode($selectedLock, JSON_THROW_ON_ERROR));
        foreach (['php', 'compiler', 'objcopy'] as $name) { put($prepared . '/bin/' . $name, "#!/bin/sh\nexit 0\n"); chmod($prepared . '/bin/' . $name, 0700); }
        foreach (['sysroot', 'phprc'] as $name) { mkdir($prepared . '/' . $name, 0700, true); }
        put($prepared . '/typephp/bin/tpc.php', '<?php');
        $manifest = ['schema' => 'webman-aot-builder-prepared-toolchain-v1', 'host' => 'macos-arm64',
            'lockSha256' => hash_file('sha256', $fixture . '/toolchain.lock.json'), 'php' => 'bin/php', 'compiler' => 'bin/compiler',
            'objcopy' => 'bin/objcopy', 'typephp' => 'typephp', 'phpx' => 'typephp/vendor/swoole/phpx', 'sysroot' => 'sysroot',
            'phprc' => 'phprc', 'sdkSha256' => $sdkDigest];
        put($prepared . '/prepared.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $load = fn() => (new PreparedToolchain())->load($prepared . '/prepared.json', $fixture, $fixture . '/toolchain.lock.json', 'macos-arm64');
        $context = $load()['sdkContext'];
        ensure($context['sdkSha256'] === $approval['sdkSha256'] && $context['derivationSha256'] === $approval['derivationSha256']
            && $context['toolchainLockFile'] === realpath($fixture . '/toolchain.lock.json')
            && $context['phpVersionId'] === 80503 && $context['deepcloneEnabled'] === false, 'approved selected SDK context rejected or changed');
        $tests++; echo "PASS selected manifest SDK authority / {$label}\n";
        put($sdkPath . '/lib/libphp.a', 'unapproved drift');
        refused($load, 'drifted selected SDK accepted');
        $tests++; echo "PASS selected SDK static material drift / {$label} refused\n";
        put($sdkPath . '/lib/libphp.a', 'explicit selected static material / ' . $label);
        $selectedLock['evidence']['patchedSdk']['sdkSha256'] = str_repeat('0', 64);
        put($fixture . '/toolchain.lock.json', json_encode($selectedLock, JSON_THROW_ON_ERROR));
        $manifest['lockSha256'] = hash_file('sha256', $fixture . '/toolchain.lock.json');
        put($prepared . '/prepared.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        refused($load, 'unselected SDK expected identity accepted');
        $tests++; echo "PASS unselected SDK authority / {$label} refused\n";
        $selectedLock['evidence']['patchedSdk'] = $approval;
        put($fixture . '/toolchain.lock.json', json_encode($selectedLock, JSON_THROW_ON_ERROR));
        $manifest['lockSha256'] = hash_file('sha256', $fixture . '/toolchain.lock.json');
        put($prepared . '/prepared.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        put($sdkPath . '/include/phpx/selected.h', 'unapproved public header');
        refused($load, 'drifted approved header tree accepted');
        $tests++; echo "PASS selected SDK header drift / {$label} refused\n";
        put($sdkPath . '/include/phpx/selected.h', $headers['phpx/selected.h']);
        $selectedLock['evidence'] = [];
        put($fixture . '/toolchain.lock.json', json_encode($selectedLock, JSON_THROW_ON_ERROR));
        $manifest['lockSha256'] = hash_file('sha256', $fixture . '/toolchain.lock.json');
        put($prepared . '/prepared.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        refused($load, 'SDK without selected derivation approval accepted');
        $tests++; echo "PASS missing selected SDK authority / {$label} refused\n";

    }
    $layout = (new StaticTargetLayout())->sysroot($sysroot);
    ensure(is_dir($layout['gcc']), 'actual selected sysroot rejected');
    ensure((new StaticTargetLayout())->phpVersion($sdk) === '8.4', 'actual selected SDK ABI rejected');
    $tests++; echo "PASS actual selected SDK/sysroot layout\n";

    $lock = json_decode(file_get_contents($repository . '/toolchain.lock.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($lock['components'] as &$component) {
        if (str_starts_with($component['id'], 'llvm-')) { $component['version'] = $component['id'] === 'llvm-macos-arm64' ? 'dev-next' : '999.7'; }
    }
    unset($component);
    put($root . '/repro/toolchain.lock.json', json_encode($lock, JSON_THROW_ON_ERROR));
    mkdir($root . '/repro/toolchain/patches/typephp', 0700, true);
    symlink($repository . '/toolchain/patches/typephp/0.9.2', $root . '/repro/toolchain/patches/typephp/0.9.2');
    mkdir($root . '/repro/toolchain/smoke', 0700, true);
    copy($repository . '/toolchain/smoke/main.php', $root . '/repro/toolchain/smoke/main.php');
    $repro = (new ReproducibilityInput())->describe($root . '/repro');
    ensure(count($repro['input']['llvmMaterials']) === 2 && $repro['input']['compilerCapabilities']['pointerBits'] === 64, 'cross-host selected material/ABI contract lost');
    $tests++; echo "PASS cross-host labels share selected material/target contract\n";
    foreach ($lock['components'] as &$component) {
        if ($component['id'] === 'php-driver-windows-x64') { $component['version'] = 'dev-selected-next'; }
    }
    unset($component);
    ensure((new LockValidator())->validate($lock) === [], 'Windows selected driver label constrained to source label');
    $tests++; echo "PASS selected Windows driver label with unchanged material identity\n";
    $lock['target']['architecture'] = 'x86';
    put($root . '/repro/toolchain.lock.json', json_encode($lock, JSON_THROW_ON_ERROR));
    refused(fn() => (new ReproducibilityInput())->describe($root . '/repro'), 'incompatible host target ABI accepted');
    $tests++; echo "PASS cross-host target ABI mismatch refused\n";

    $process = proc_open([$php, '-n', '-r', ToolchainCapabilities::phpProbe()], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    ensure(is_resource($process) && proc_close($process) === 0, 'actual selected PHP capability rejected');
    $tests++; echo " PASS actual private PHP capability\n";
    (new ToolchainCapabilities())->assertCxx($compiler);
    $tests++; echo "PASS actual C++17 Linux target capability\n";
    printf("Toolchain compatibility: %d cases passed in %.3fs\n", $tests, microtime(true) - $started);
} finally { clean($root); }
