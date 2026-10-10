<?php

declare(strict_types=1);

if (PHP_OS_FAMILY !== 'Darwin') { fwrite(STDOUT, '[SKIP] macOS native archive/installation fixture' . PHP_EOL); exit(0); }

// Verify lock generation against an actual locally built small archive; never write repository locks.
$repository = dirname(__DIR__);
$archive = $argv[1] ?? '';
if (!is_file($archive)) { throw new RuntimeException('Pass actual small macOS archive'); }
$source = file_get_contents($repository . '/tools/lock-release-artifacts.php');
$entry = strrpos($source, "\ntry {\n    if (!in_array(\$argc");
if ($entry === false) { throw new RuntimeException('release lock entrypoint changed'); }
eval(substr($source, strlen('<?php'), $entry - strlen('<?php')));
$p = proc_open(['/usr/bin/tar', '-xOzf', $archive, 'package.json'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
if (!is_resource($p)) { throw new RuntimeException('cannot inspect real archive'); }
$identity = json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
fclose($pipes[1]);
if (proc_close($p) !== 0) { throw new RuntimeException('cannot inspect real archive'); }
$root = realpath(getenv('WEBMAN_AOT_TEST_TMP') ?: sys_get_temp_dir()) . '/aot-release-lock-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$result = ['schema' => 'webman-aot-builder-installer-package-result-v1', 'revision' => $identity['revision'],
    'packages' => [['platform' => 'macos-arm64', 'path' => $archive, 'size' => filesize($archive), 'sha256' => hash_file('sha256', $archive)]]];
$path = $root . '/result.json';
file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
try {
    $locked = releaseFullPackage($path, 'macos-arm64', $identity['version'], false);
    if ($locked['sha256'] !== hash_file('sha256', $archive) || $locked['size'] !== filesize($archive)
        || $locked['filename'] !== basename($archive)) { throw new RuntimeException('actual small bytes were not locked'); }
    echo '[PASS] Actual small archive produces exact filename/size/SHA and verifies current target manifest' . "\n";
    $result['packages'][0]['size']++;
    file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
    try {
        releaseFullPackage($path, 'macos-arm64', $identity['version'], false);
        throw new LogicException('fabricated size accepted');
    } catch (RuntimeException $error) {
        if ($error instanceof LogicException) { throw $error; }
        echo '[PASS] Fabricated resource metadata rejected' . "\n";
    }
    mkdir($root . '/ordinary', 0700);
    file_put_contents($root . '/ordinary/package.json', json_encode($identity, JSON_THROW_ON_ERROR));
    $unsupported = $root . '/ordinary/' . basename($archive);
    $p = proc_open(['/usr/bin/tar', '-czf', $unsupported, '-C', $root . '/ordinary', 'package.json'], [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($p) || proc_close($p) !== 0) { throw new RuntimeException('ordinary small identity fixture failed'); }
    $result['packages'][0] = ['platform' => 'macos-arm64', 'path' => $unsupported,
        'size' => filesize($unsupported), 'sha256' => hash_file('sha256', $unsupported)];
    file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
    try {
        releaseFullPackage($path, 'macos-arm64', $identity['version'], false);
        throw new LogicException('small without locked upgrade payload accepted');
    } catch (RuntimeException $error) {
        echo '[PASS] Ordinary small without locked upgrade payload cannot produce upgrade metadata' . "\n";
    }
    unlink($unsupported); unlink($root . '/ordinary/package.json'); rmdir($root . '/ordinary');
    echo json_encode($locked, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} finally { unlink($path); rmdir($root); }
