<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;

require_once __DIR__ . "/UnifiedPatchApplier.php";

final class TypePhpPatchSourceVerifier
{
    public function verify(string $typephpDirectory, string $manifestFile): void
    {
        $source = realpath($typephpDirectory);
        $manifest = is_file($manifestFile) && !is_link($manifestFile)
            ? file_get_contents($manifestFile)
            : false;
        if (!is_string($source) || !is_dir($source) || is_link($typephpDirectory)
            || !is_string($manifest)
        ) {
            throw new ConfigurationException('prepared TypePHP patch source or manifest is missing');
        }
        try {
            $rules = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException('TypePHP patch manifest is invalid', previous: $exception);
        }
        if (!is_array($rules)
            || ($rules['component'] ?? null) !== 'typephp-source'
            || !is_string($rules['version'] ?? null)
            || $rules['version'] === ''
            || !is_array($rules['rules'] ?? null)
            || $rules['rules'] === []
        ) {
            throw new ConfigurationException('TypePHP patch manifest shape drifted');
        }
        (new UnifiedPatchApplier())->verifyManifestCoverage($manifestFile, $rules['rules']);
        $seen = [];
        foreach ($rules['rules'] as $rule) {
            $path = is_array($rule) ? ($rule['path'] ?? null) : null;
            $digest = is_array($rule) ? ($rule['afterSha256'] ?? null) : null;
            if (!is_string($path)
                || preg_match('~^(?:composer\.json|(?:src|vendor)/[A-Za-z0-9._/-]+)$~D', $path) !== 1
                || in_array('..', explode('/', $path), true)
                || isset($seen[$path])
                || (array_key_exists('added', $rule) && ($rule['added'] !== true
                    || ($rule['beforeSha256'] ?? null) !== hash('sha256', '')
                    || array_key_exists('preparedBeforeSha256', $rule)))
                || (array_key_exists('preparedBeforeSha256', $rule)
                    && (!is_string($rule['preparedBeforeSha256'])
                        || preg_match('/^[a-f0-9]{64}$/D', $rule['preparedBeforeSha256']) !== 1
                        || $rule['preparedBeforeSha256'] === ($rule['afterSha256'] ?? null)))
                || !is_string($digest)
                || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            ) {
                throw new ConfigurationException('TypePHP patch rule path or digest is invalid');
            }
            $seen[$path] = true;
            $target = $source . '/' . $path;
            $actual = is_file($target) && !is_link($target)
                ? hash_file('sha256', $target)
                : false;
            if (!is_string($actual) || !hash_equals($digest, $actual)) {
                throw new ConfigurationException("prepared TypePHP patch source drifted: {$path}");
            }
        }
    }
}
