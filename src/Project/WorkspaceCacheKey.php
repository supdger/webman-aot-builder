<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class WorkspaceCacheKey
{
    public function create(
        CoverageLedger $ledger,
        string $toolchainSha256,
        string $rulesSha256,
        string $compilerPatchSha256
    ): string {
        foreach ([
            'toolchain' => $toolchainSha256,
            'rules' => $rulesSha256,
            'compiler-patch' => $compilerPatchSha256,
        ] as $name => $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new ConfigurationException("invalid {$name} digest for workspace cache key");
            }
        }
        $files = $ledger->files();
        usort(
            $files,
            static fn(array $left, array $right): int => $left['path'] <=> $right['path']
        );
        $payload = json_encode([
            'schema' => 'webman-aot-builder-workspace-cache-key-v1',
            'coverage' => $files,
            'toolchainSha256' => $toolchainSha256,
            'rulesSha256' => $rulesSha256,
            'compilerPatchSha256' => $compilerPatchSha256,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $payload);
    }
}
