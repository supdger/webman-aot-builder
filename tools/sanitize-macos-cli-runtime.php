<?php

declare(strict_types=1);

try {
    if (PHP_OS_FAMILY !== 'Darwin' || php_uname('m') !== 'arm64'
        || count($argv) !== 3
        || !is_file($argv[1])
        || is_link($argv[1])
        || file_exists($argv[2])
        || !is_dir(dirname($argv[2]))
    ) {
        throw new RuntimeException(
            'usage: php sanitize-macos-cli-runtime.php <locked-upstream-php> <new-output>'
        );
    }
    $lock = json_decode(
        file_get_contents(dirname(__DIR__) . '/installer/runtime.lock.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    $expected = $lock['runtimes']['macos-arm64']['upstreamBinarySha256'] ?? null;
    $actual = hash_file('sha256', $argv[1]);
    if (!is_string($expected) || !is_string($actual) || !hash_equals($expected, $actual)) {
        throw new RuntimeException('upstream private CLI PHP does not match the runtime lock');
    }
    $binary = file_get_contents($argv[1]);
    $buildPrefix = '/private/tmp/webman-aot-builder-spc-2.8.5';
    $publicPrefix = '/usr/src/webman-aot-builder/spc-2.8.5/src';
    if (!is_string($binary)
        || strlen($buildPrefix) !== strlen($publicPrefix)
        || substr_count($binary, $buildPrefix) !== 17
    ) {
        throw new RuntimeException('upstream CLI PHP build-path structure drifted');
    }
    $normalized = str_replace($buildPrefix, $publicPrefix, $binary);
    // The pinned upstream PHP contains its CI buildroot in OpenSSL paths.
    $privatePathScan = str_replace(
        '/Users/runner/work/static-php-cli-hosted/',
        '',
        $normalized
    );
    if (str_contains($normalized, '/private/tmp')
        || preg_match('~/(?:Users|home)/[^/\x00]+/~', $privatePathScan) === 1
    ) {
        throw new RuntimeException('normalized CLI PHP still contains a private build path');
    }
    if (file_put_contents($argv[2], $normalized, LOCK_EX) !== strlen($normalized)
        || !chmod($argv[2], 0700)
    ) {
        throw new RuntimeException('unable to create normalized private CLI PHP');
    }
    $sign = proc_open(
        [
            '/usr/bin/codesign',
            '--force',
            '--sign',
            '-',
            '--identifier',
            'org.webman-aot-builder.cli-php',
            $argv[2],
        ],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($sign)) {
        throw new RuntimeException('unable to sign normalized CLI PHP');
    }
    fclose($pipes[0]);
    $message = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($sign) !== 0) {
        throw new RuntimeException('normalized CLI PHP signing failed: ' . trim($message));
    }
    $version = trim((string) shell_exec(escapeshellarg($argv[2]) . ' -n -r ' . escapeshellarg('echo PHP_VERSION;')));
    $capability = proc_open([$argv[2], dirname(__DIR__) . '/tools/check-toolchain-capabilities.php', 'php', $argv[2]], [0 => STDIN, 1 => STDERR, 2 => STDERR], $pipes);
    if (!is_resource($capability) || proc_close($capability) !== 0) {
        throw new RuntimeException('normalized CLI PHP lacks required compiler runtime capabilities');
    }
    echo json_encode([
        'schema' => 'webman-aot-builder-macos-cli-runtime-normalization-v1',
        'upstreamSha256' => $actual,
        'binarySha256' => hash_file('sha256', $argv[2]),
        'replacedBuildPathOccurrences' => 17,
        'phpVersion' => $version,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
