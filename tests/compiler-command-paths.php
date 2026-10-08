<?php

declare(strict_types=1);

use WebmanAotBuilder\Toolchain\UnifiedPatchApplier;

require dirname(__DIR__) . '/src/Toolchain/UnifiedPatchApplier.php';

if (($argv[1] ?? '') === '--probe') {
    require dirname($argv[2], 2) . '/Build/ExecutableLocator.php';
    require $argv[2];
    echo json_encode([
        'program' => TypePhp\Backend\CompilerFactory::getCommandProgram($argv[3]),
        'supported' => TypePhp\Backend\CompilerFactory::supportsTarget($argv[3], $argv[4]),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function probe(string $source, string $command, string $target): array
{
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--probe', $source, $command, $target],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    ensure(is_resource($process), 'unable to run target probe');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    ensure(proc_close($process) === 0, 'target probe failed: ' . $error);
    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
}

$source = $argv[1] ?? '';
ensure(is_file($source), 'Pass the fixed TypePHP 0.9.2 src/Backend/CompilerFactory.php');
$repository = dirname(__DIR__);
$manifest = json_decode(file_get_contents($repository . '/toolchain/patches/typephp/0.9.2/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$rule = array_values(array_filter($manifest['rules'], static fn (array $entry): bool => $entry['path'] === 'src/Backend/CompilerFactory.php'))[0] ?? null;
ensure(is_array($rule) && hash_file('sha256', $source) === $rule['beforeSha256'], 'fixed CompilerFactory source digest differs');
$backendRule = array_values(array_filter($manifest['rules'], static fn (array $entry): bool => $entry['path'] === 'src/Backend/GccLikeBackend.php'))[0] ?? null;
ensure(is_array($backendRule) && hash_file('sha256', dirname($source) . '/GccLikeBackend.php') === $backendRule['beforeSha256'], 'fixed GCC-like backend source digest differs');
$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-compiler-path-' . bin2hex(random_bytes(6));
mkdir($root . '/src/Backend', 0700, true);
mkdir($root . '/src/Build', 0700, true);
try {
    copy($source, $root . '/src/Backend/CompilerFactory.php');
    copy(dirname($source) . '/GccLikeBackend.php', $root . '/src/Backend/GccLikeBackend.php');
    copy(dirname($source, 2) . '/Build/ExecutableLocator.php', $root . '/src/Build/ExecutableLocator.php');
    $patched = $root . '/src/Backend/CompilerFactory.php';
    $fakeDirectory = $root . "/compiler's directory with spaces";
    mkdir($fakeDirectory, 0700);
    $fake = $fakeDirectory . (PHP_OS_FAMILY === 'Windows' ? '/clang.cmd' : '/clang');
    $log = $root . '/arguments.json';
    $behavior = $root . '/compiler.php';
    file_put_contents($behavior, '<?php file_put_contents(' . var_export($log, true)
        . ', json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR)); exit(in_array("--target=reject", $argv, true) ? 23 : 0);');
    $script = PHP_OS_FAMILY === 'Windows'
        ? '@echo off' . "\r\n" . '"' . PHP_BINARY . '" "' . $behavior . '" %*' . "\r\n"
        : '#!/bin/sh' . "\n" . 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($behavior) . ' "$@"' . "\n";
    file_put_contents($fake, $script);
    chmod($fake, 0700);
    ensure(probe($patched, $fake, 'x86_64-unknown-linux-musl')['supported'] === false, 'original raw space path failure was not reproduced');
    echo "PASS original raw compiler path failure reproduced\n";
    (new UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0026-compiler-command-paths.patch', $root);
    ensure(hash_file('sha256', $patched) === $rule['afterSha256'], 'patched compiler source digest differs');
    ensure(hash_file('sha256', $root . '/src/Backend/GccLikeBackend.php') === $backendRule['afterSha256'], 'patched backend source digest differs');
    foreach ([$fake, '"' . $fake . '"', '"' . $fake . '" --fixture'] as $command) {
        $result = probe($patched, $command, 'x86_64-unknown-linux-musl');
        ensure($result['program'] === $fake && $result['supported'] === true, 'space path or configured arguments rejected');
        $arguments = json_decode(file_get_contents($log), true, flags: JSON_THROW_ON_ERROR);
        ensure(in_array('--target=x86_64-unknown-linux-musl', $arguments, true), 'target argument changed');
        ensure(in_array('-c', $arguments, true) && in_array('-o', $arguments, true), 'compilation probe arguments missing');
        if (str_ends_with($command, '--fixture')) {
            ensure($arguments[0] === '--fixture', 'configured compiler argument lost');
        }
    }
    $upstream = dirname($source, 3);
    spl_autoload_register(static function (string $class) use ($root, $upstream): void {
        $prefix = 'TypePhp\\';
        if (str_starts_with($class, $prefix)) {
            $relative = 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            require is_file($root . '/' . $relative) ? $root . '/' . $relative : $upstream . '/' . $relative;
        }
    });
    $backend = new TypePhp\Backend\Clang(new TypePhp\Platform\Linux(), $fake, $fake);
    $input = $root . '/compile input.c';
    file_put_contents($input, 'void entry(void) {}');
    $object = $root . '/compile output.o';
    foreach ([
        $backend->buildCompileCommand($input, $object),
        $backend->buildCCompileCommand($input, $object),
        $backend->buildNativeCompileCommand($input, $object, [], 'c++-header'),
        $backend->buildLinkCommand([$object], $root . '/linked output'),
    ] as $command) {
        $status = 0;
        exec($command, $lines, $status);
        ensure($status === 0, 'backend command split the executable path');
    }
    echo "PASS actual backend C/C++/native/PCH/link command generation with space paths\n";
    ensure(probe($patched, $fake, 'reject')['supported'] === false, 'compiler target failure accepted');
    ensure(probe($patched, $root . '/missing compiler', 'x86_64-unknown-linux-musl')['supported'] === false, 'missing compiler accepted');
    if (PHP_OS_FAMILY !== 'Windows') {
        $marker = $root . '/injected';
        ensure(probe($patched, '"' . $fake . '" --fixture;touch ' . escapeshellarg($marker), 'x86_64-unknown-linux-musl')['supported'], 'configured literal argument rejected');
        ensure(!file_exists($marker), 'compiler arguments executed an extra command');
    }
    echo "PASS patched raw/quoted executable paths, configured arguments, failed targets, missing files and shell command boundary\n";
    if (isset($argv[2])) {
        $compiler = $argv[2];
        ensure(is_file($compiler) && str_contains($compiler, ' '), 'Pass an actual compiler executable path containing spaces');
        ensure(!probe($source, $compiler, 'x86_64-unknown-linux-musl')['supported'], 'actual original space path failure was not reproduced');
        ensure(probe($patched, $compiler, 'x86_64-unknown-linux-musl')['supported'], 'actual clang target probe failed');
        ensure(probe($patched, '"' . $compiler . '"', 'x86_64-unknown-linux-musl')['supported'], 'actual quoted clang target probe failed');
        echo "PASS actual installed clang target probe through unchanged space path\n";
    }
    ensure(hash_file('sha256', $source) === $rule['beforeSha256'], 'original toolchain source changed');
} finally {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
echo sprintf("PASS completed in %.3fs\n", microtime(true) - $started);
