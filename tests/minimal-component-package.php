<?php

declare(strict_types=1);

if (PHP_OS_FAMILY !== 'Darwin') { fwrite(STDOUT, '[SKIP] macOS native archive/installation fixture' . PHP_EOL); exit(0); }

// Run with a locked component ZIP, verified prepared TypePHP tree and previous private runtime.
$repository = dirname(__DIR__);
[$script, $component, $typephp, $previous] = $argv + ['', '', '', ''];
foreach ([$component, $typephp, $previous] as $input) {
    if ($input === '' || !file_exists($input)) { throw new RuntimeException('Pass component ZIP, prepared TypePHP tree and previous private runtime'); }
}
$source = file_get_contents($repository . '/tools/package-installers.php');
$entry = strrpos($source, "\ntry {\n    (new InstallerPackager");
if ($entry === false) { throw new RuntimeException('packager entrypoint changed'); }
eval(substr($source, strlen('<?php'), $entry - strlen('<?php')));
$root = realpath(getenv('WEBMAN_AOT_TEST_TMP') ?: sys_get_temp_dir()) . '/aot-small-package-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$stage = $root . '/package';
$packager = new InstallerPackager($repository);
$plain = new InstallerPackager($repository);
(new ReflectionMethod(InstallerPackager::class, 'stageMinimalComponent'))->invoke($plain,
    $root . '/ordinary-small', 'macos-arm64', $component, false);
if (is_file($root . '/ordinary-small/payload/app/toolchain/minimal-upgrade/minimal-component.json')) {
    throw new RuntimeException('ordinary small build unexpectedly claimed upgrade capability');
}
echo '[PASS] Ordinary small packaging remains supported without a new required prepared-source argument' . "\n";
(new ReflectionMethod(InstallerPackager::class, 'parseOptions'))->invoke($packager,
    ['fixture', '--prepared-typephp=' . $typephp]);
(new ReflectionMethod(InstallerPackager::class, 'stageApplication'))->invoke($packager, $stage,
    $previous . '/current/app/THIRD_PARTY_LICENSES/TypePHP-GPL-3.0.txt');
(new ReflectionMethod(InstallerPackager::class, 'stageMinimalComponent'))->invoke($packager,
    $stage, 'macos-arm64', $component, false);
mkdir($stage . '/payload/runtime/bin', 0700, true);
foreach (['php', 'php-compiler'] as $name) {
    copy($previous . '/current/runtime/bin/' . $name, $stage . '/payload/runtime/bin/' . $name);
    chmod($stage . '/payload/runtime/bin/' . $name, 0700);
}
mkdir($stage . '/payload/launcher', 0700, true);
copy($repository . '/bin/webman-aot', $stage . '/payload/launcher/webman-aot');
copy($repository . '/installer/macos/install.sh', $stage . '/install.sh');
copy($repository . '/installer/macos/uninstall.sh', $stage . '/uninstall.sh');
$runtimeLock = json_decode(file_get_contents($repository . '/installer/runtime.lock.json'), true, flags: JSON_THROW_ON_ERROR);
foreach (['php' => 'binarySha256', 'php-compiler' => null] as $name => $key) {
    $expected = $key !== null ? $runtimeLock['runtimes']['macos-arm64'][$key]
        : $runtimeLock['runtimes']['macos-arm64']['compilerDriver']['binarySha256'];
    if (hash_file('sha256', $stage . '/payload/runtime/bin/' . $name) !== $expected) {
        throw new RuntimeException('read-only cached private runtime does not match target runtime lock');
    }
}
(new ReflectionMethod(InstallerPackager::class, 'writeMetadata'))->invoke($packager,
    $stage, 'macos-arm64', $runtimeLock['runtimes']['macos-arm64'], false);
$members = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS)) as $file) {
    $members[] = substr($file->getPathname(), strlen($stage) + 1);
}
require_once $repository . '/src/Version.php';
$archive = $root . '/webman-aot-builder-' . WebmanAotBuilder\Version::VALUE . '-macos-arm64.tar.gz';
$p = proc_open(['/usr/bin/tar', '-czf', $archive, '-C', $stage, ...$members], [STDIN, STDOUT, STDERR], $pipes);
if (!is_resource($p) || proc_close($p) !== 0) { throw new RuntimeException('actual small fixture archive failed'); }
echo '[package] Actual staged source package: ' . filesize($archive) . ' bytes, SHA256 ' . hash_file('sha256', $archive) . "\n";
$environment = getenv();
$environment['WEBMAN_AOT_REUSE_HOME'] = $previous;
$environment['HOME'] = $root . '/isolated-home';
mkdir($environment['HOME'], 0700);
$p = proc_open(['/bin/sh', $stage . '/install.sh', '--home', $root . '/installed', '--bin-dir', $root . '/bin', '--no-path'],
    [STDIN, STDOUT, STDERR], $pipes, $stage, $environment);
if (!is_resource($p) || proc_close($p) !== 0) { throw new RuntimeException('actual small native reuse installation failed; evidence retained: ' . $root); }
$generations = glob($root . '/installed/toolchains/versions/*', GLOB_ONLYDIR);
if (count($generations) !== 1) { throw new RuntimeException('expected a new verified generation'); }
$expected = json_decode(file_get_contents($repository . '/toolchain/minimal-components.lock.json'), true)['components']['macos-arm64']['manifestSha256'];
if (hash_file('sha256', $generations[0] . '/minimal-component.json') !== $expected) { throw new RuntimeException('target manifest bytes were modified'); }
echo '[PASS] Real small package rebuilt target generation with preserved locked manifest; prior runtime read only.' . "\n";
echo '[evidence] ' . $root . "\n";
