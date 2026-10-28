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
        foreach (['llvm-macos-arm64', 'llvm-windows-x64'] as $id) {
            $version = (string) ($components[$id]['version'] ?? '');
            if ($version === '') {
                throw new \RuntimeException("locked LLVM host component is missing: {$id}");
            }
            $llvmVersions[$version] = true;
        }
        if (count($llvmVersions) !== 1) {
            throw new \RuntimeException('Mac and Windows LLVM versions are not aligned');
        }

        $patches = [];
        $patchDirectory = $root . '/toolchain/patches/typephp/0.9.5';
        foreach ([
            '0001-full-static-sdk-target.patch',
            '0002-static-extension-registry.patch',
            '0003-reproducible-source-identities.patch',
            '0004-full-static-host-target-separation.patch',
            '0005-windows-clang-response-paths.patch',
            '0006-pcntl-target-reference-metadata.patch',
            '0010-reproducible-arginfo-source-identity.patch',
            '0011-native-inheritance-and-target-reference-calls.patch',
            '0012-loop-probe-defers-unknown-foreach-vars.patch',
            '0014-default-helpers-and-globals-reference.patch',
            '0015-portable-full-static-magic-dir.patch',
            '0016-embedded-anonymous-class-valid-php.patch',
            '0021-portable-source-scan-order.patch',
            '0022-full-static-hide-host-only-reflection.patch',
            '0024-closure-runtime-binding-and-reference-storage.patch',
            '0026-compiler-command-paths.patch',
            '0027-compiler-runtime-capabilities.patch',
            '0028-ordinary-toarray-method-contracts.patch',
            '0029-unavailable-composer-traits.patch',
            '0030-polymorphic-php-local-storage.patch',
            '0031-persistent-php-reference-storage.patch',
            '0035-target-function-value-storage.patch',
            '0036-request-namespace-function-fallback.patch',
            '0037-php-string-bitwise-not.patch',
            '0039-target-internal-class-declarations.patch',
            '0045-switch-goto-termination.patch',
            '0047-lexical-finally-goto-exits.patch',
            '0053-native-runtime-declarations.patch',
            '0054-load-time-constant-and-list-reference.patch',
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
            'llvmVersion' => array_key_first($llvmVersions),
            'patches' => $patches,
            'fixtureSha256' => $fixtureDigest,
            'build' => [
                'phpVersion' => '8.4',
                'targetTriple' => 'x86_64-unknown-linux-musl',
                'sourcePrefix' => '/usr/src/webman-aot-builder',
                'cxxStandard' => 'c++17',
                'gccVersion' => '12.2.1',
                'allowMultipleDefinition' => true,
                'stripDebug' => true,
                'stripSdkDebug' => true,
                'strippedSdkSha256' => '4d683d1c5e13f90e59f1486e70683cc3ca52f31b2878087bf212d233371c2623',
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
