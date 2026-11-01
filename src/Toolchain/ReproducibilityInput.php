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
        // Hash the complete numbered chain exactly as the applier enumerates it; a hand-maintained
        // list here silently excluded newer patches from the reproducibility input.
        $patchPaths = (new UnifiedPatchApplier())->enumeratePatches($patchDirectory);
        if ($patchPaths === []) {
            throw new \RuntimeException('reproducibility patch chain is empty: ' . $patchDirectory);
        }
        foreach ($patchPaths as $patchPath) {
            $patch = basename($patchPath);
            $patches[$patch] = $this->hashNormalizedTextFile(
                $patchPath,
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
