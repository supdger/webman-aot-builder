<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class CoveragePlanner
{
    private const RUNTIME_POLICIES = [
        ProjectDiscovery::CONFIG => [
            'policy' => 'runtime.config.v1',
            'reason' => 'configuration remains externally editable at runtime',
        ],
        ProjectDiscovery::TEMPLATE => [
            'policy' => 'runtime.template.v1',
            'reason' => 'templates are packaged runtime resources',
        ],
        ProjectDiscovery::STATIC_ASSET => [
            'policy' => 'runtime.static.v1',
            'reason' => 'static assets are packaged runtime resources',
        ],
    ];

    public function __construct(private readonly string $projectDirectory)
    {
    }

    /**
     * @param array<string, array{status:string,policy?:string,reason?:string,replacement?:string}> $decisions
     */
    public function plan(DiscoveryResult $discovery, array $decisions = []): CoverageLedger
    {
        $discovered = [];
        $ledger = [];
        foreach ($discovery->files() as $file) {
            $path = $file['path'];
            $discovered[$path] = true;
            if ($file['category'] === ProjectDiscovery::UNCLASSIFIED) {
                throw new ConfigurationException("project file is unclassified: {$path}");
            }

            $sourceSha256 = $this->digestProjectFile($path);
            $decision = $decisions[$path] ?? null;
            if ($file['category'] === ProjectDiscovery::BUSINESS_PHP) {
                $ledger[] = $this->planBusinessPhp(
                    $file,
                    $sourceSha256,
                    $decision
                );
                continue;
            }
            if ($decision !== null) {
                throw new ConfigurationException(
                    "coverage decision cannot override resource classification: {$path}"
                );
            }
            if ($file['category'] === ProjectDiscovery::INSTALL_ONLY) {
                $webmanScaffold = str_starts_with(
                    $path,
                    'vendor/workerman/webman-framework/src/'
                );
                $ledger[] = $this->record(
                    $file,
                    $sourceSha256,
                    CoverageLedger::INSTALL_ONLY,
                    $webmanScaffold
                        ? 'install.webman-framework-scaffold.v1'
                        : 'install.webman-plugin.v1',
                    $webmanScaffold
                        ? 'Composer installer and launcher source templates are not runtime inputs'
                        : 'installation and migration code is excluded from the running application'
                );
                continue;
            }
            if ($file['category'] === ProjectDiscovery::SOURCE_METADATA) {
                $ledger[] = $this->record(
                    $file,
                    $sourceSha256,
                    CoverageLedger::INSTALL_ONLY,
                    'source.metadata.v1',
                    'known source metadata and documentation are not runtime inputs'
                );
                continue;
            }
            if ($file['category'] === ProjectDiscovery::THIRD_PARTY_DYNAMIC_PHP) {
                $ledger[] = $this->record(
                    $file,
                    $sourceSha256,
                    CoverageLedger::RUNTIME_APPROVED,
                    'runtime.third-party-dynamic.v1',
                    'structurally verified third-party view adapter uses runtime template behavior'
                );
                continue;
            }
            $policy = self::RUNTIME_POLICIES[$file['category']] ?? null;
            if ($policy === null) {
                throw new ConfigurationException(
                    "project file category has no coverage policy: {$path} ({$file['category']})"
                );
            }
            $ledger[] = $this->record(
                $file,
                $sourceSha256,
                CoverageLedger::RUNTIME_APPROVED,
                $policy['policy'],
                $policy['reason']
            );
        }

        foreach (array_keys($decisions) as $path) {
            if (!isset($discovered[$path])) {
                throw new ConfigurationException(
                    "coverage decision references an undiscovered file: {$path}"
                );
            }
        }
        usort(
            $ledger,
            static fn(array $left, array $right): int => $left['path'] <=> $right['path']
        );

        return new CoverageLedger($ledger);
    }

    /**
     * @param array{path:string,category:string,owner:string} $file
     * @param array{status:string,policy?:string,reason?:string,replacement?:string}|null $decision
     * @return array<string, string|null>
     */
    private function planBusinessPhp(
        array $file,
        string $sourceSha256,
        ?array $decision
    ): array {
        $path = $file['path'];
        $status = $decision['status'] ?? CoverageLedger::COMPILED_DIRECT;
        if ($status === CoverageLedger::RUNTIME_APPROVED
            || $status === CoverageLedger::INSTALL_ONLY
        ) {
            throw new ConfigurationException(
                "business PHP cannot bypass AOT compilation: {$path} ({$status})"
            );
        }
        if ($status === CoverageLedger::COMPILED_DIRECT) {
            if ($decision !== null && count($decision) !== 1) {
                throw new ConfigurationException(
                    "compiled-direct coverage decision cannot carry replacement policy: {$path}"
                );
            }

            return $this->record(
                $file,
                $sourceSha256,
                CoverageLedger::COMPILED_DIRECT
            );
        }
        if ($status !== CoverageLedger::COMPILED_SHADOW) {
            throw new ConfigurationException("unknown coverage status {$status}: {$path}");
        }

        $policy = $decision['policy'] ?? '';
        $reason = $decision['reason'] ?? '';
        $replacement = $decision['replacement'] ?? '';
        if ($policy === '' || $reason === '' || $replacement === '') {
            throw new ConfigurationException(
                "compiled-shadow requires policy, reason, and replacement: {$path}"
            );
        }
        if ($replacement === $path) {
            throw new ConfigurationException("compiled-shadow must use a distinct AOT copy: {$path}");
        }
        $record = $this->record(
            $file,
            $sourceSha256,
            CoverageLedger::COMPILED_SHADOW,
            $policy,
            $reason
        );
        $record['replacement'] = $replacement;
        $record['replacementSha256'] = $this->digestProjectFile($replacement);

        return $record;
    }

    /**
     * @param array{path:string,category:string,owner:string} $file
     * @return array<string, string|null>
     */
    private function record(
        array $file,
        string $sourceSha256,
        string $status,
        ?string $policy = null,
        ?string $reason = null
    ): array {
        return [
            'path' => $file['path'],
            'sourceSha256' => $sourceSha256,
            'category' => $file['category'],
            'owner' => $file['owner'],
            'status' => $status,
            'policy' => $policy,
            'reason' => $reason,
        ];
    }

    private function digestProjectFile(string $relativePath): string
    {
        if ($relativePath === ''
            || str_starts_with($relativePath, '/')
            || preg_match('/^[A-Za-z]:/', $relativePath) === 1
            || in_array('..', explode('/', str_replace('\\', '/', $relativePath)), true)
        ) {
            throw new ConfigurationException("coverage path is unsafe: {$relativePath}");
        }
        $path = rtrim($this->projectDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path) || is_link($path)) {
            throw new ConfigurationException("coverage file is missing or unsafe: {$relativePath}");
        }
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new ConfigurationException("unable to hash coverage file: {$relativePath}");
        }

        return $digest;
    }
}
