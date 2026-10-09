#!/usr/bin/env php
<?php
declare(strict_types=1);

// Development-only release helper; the installed bridge does not require ext-zip.
$root = dirname(__DIR__);
require $root . '/packages/composer-installer/src/Installer.php';
try {
    $output = $argv[1] ?? '';
    if ($output === '' || !str_starts_with($output, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Usage: php tools/package-composer.php /absolute/task-output-directory');
    }
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Packaging requires system PHP ext-zip and Composer; the installed bridge does not require ext-zip.');
    }
    $version = Supdger\WebmanAotInstaller\Installer::VERSION;
    $release = json_decode((string) file_get_contents($root . '/packages/composer-installer/resources/releases.json'), true, flags: JSON_THROW_ON_ERROR);
    $runtimeVersion = $release['version'] ?? '';
    if (($release['schema'] ?? null) !== 1 || !is_string($runtimeVersion)
        || !preg_match('/^\d+\.\d+\.\d+$/D', $runtimeVersion)
        || !is_array($release['packages'] ?? null)
        || array_keys($release['packages']) !== ['macos-arm64', 'windows-x86_64']) {
        throw new RuntimeException('Invalid locked native runtime release metadata.');
    }
    foreach ($release['packages'] as $host => $package) {
        $filename = 'webman-aot-builder-' . $runtimeVersion . '-full-' . $host
            . ($host === 'macos-arm64' ? '.tar.gz' : '.zip');
        if (!is_array($package) || ($package['filename'] ?? null) !== $filename
            || ($package['url'] ?? null) !== 'https://github.com/supdger/webman-aot-builder/releases/download/v'
                . $runtimeVersion . '/' . $filename
            || !is_int($package['size'] ?? null) || $package['size'] <= 0
            || !is_string($package['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $package['sha256'])) {
            throw new RuntimeException('Invalid locked native runtime asset: ' . $host);
        }
    }
    if ($runtimeVersion !== $version) {
        throw new RuntimeException('Composer release must bind the same native runtime version.');
    }
    $name = 'webman-aot-builder-' . $version . '-composer';
    $metadata = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($metadata['name'] ?? null) !== 'supdger/webman-aot-builder'
        || ($metadata['type'] ?? null) !== 'composer-plugin'
        || ($metadata['require']['composer-plugin-api'] ?? null) !== '*'
        || ($metadata['require']['composer'] ?? null) !== '*'
        || ($metadata['require']['php'] ?? null) !== '>=8.0'
        || ($metadata['extra']['class'] ?? null) !== 'Supdger\\WebmanAotInstaller\\Plugin'
        || ($metadata['extra']['plugin-optional'] ?? null) !== true
        || ($metadata['bin'] ?? null) !== ['packages/composer-installer/bin/webman-aot']
        || ($metadata['autoload']['psr-4']['Supdger\\WebmanAotInstaller\\'] ?? null) !== 'packages/composer-installer/src/'
        || isset($metadata['version'])
        || ($metadata['dist']['type'] ?? null) !== 'zip'
        || ($metadata['dist']['url'] ?? null) !== 'https://github.com/supdger/webman-aot-builder/releases/download/v' . $version . '/' . $name . '.zip') {
        throw new RuntimeException('Root Composer metadata, bridge version and Release asset URL differ.');
    }
    if (!is_dir($output) && !mkdir($output, 0700, true)) {
        throw new RuntimeException('Cannot create output directory.');
    }
    $resolved = realpath($output);
    if ($resolved === false || $resolved === $root || str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Release output must be outside the source tree.');
    }
    $archive = $resolved . '/' . $name . '.zip';
    if (file_exists($archive)) {
        throw new RuntimeException('Output ZIP already exists; choose a fresh task directory.');
    }
    fwrite(STDOUT, '[package] Creating lightweight Composer ZIP ' . $version . PHP_EOL);
    $process = proc_open(['composer', 'archive', '--format=zip', '--dir=' . $resolved, '--file=' . $name], [STDIN, STDOUT, STDERR], $pipes, $root);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Composer archive failed.');
    }
    $expected = ['LICENSE', 'README.md', 'composer.json', 'packages/composer-installer/bin/webman-aot',
        'packages/composer-installer/resources/extract-windows.ps1', 'packages/composer-installer/resources/releases.json',
        'packages/composer-installer/resources/uninstall-launchers.json', 'packages/composer-installer/src/Uninstaller.php',
        'packages/composer-installer/src/Archive.php', 'packages/composer-installer/src/Console.php', 'packages/composer-installer/src/Installer.php', 'packages/composer-installer/src/Plugin.php', 'packages/composer-installer/src/Process.php'];
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        throw new RuntimeException('Cannot inspect generated ZIP.');
    }
    $actual = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $file = $zip->getNameIndex($i);
        $actual[] = $file;
        if (!in_array($file, $expected, true) || $zip->getFromIndex($i) !== file_get_contents($root . '/' . $file)) {
            throw new RuntimeException('Unexpected or altered archive member: ' . $file);
        }
    }
    $zip->close();
    sort($actual); sort($expected);
    if ($actual !== $expected || filesize($archive) > 100000) {
        throw new RuntimeException('ZIP is incomplete or exceeds the lightweight package size limit.');
    }
    file_put_contents($resolved . '/SHA256SUMS', hash_file('sha256', $archive) . '  ' . basename($archive) . PHP_EOL);
    fwrite(STDOUT, '[package] Verified ' . count($expected) . ' source-identical files, ' . filesize($archive) . ' bytes; ' . $archive . PHP_EOL);
    fwrite(STDOUT, '[package] Native runtime binding: v' . $release['version'] . '; assets remain pinned by filename, size and SHA-256.' . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, '[package failed] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
