<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

final class ReproducibilityInput
{
    /**
     * @return array{schema:string,input:array<string, mixed>,sha256:string}
     */
    public function describe(string $root): array
    {
        $root = rtrim($root, '/\\');
        $lock = json_decode(
            (string) file_get_contents($root . '/toolchain.lock.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $components = [];
        foreach ($lock['components'] ?? [] as $component) {
            $components[(string) $component['id']] = $component;
        }

        $selected = [];
        foreach ([
            'php-source',
            'typephp-source',
            'phpx-source',
            'phpx-sdk-linux-x64',
            'musl-source',
            'alpine-musl-dev-x86-64',
            'alpine-linux-headers-x86-64',
            'alpine-libstdcpp-dev-x86-64',
            'alpine-fortify-headers-x86-64',
            'alpine-gcc-x86-64',
        ] as $id) {
            $component = $components[$id] ?? null;
            if (!is_array($component)) {
                throw new \RuntimeException("locked reproducibility component is missing: {$id}");
            }
            $selected[$id] = [
                'version' => (string) ($component['version'] ?? ''),
                'revision' => (string) ($component['revision'] ?? ''),
                'sha256' => (string) ($component['sha256'] ?? ''),
            ];
        }

        $llvmVersions = [];
        $llvmMaterials = [];
        foreach (['llvm-macos-arm64', 'llvm-windows-x64'] as $id) {
            $version = (string) ($components[$id]['version'] ?? '');
            if ($version === '') {
                throw new \RuntimeException("locked LLVM host component is missing: {$id}");
            }
            $llvmVersions[$id] = $version;
            $material = $components[$id];
            if (($material['kind'] ?? null) !== 'host-tool' || preg_match('/^[a-f0-9]{64}$/D', (string) ($material['sha256'] ?? '')) !== 1) {
                throw new \RuntimeException('selected LLVM material identity is invalid: ' . $id);
            }
            $llvmMaterials[$id] = $material['sha256'];
        }
        // Both native preparers execute the same C++17/x86_64 capability probe.
        // Their archive identities remain part of this contract; labels do not establish ABI parity.
        if (($lock['target']['os'] ?? null) !== 'linux'
            || ($lock['target']['architecture'] ?? null) !== 'x86_64'
            || ($lock['target']['libc'] ?? null) !== 'musl') {
            throw new \RuntimeException('host LLVM selections do not share the required Linux x86_64 musl target ABI');
        }

        $patches = [];
        $patchDirectory = $root . '/toolchain/patches/typephp/0.9.2';
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
        ] as $patch) {
            $patches[$patch] = $this->hashNormalizedTextFile(
                $patchDirectory . '/' . $patch,
                "reproducibility patch: {$patch}"
            );
        }

        $fixture = $root . '/toolchain/smoke/main.php';
        $fixtureDigest = $this->hashNormalizedTextFile(
            $fixture,
            'full-static smoke fixture'
        );

        $input = [
            'target' => $lock['target'] ?? null,
            'components' => $selected,
            'llvmVersions' => $llvmVersions,
            'llvmMaterials' => $llvmMaterials,
            'compilerCapabilities' => ['target' => 'x86_64-unknown-linux-musl', 'cxxStandard' => 'c++17', 'pointerBits' => 64],
            'patches' => $patches,
            'fixtureSha256' => $fixtureDigest,
            'build' => [
                'phpVersion' => $lock['evidence']['phpxSdkManifest']['phpVersion'] ?? null,
                'targetTriple' => 'x86_64-unknown-linux-musl',
                'sourcePrefix' => '/usr/src/webman-aot-builder',
                'cxxStandard' => 'c++17',
                'gccVersion' => $components['alpine-gcc-x86-64']['version'] ?? null,
                'allowMultipleDefinition' => true,
                'stripDebug' => true,
                'stripSdkDebug' => true,
                'strippedSdkSha256' => 'bc4b4053092176f8e046a5db0b66c659daa4f23468c09c982223d4fea24d7eeb',
                'removeCommentSection' => true,
                'jobCount' => 4,
            ],
        ];
        $this->sortRecursively($input);
        $canonical = json_encode(
            $input,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return [
            'schema' => 'webman-aot-builder-reproducibility-input-v1',
            'input' => $input,
            'sha256' => hash('sha256', $canonical),
        ];
    }

    private function hashNormalizedTextFile(string $path, string $description): string
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException("unable to hash {$description}");
        }

        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $contents));
    }

    /**
     * @param array<mixed> $value
     */
    private function sortRecursively(array &$value): void
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }
    }
}
