<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

final class LockValidator
{
    /**
     * @param array<string, mixed> $lock
     * @return list<string>
     */
    public function validate(array $lock): array
    {
        $errors = [];

        if (($lock['schema'] ?? null) !== 'webman-aot-builder-toolchain-lock-v1') {
            $errors[] = 'unsupported or missing lock schema';
        }

        $target = $lock['target'] ?? null;
        if (!is_array($target)
            || ($target['os'] ?? null) !== 'linux'
            || ($target['architecture'] ?? null) !== 'x86_64'
            || ($target['libc'] ?? null) !== 'musl'
        ) {
            $errors[] = 'target must be linux x86_64 musl';
        }

        $hosts = $lock['hosts'] ?? null;
        if (!is_array($hosts)
            || !in_array('macos-arm64', $hosts, true)
            || !in_array('windows-x86_64', $hosts, true)
        ) {
            $errors[] = 'both supported hosts must be present';
        }

        $componentIds = [];
        $componentHashes = [];
        $components = $lock['components'] ?? null;
        if (!is_array($components) || $components === []) {
            $errors[] = 'components must be a non-empty array';
            $components = [];
        }

        foreach ($components as $index => $component) {
            if (!is_array($component)) {
                $errors[] = "component {$index} must be an object";
                continue;
            }

            $id = $component['id'] ?? null;
            if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9-]*$/D', $id) !== 1) {
                $errors[] = "component {$index} has invalid id";
                continue;
            }
            if (isset($componentIds[$id])) {
                $errors[] = "duplicate component id: {$id}";
            }
            $componentIds[$id] = true;

            foreach (['kind', 'version'] as $field) {
                if (!is_string($component[$field] ?? null) || $component[$field] === '') {
                    $errors[] = "component {$id} has invalid {$field}";
                }
            }

            $sourceUrl = $component['sourceUrl'] ?? null;
            if (!is_string($sourceUrl) || !str_starts_with($sourceUrl, 'https://')) {
                $errors[] = "component {$id} must use an https source URL";
            }

            $sha256 = $component['sha256'] ?? null;
            if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
                $errors[] = "component {$id} has invalid sha256";
            } else {
                $componentHashes[$id] = $sha256;
            }
        }

        $phpSource = null;
        $windowsDriver = null;
        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            if (($component['id'] ?? null) === 'php-source') {
                $phpSource = $component;
            }
            if (($component['id'] ?? null) === 'php-driver-windows-x64') {
                $windowsDriver = $component;
            }
        }
        if (is_array($windowsDriver)
            && ($windowsDriver['kind'] ?? null) !== 'host-tool'
        ) {
            $errors[] = 'selected Windows PHP driver must be a host tool';
        }

        $embeddedIds = [];
        $embeddedLibraries = $lock['embeddedLibraries'] ?? null;
        if (!is_array($embeddedLibraries)) {
            $errors[] = 'embeddedLibraries must be an array';
            $embeddedLibraries = [];
        }

        foreach ($embeddedLibraries as $index => $library) {
            if (!is_array($library)) {
                $errors[] = "embedded library {$index} must be an object";
                continue;
            }

            $id = $library['id'] ?? null;
            if (!is_string($id) || $id === '') {
                $errors[] = "embedded library {$index} has invalid id";
                continue;
            }
            if (isset($embeddedIds[$id]) || isset($componentIds[$id])) {
                $errors[] = "duplicate toolchain id: {$id}";
            }
            $embeddedIds[$id] = true;

            $coveredBy = $library['coveredBy'] ?? null;
            if (!is_string($coveredBy) || !isset($componentIds[$coveredBy])) {
                $errors[] = "embedded library {$id} references an unknown artifact";
                continue;
            }

            $artifactSha256 = $library['artifactSha256'] ?? null;
            if (!is_string($artifactSha256)
                || !hash_equals($componentHashes[$coveredBy] ?? '', $artifactSha256)
            ) {
                $errors[] = "embedded library {$id} does not match its artifact digest";
            }

            if (!is_string($library['sourceUrl'] ?? null)
                || !str_starts_with($library['sourceUrl'], 'https://')
            ) {
                $errors[] = "embedded library {$id} must record an https source URL";
            }
        }

        $providers = $componentIds + $embeddedIds;
        $requiredExtensions = $lock['requiredExtensions'] ?? null;
        if (!is_array($requiredExtensions) || $requiredExtensions === []) {
            $errors[] = 'requiredExtensions must be a non-empty object';
        } else {
            foreach ($requiredExtensions as $extension => $extensionProviders) {
                if (!is_array($extensionProviders) || $extensionProviders === []) {
                    $errors[] = "extension {$extension} has no source provider";
                    continue;
                }
                foreach ($extensionProviders as $provider) {
                    if (!is_string($provider) || !isset($providers[$provider])) {
                        $errors[] = "extension {$extension} references unknown provider";
                    }
                }
            }
        }

        return $errors;
    }
}
