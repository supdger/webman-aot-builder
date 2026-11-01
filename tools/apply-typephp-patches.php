#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Toolchain/UnifiedPatchApplier.php';

use WebmanAotBuilder\Toolchain\UnifiedPatchApplier;

/**
 * @return array<string, string>
 */
function patchOptions(array $arguments): array
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

function patchRequired(array $options, string $name): string
{
    $value = rtrim($options[$name] ?? '', '/\\');
    if ($value === '') {
        throw new InvalidArgumentException("missing --{$name}=...");
    }
    return $value;
}

try {
    $options = patchOptions($argv);
    $typephp = patchRequired($options, 'typephp');
    $phpx = patchRequired($options, 'phpx');
    if (!is_dir($typephp) || !is_dir($phpx)) {
        throw new RuntimeException('TypePHP and PHPX source directories must exist');
    }

    $vendoredPhpx = $typephp . '/vendor/swoole/phpx';
    $vendoredRealPath = realpath($vendoredPhpx);
    $phpxRealPath = realpath($phpx);
    if ($vendoredRealPath === false || $phpxRealPath === false || $vendoredRealPath !== $phpxRealPath) {
        throw new RuntimeException('PHPX must be available at TypePHP vendor/swoole/phpx');
    }

    $patchDirectory = dirname(__DIR__) . '/toolchain/patches/typephp/0.9.5';
    $manifest = json_decode(
        (string) file_get_contents($patchDirectory . '/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    $rules = $manifest['rules'] ?? null;
    if (!is_array($rules) || $rules === []) {
        throw new RuntimeException('TypePHP patch manifest has no rules');
    }

    $applier = new UnifiedPatchApplier();
    $applier->verifyManifestCoverage($patchDirectory . '/manifest.json', $rules);
    // The chain is the complete numbered patch directory; a hand-maintained list here once
    // silently dropped 0055-0080 and only surfaced as 17 unexplained afterSha256 mismatches.
    $patches = $applier->enumeratePatches($patchDirectory);
    if ($patches === []) {
        throw new RuntimeException('TypePHP patch chain is empty: ' . $patchDirectory);
    }

    $alreadyApplied = true;
    foreach ($rules as $rule) {
        $target = $typephp . '/' . (string) ($rule['path'] ?? '');
        if (!is_file($target) || is_link($target)
            || hash_file('sha256', $target) !== ($rule['afterSha256'] ?? null)) {
            $alreadyApplied = false;
            break;
        }
    }
    if ($alreadyApplied) {
        fwrite(STDOUT, json_encode(['component' => 'typephp-source', 'version' => $manifest['version'],
            'patches' => count($patches), 'rules' => count($rules), 'status' => 'already-applied-and-verified'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
        exit(0);
    }

    foreach ($rules as $rule) {
        $path = (string) ($rule['path'] ?? '');
        $expected = (string) ($rule['beforeSha256'] ?? '');
        if (array_key_exists('added', $rule)) {
            if ($rule['added'] !== true || $expected !== hash('sha256', '')
                || array_key_exists('preparedBeforeSha256', $rule)
                || file_exists($typephp . '/' . $path) || is_link($typephp . '/' . $path)) {
                throw new RuntimeException("TypePHP added source must be absent before patching: {$path}");
            }
            continue;
        }
        $actual = is_file($typephp . '/' . $path)
            ? hash_file('sha256', $typephp . '/' . $path)
            : false;
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException("TypePHP pristine source digest mismatch: {$path}");
        }
    }

    // Apply the whole chain atomically: every hunk of every patch must match (in memory, in
    // order) before any file is written, so an unmatched hunk never leaves a half-patched tree.
    $appliedPatches = $applier->applyChain($patches, $typephp);
    fwrite(STDERR, sprintf("[patch] applied %d patches: %s .. %s\n",
        count($appliedPatches), $appliedPatches[0], $appliedPatches[count($appliedPatches) - 1]));

    $mismatches = [];
    foreach ($rules as $rule) {
        $path = (string) $rule['path'];
        $expected = (string) $rule['afterSha256'];
        $actual = is_file($typephp . '/' . $path) ? hash_file('sha256', $typephp . '/' . $path) : false;
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            $touchedBy = [];
            foreach ($patches as $patch) {
                if (str_contains((string) file_get_contents($patch), "\n+++ b/{$path}\n")) {
                    $touchedBy[] = basename($patch);
                }
            }
            $mismatches[] = sprintf('%s (expected %s, actual %s; touched by %s)', $path, $expected,
                is_string($actual) ? $actual : 'missing', $touchedBy === [] ? 'no patch' : implode(', ', $touchedBy));
        }
    }
    if ($mismatches !== []) {
        throw new RuntimeException(sprintf(
            "patched TypePHP source digest mismatch in %d of %d rules after applying %d patches:\n  %s",
            count($mismatches), count($rules), count($appliedPatches), implode("\n  ", $mismatches)
        ));
    }

    fwrite(
        STDOUT,
        json_encode(
            [
                'component' => 'typephp-source',
                'version' => $manifest['version'],
                'patches' => count($appliedPatches),
                'rules' => count($rules),
                'status' => 'applied-and-verified',
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        ) . PHP_EOL
    );
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
