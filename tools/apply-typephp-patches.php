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

    $patchDirectory = dirname(__DIR__) . '/toolchain/patches/typephp/0.9.2';
    $manifest = json_decode(
        (string) file_get_contents($patchDirectory . '/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    $rules = $manifest['rules'] ?? null;
    if (!is_array($rules) || $rules === []) {
        throw new RuntimeException('TypePHP patch manifest has no rules');
    }

    (new UnifiedPatchApplier())->verifyManifestCoverage($patchDirectory . '/manifest.json', $rules);

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
            'patches' => 51, 'rules' => count($rules), 'status' => 'already-applied-and-verified'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
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

    $applier = new UnifiedPatchApplier();
    foreach ([
        '0001-full-static-sdk-target.patch',
        '0002-static-extension-registry.patch',
        '0003-reproducible-source-identities.patch',
        '0004-full-static-host-target-separation.patch',
        '0005-windows-clang-response-paths.patch',
        '0006-pcntl-target-reference-metadata.patch',
        '0007-full-static-skip-host-php-import-libs.patch',
        '0008-full-static-linux-internal-metadata.patch',
        '0009-full-static-linux-builtin-lifetime.patch',
        '0010-reproducible-arginfo-source-identity.patch',
        '0011-native-inheritance-and-target-reference-calls.patch',
        '0012-loop-probe-defers-unknown-foreach-vars.patch',
        '0013-namespaced-global-function-dependencies.patch',
        '0014-default-helpers-and-globals-reference.patch',
        '0015-portable-full-static-magic-dir.patch',
        '0016-embedded-anonymous-class-valid-php.patch',
        '0017-full-static-anonymous-source-embedding.patch',
        '0018-full-static-target-php-eol.patch',
        '0019-full-static-target-internal-functions.patch',
        '0020-full-static-target-constants-and-host-functions.patch',
        '0021-portable-source-scan-order.patch',
        '0022-full-static-hide-host-only-reflection.patch',
        '0023-full-static-select-target-reflection.patch',
        '0024-closure-runtime-binding-and-reference-storage.patch',
        '0025-verified-object-checkpoints.patch',
        '0026-compiler-command-paths.patch',
        '0027-compiler-runtime-capabilities.patch',
        '0028-ordinary-toarray-method-contracts.patch',
        '0029-unavailable-composer-traits.patch',
        '0030-polymorphic-php-local-storage.patch',
        '0031-persistent-php-reference-storage.patch',
        '0032-closure-exception-cleanup.patch',
        '0033-mutable-php-value-parameters.patch',
        '0034-native-boundary-reference-overrides.patch',
        '0035-target-function-value-storage.patch',
        '0036-request-namespace-function-fallback.patch',
        '0037-php-string-bitwise-not.patch',
        '0038-known-php-local-value-joins.patch',
        '0039-target-internal-class-declarations.patch',
        '0040-pure-php-call-return-prediction.patch',
        '0041-lazy-missing-composer-interfaces.patch',
        '0042-ordinary-constructor-return-values.patch',
        '0043-ordinary-destructor-return-values.patch',
        '0044-related-php-object-local-joins.patch',
        '0045-switch-goto-termination.patch',
        '0046-final-switch-case-exit.patch',
        '0047-lexical-finally-goto-exits.patch',
        '0048-php-catch-local-value-storage.patch',
        '0049-goto-safe-expression-temporaries.patch',
        '0050-foreach-list-local-value-storage.patch',
        '0051-switch-selector-value-lifetime.patch',
    ] as $patch) {
        $applier->apply($patchDirectory . '/' . $patch, $typephp);
    }

    foreach ($rules as $rule) {
        $path = (string) $rule['path'];
        $expected = (string) $rule['afterSha256'];
        $actual = hash_file('sha256', $typephp . '/' . $path);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException("patched TypePHP source digest mismatch: {$path}");
        }
    }

    fwrite(
        STDOUT,
        json_encode(
            [
                'component' => 'typephp-source',
                'version' => $manifest['version'],
                'patches' => 51,
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
