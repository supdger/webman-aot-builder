<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workspace = $argv[1] ?? sys_get_temp_dir() . '/webman-aot-distribution-' . bin2hex(random_bytes(6));
$revision = $argv[2] ?? 'HEAD';
if (!str_starts_with($workspace, DIRECTORY_SEPARATOR) || file_exists($workspace)) {
    throw new RuntimeException('Use a new absolute directory for archive verification.');
}
mkdir($workspace, 0700, true);

function runArchiveCommand(array $command, string $directory): string
{
    fwrite(STDOUT, '[check] ' . implode(' ', $command) . "\n");
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $directory);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start archive check.');
    }
    $output = '';
    while (($line = fgets($pipes[1])) !== false) {
        fwrite(STDOUT, $line);
        $output .= $line;
    }
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Archive command failed.');
    }
    return $output;
}

function extractArchive(string $archive, string $destination): array
{
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        throw new RuntimeException('Cannot open archive.');
    }
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!str_ends_with($name, '/')) {
            $files[] = $name;
        }
    }
    mkdir($destination, 0700, true);
    if (!$zip->extractTo($destination)) {
        throw new RuntimeException('Cannot extract archive.');
    }
    $zip->close();
    return $files;
}

runArchiveCommand(['git', 'archive', '--worktree-attributes', '--format=zip', '--output=' . $workspace . '/source.zip', $revision], $root);
$source = $workspace . '/source';
$files = extractArchive($workspace . '/source.zip', $source);
foreach ($files as $file) {
    if (preg_match('~^(?:\.github/|openspec/|tests/|docs/|tools/test-|tools/fixtures/|packages/composer-installer/tests/)~', $file)) {
        throw new RuntimeException('Development-only file shipped in source archive: ' . $file);
    }
}
foreach (['build.command', 'build.cmd', 'tools/package-installers.php', 'tools/source-bootstrap-macos.sh',
    'tools/source-build-materials.lock', 'installer/runtime.lock.json', 'toolchain.lock.json',
    'toolchain/patches/typephp/0.9.2/manifest.json', 'compatibility/locks/webman-workerman-2026-09-25.json',
    'packages/composer-installer/bin/webman-aot', 'src/Guided/Flow.php'] as $required) {
    if (!in_array($required, $files, true)) {
        throw new RuntimeException('Source build dependency missing: ' . $required);
    }
}
runArchiveCommand([PHP_BINARY, 'tools/guided.php', '--help'], $source);
runArchiveCommand([PHP_BINARY, 'tools/build-macos-installer.php', '--help'], $source);
runArchiveCommand([PHP_BINARY, 'tools/build-windows-installer.php', '--help'], $source);
runArchiveCommand([PHP_BINARY, 'packages/composer-installer/bin/webman-aot', '--version'], $source);
fwrite(STDOUT, 'PASS source archive: ' . count($files) . " files; extracted build entry points work without downloads.\n");

runArchiveCommand([PHP_BINARY, 'tools/package-composer.php', $workspace . '/composer-package'], $root);
$archives = glob($workspace . '/composer-package/*.zip');
if (count($archives) !== 1) {
    throw new RuntimeException('Expected one Composer archive.');
}
$composer = $workspace . '/composer';
$members = extractArchive($archives[0], $composer);
foreach (['README.md', 'README.zh-CN.md', 'composer.json', 'LICENSE'] as $required) {
    if (!in_array($required, $members, true)) {
        throw new RuntimeException('Composer consumer file missing: ' . $required);
    }
}
runArchiveCommand([PHP_BINARY, 'packages/composer-installer/bin/webman-aot', '--help'], $composer);
$version = runArchiveCommand([PHP_BINARY, 'packages/composer-installer/bin/webman-aot', '--version'], $composer);
if (!str_contains($version, 'Composer 入口') || is_dir($composer . '/src') || is_dir($composer . '/tests')) {
    throw new RuntimeException('Composer archive has a broken entry point or development payload.');
}
fwrite(STDOUT, 'PASS Composer archive: ' . count($members) . " verified consumer files; extracted help/version work without installation.\n");
fwrite(STDOUT, 'Archives and extracted verification inputs: ' . $workspace . "\n");
