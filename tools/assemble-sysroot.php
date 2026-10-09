#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Cli/ConfigurationException.php';
require dirname(__DIR__) . '/src/Toolchain/StaticTargetLayout.php';

/**
 * @return array<string, string>
 */
function parseOptions(array $arguments): array
{
    $result = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException("invalid option: {$argument}");
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $result[$name] = $value;
    }
    return $result;
}

/**
 * @param list<string> $command
 */
function execute(array $command): void
{
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException("unable to start command: {$command[0]}");
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("command failed with exit code {$exitCode}: {$command[0]}");
    }
}

try {
    $options = parseOptions($argv);
    $artifacts = rtrim($options['artifacts'] ?? '', '/\\');
    $output = rtrim($options['output'] ?? '', '/\\');
    $tar = $options['tar'] ?? 'tar';
    $lockPath = $options['lock'] ?? dirname(__DIR__) . '/toolchain.lock.json';
    if ($artifacts === '' || $output === '') {
        throw new InvalidArgumentException(
            'Usage: php tools/assemble-sysroot.php --artifacts=<download-dir> --output=<empty-dir> [--tar=<tar>] [--lock=<toolchain-lock>]'
        );
    }
    if (!is_dir($artifacts)) {
        throw new RuntimeException("artifact directory does not exist: {$artifacts}");
    }
    if (is_dir($output) && count(array_diff(scandir($output) ?: [], ['.', '..'])) > 0) {
        throw new RuntimeException("sysroot output directory must be empty: {$output}");
    }
    if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
        throw new RuntimeException("unable to create sysroot directory: {$output}");
    }

    $lock = json_decode(
        (string) file_get_contents($lockPath),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    $packages = array_values(array_filter(
        $lock['components'] ?? [],
        static fn(array $component): bool => ($component['kind'] ?? null) === 'sysroot-package'
    ));
    if (count($packages) !== 5) {
        throw new RuntimeException('expected exactly five locked sysroot packages');
    }

    // Windows cannot create Unix links without a separate privilege. These
    // locked APK links name shared libraries and GCC tool aliases, none of
    // which is part of the static sysroot used by this build.
    $windowsLinkExclusions = [
        'alpine-musl-dev-x86-64' => [
            'sha256' => 'd288f1cae4a503826df1ecff8d4c3818b8c765d3e4c63d397fddd3b52de3c17b',
            'paths' => ['usr/lib/libc.so'],
        ],
        'alpine-libstdcpp-dev-x86-64' => [
            'sha256' => '58f70628a343fbddba77261326b6a67437e69b0bc0bf277172137bb3ef1eaf59',
            'paths' => ['usr/lib/libstdc++.so'],
        ],
        'alpine-gcc-x86-64' => [
            'sha256' => 'ff9dc5bf2b8d80cc560c49ce3156d31e88eed94517781ee5e1b30c5ee7db7ea2',
            'paths' => [
                'usr/bin/cc',
                'usr/lib/bfd-plugins/liblto_plugin.so',
                'usr/lib/gcc/x86_64-alpine-linux-musl/*/plugin/libcc1plugin.so',
                'usr/lib/gcc/x86_64-alpine-linux-musl/*/plugin/libcc1plugin.so.0',
                'usr/lib/gcc/x86_64-alpine-linux-musl/*/plugin/libcp1plugin.so',
                'usr/lib/gcc/x86_64-alpine-linux-musl/*/plugin/libcp1plugin.so.0',
                'usr/lib/libatomic.so',
                'usr/lib/libcc1.so',
                'usr/lib/libcc1.so.0',
                'usr/lib/libgomp.so',
                'usr/lib/libitm.so',
                'usr/lib/libitm.so.1',
                'usr/bin/x86_64-alpine-linux-musl-gcc',
                'usr/bin/x86_64-alpine-linux-musl-gcc-*',
                'usr/bin/x86_64-alpine-linux-musl-gcc-ar',
                'usr/bin/x86_64-alpine-linux-musl-gcc-nm',
                'usr/bin/x86_64-alpine-linux-musl-gcc-ranlib',
            ],
        ],
    ];
    foreach ($packages as $package) {
        $archive = $artifacts . '/' . basename((string) $package['sourceUrl']);
        $actualDigest = is_file($archive) ? hash_file('sha256', $archive) : false;
        if (!is_string($actualDigest) || !hash_equals((string) $package['sha256'], $actualDigest)) {
            throw new RuntimeException("sysroot package digest mismatch: {$archive}");
        }
        $command = [$tar];
        if (PHP_OS_FAMILY === 'Windows' && isset($windowsLinkExclusions[$package['id']])) {
            $rule = $windowsLinkExclusions[$package['id']];
            foreach ($rule['paths'] as $path) {
                $command[] = '--exclude=' . $path;
            }
        }
        execute([...$command, '-xf', $archive, '-C', $output]);
    }

    (new WebmanAotBuilder\Toolchain\StaticTargetLayout())->sysroot($output);
    if (!is_file($output . '/usr/include/stdio.h')) {
        throw new RuntimeException('assembled sysroot lacks musl C headers');
    }

    fwrite(
        STDOUT,
        json_encode(
            [
                'target' => 'x86_64-alpine-linux-musl',
                'packages' => array_column($packages, 'id'),
                'output' => $output,
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        ) . PHP_EOL
    );
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
