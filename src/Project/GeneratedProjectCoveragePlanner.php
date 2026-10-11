<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class GeneratedProjectCoveragePlanner
{
    /**
     * @return array{
     *   discovery:DiscoveryResult,
     *   coverage:CoverageLedger,
     *   resources:RuntimeResourceManifest,
     *   compiled:array{direct:int,shadow:int}
     * }
     */
    public function plan(
        string $mirrorDirectory,
        ProjectProfile $profile,
        string $compatibilityLockFile,
        array $generatedMappings = [],
        array $generatedAdaptations = []
    ): array {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror)
            || is_link($mirrorDirectory)
            || !ProjectMirror::isOwnedPath($mirror)
        ) {
            throw new ConfigurationException(
                'generated coverage requires an isolated project mirror'
            );
        }
        $lockSource = is_file($compatibilityLockFile)
            && !is_link($compatibilityLockFile)
            ? file_get_contents($compatibilityLockFile)
            : false;
        if (!is_string($lockSource)) {
            throw new ConfigurationException('generated coverage compatibility lock is missing');
        }
        try {
            $lock = json_decode($lockSource, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException(
                'generated coverage compatibility lock is invalid',
                previous: $exception
            );
        }
        if (!is_array($lock)
            || ($lock['schema'] ?? null) !== 'webman-aot-builder-upstream-generator-lock-v1'
            || !is_array($lock['mappings'] ?? null)
            || !is_array($lock['dynamicPhp'] ?? null)
        ) {
            throw new ConfigurationException(
                'generated coverage compatibility lock shape drifted'
            );
        }

        $discovery = (new ProjectDiscovery($mirror, $lock['dynamicPhp']))
            ->discover($profile);
        $businessPaths = [];
        foreach ($discovery->files() as $file) {
            if (strtolower(pathinfo($file['path'], PATHINFO_EXTENSION)) === 'php') {
                $businessPaths[$file['path']] = true;
            }
        }

        $metadata = ProjectMirror::generationMetadataDirectory($mirror);
        $entrypointFile = $metadata !== null ? $metadata . '/entrypoint-mapping.json' : '';
        $hasGeneratedEntrypoint = is_file($entrypointFile);
        if (is_link($entrypointFile)
            || ($hasGeneratedEntrypoint
                && (!is_readable($entrypointFile) || str_replace(DIRECTORY_SEPARATOR, '/', realpath($entrypointFile) ?: '') !== str_replace(DIRECTORY_SEPARATOR, '/', $entrypointFile)))
        ) {
            throw new ConfigurationException('generated entrypoint mapping is unsafe');
        }
        $entrypoint = $lock['entrypointMapping'] ?? null;
        if ($hasGeneratedEntrypoint) {
            $entrypointBytes = file_get_contents($entrypointFile);
            if (!is_string($entrypointBytes)) {
                throw new ConfigurationException('generated entrypoint mapping cannot be read');
            }
            try {
                $entrypoint = json_decode($entrypointBytes, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new ConfigurationException('generated entrypoint mapping is invalid', previous: $exception);
            }
        }
        $mappings = $hasGeneratedEntrypoint ? [] : $lock['mappings'];
        $generatedDirectory = ProjectMirror::generationSourceDirectory($mirror);
        $generatedPrefix = $generatedDirectory !== null ? str_replace(DIRECTORY_SEPARATOR, '/', substr($generatedDirectory, strlen($mirror) + 1)) . '/' : null;
        $aliases = $this->sourceAliases($mirror);
        $seenSources = [];
        $seenShadows = [];
        foreach ($generatedMappings as $generated) {
            if (!is_array($generated)
                || !is_string($generated['path'] ?? null)
                || !is_string($generated['shadow'] ?? null)
                || !is_string($generated['sourceSha256'] ?? null)
                || !is_string($generated['shadowSha256'] ?? null)
            ) {
                throw new ConfigurationException('generated coverage mapping is invalid');
            }
            $source = $generated['path'];
            $shadow = $generated['shadow'];
            if (isset($seenSources[$source]) || (isset($seenShadows[$shadow])
                    && $seenShadows[$shadow] !== $generated['shadowSha256'])
                || (($generatedPrefix === null || !str_starts_with($shadow, $generatedPrefix)) && !self::isSourceAlias($aliases, $source, $shadow)
                    && !(new \WebmanAotBuilder\Compatibility\ConditionalFileLoader())->validateSourceAlias($mirror, $generated))
                || strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== 'php'
                || strtolower(pathinfo($shadow, PATHINFO_EXTENSION)) !== 'php'
            ) {
                throw new ConfigurationException("generated coverage mapping evidence drifted: {$source}");
            }
            if ($this->digest($mirror, $source) !== $generated['sourceSha256']) {
                throw new ConfigurationException("generated coverage source mapping drifted: {$source}");
            }
            if ($this->digest($mirror, $shadow) !== $generated['shadowSha256']) {
                throw new ConfigurationException("generated coverage shadow mapping drifted: {$source}");
            }
            $seenSources[$source] = true;
            $seenShadows[$shadow] = $generated['shadowSha256'];
            $mappings[$source] = $generated;
        }

        $aliasShadows = [];
        foreach ($aliases as $path => $alias) {
            if (!isset($mappings[$path])) { continue; }
            $identity = $alias['canonicalSha256'];
            $shadow = $mappings[$path]['shadow'];
            if (isset($aliasShadows[$identity]) && $aliasShadows[$identity] !== $shadow) {
                throw new ConfigurationException('canonical source aliases have inconsistent compiler replacements');
            }
            $aliasShadows[$identity] = $shadow;
        }

        $decisions = [];
        foreach ($mappings as $source => $mapping) {
            if (!isset($businessPaths[$source])) {
                continue;
            }
            if (!is_array($mapping)
                || !is_string($mapping['shadow'] ?? null)
                || !is_string($mapping['sourceSha256'] ?? null)
                || !is_string($mapping['shadowSha256'] ?? null)
                || $this->digest($mirror, $source) !== $mapping['sourceSha256']
            ) {
                throw new ConfigurationException(
                    "generated coverage source mapping drifted: {$source}"
                );
            }
            $expectedShadowSha256 = $mapping['adaptedShadowSha256']
                ?? $mapping['shadowSha256'];
            if (!is_string($expectedShadowSha256)
                || preg_match('/^[a-f0-9]{64}$/D', $expectedShadowSha256) !== 1
                || $this->digest($mirror, $mapping['shadow'])
                    !== $expectedShadowSha256
            ) {
                throw new ConfigurationException(
                    "generated coverage shadow mapping drifted: {$source}"
                );
            }
            $decisions[$source] = [
                'status' => CoverageLedger::COMPILED_SHADOW,
                'policy' => self::isSourceAlias($aliases, $source, $mapping['shadow'])
                    ? 'php.canonical-source-alias.v1'
                    : (isset($mapping['sourceAlias']) ? 'php.conditional-declaration-loader.v1' : 'upstream.typephp-generated-shadow.v1'),
                'reason' => 'locked upstream AOT replacement is compiled instead of the original',
                'replacement' => $mapping['shadow'],
            ];
        }

        if ($entrypoint !== null && $entrypoint !== []) {
            if (!is_array($entrypoint)
                || !is_string($entrypoint['source'] ?? null)
                || !is_string($entrypoint['sourceSha256'] ?? null)
                || !is_string($entrypoint['replacement'] ?? null)
                || !is_string($entrypoint['replacementSha256'] ?? null)
                || !is_string($entrypoint['policy'] ?? null)
                || $entrypoint['policy'] === ''
                || $this->digest($mirror, $entrypoint['replacement']) !== $entrypoint['replacementSha256']
                || !is_array($entrypoint['sources'] ?? [])
            ) {
                throw new ConfigurationException('generated coverage entrypoint mapping drifted');
            }
            $entrypointSources = $entrypoint['sources'] ?? [];
            $entrypointSources[$entrypoint['source']] = $entrypoint['sourceSha256'];
            foreach ($entrypointSources as $source => $digest) {
                if (!is_string($source) || !is_string($digest)
                    || !isset($businessPaths[$source])
                    || $this->digest($mirror, $source) !== $digest
                    || isset($decisions[$source])
                ) {
                    throw new ConfigurationException('generated coverage entrypoint source drifted');
                }
                $decisions[$source] = [
                    'status' => CoverageLedger::COMPILED_SHADOW,
                    'policy' => $entrypoint['policy'],
                    'reason' => 'the generated compiled entrypoint includes this startup source',
                    'replacement' => $entrypoint['replacement'],
                ];
            }
        } else {
            $entrypoint = null;
        }
        foreach (['Request', 'Response'] as $name) {
            $source = "vendor/workerman/webman-framework/src/support/{$name}.php";
            $replacement = "support/{$name}.php";
            if (!isset($businessPaths[$source], $businessPaths[$replacement])
                || !WebmanSupportOverride::canReplace($mirror, $name)
            ) {
                continue;
            }
            $decisions[$source] = [
                'status' => CoverageLedger::COMPILED_SHADOW,
                'policy' => 'webman.project-support-override.v1',
                'reason' => 'compiled project support class supersedes the inactive framework fallback',
                'replacement' => $replacement,
            ];
        }

        $coverage = (new CoveragePlanner($mirror))->plan($discovery, $decisions);
        $compiled = (new CompilerCoverageAudit($entrypoint, $generatedMappings))->audit(
            $mirror,
            $coverage
        );
        $resources = (new RuntimeResourcePlanner($mirror))->plan(
            $discovery,
            $coverage
        );
        return [
            'discovery' => $discovery,
            'coverage' => $coverage,
            'resources' => $resources,
            'compiled' => $compiled,
        ];
    }

    /** @return array<string,array{canonicalSha256:string,sourceSha256:string,representative:string}> */
    public function sourceAliases(string $mirror): array
    {
        $file = ProjectMirror::sourceAliasManifestPath($mirror);
        if ($file === null || (!file_exists($file) && !is_link($file))) { return []; }
        if (!is_file($file) || !is_readable($file) || is_link($file) || str_replace(DIRECTORY_SEPARATOR, '/', realpath($file) ?: '') !== str_replace(DIRECTORY_SEPARATOR, '/', $file)) { throw new ConfigurationException('mirrored source alias evidence is unsafe'); }
        try { $aliases = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new ConfigurationException('mirrored source alias evidence is invalid', previous: $error); }
        if (!is_array($aliases) || ($aliases['schema'] ?? null) !== 'webman-aot-builder-source-aliases-v1'
            || !is_array($aliases['sources'] ?? null) || !is_array($aliases['links'] ?? null) || !is_array($aliases['cycles'] ?? null)
        ) { throw new ConfigurationException('mirrored source alias evidence shape drifted'); }
        $sources = $aliases['sources'];
        foreach ($sources as $path => $source) {
            if (!is_string($path) || !is_array($source) || !is_string($source['canonicalSha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $source['canonicalSha256']) !== 1
                || !is_string($source['sourceSha256'] ?? null) || $this->digest($mirror, $path) !== $source['sourceSha256']
                || !is_string($source['representative'] ?? null) || !isset($sources[$source['representative']])
                || ($sources[$source['representative']]['canonicalSha256'] ?? null) !== $source['canonicalSha256']
                || ($sources[$source['representative']]['sourceSha256'] ?? null) !== $source['sourceSha256']
                || ($sources[$source['representative']]['representative'] ?? null) !== $source['representative']
            ) { throw new ConfigurationException('mirrored source alias identity drifted: ' . $path); }
        }
        return $sources;
    }

    public static function isSourceAlias(array $aliases, string $source, string $replacement): bool
    {
        return $source !== $replacement && isset($aliases[$source], $aliases[$replacement])
            && $aliases[$source]['canonicalSha256'] === $aliases[$replacement]['canonicalSha256']
            && $aliases[$source]['sourceSha256'] === $aliases[$replacement]['sourceSha256'];
    }

    private function digest(string $mirror, string $relative): ?string
    {
        if ($relative === ''
            || str_starts_with($relative, '/')
            || str_contains($relative, '\\')
            || preg_match('/^[A-Za-z]:/', $relative) === 1
            || in_array('..', explode('/', $relative), true)
            || in_array('.', explode('/', $relative), true)
            || in_array('', explode('/', $relative), true)
        ) {
            return null;
        }
        $componentPath = $mirror;
        foreach (explode('/', $relative) as $component) {
            $componentPath .= '/' . $component;
            if (is_link($componentPath)) {
                return null;
            }
        }
        $path = $mirror . '/' . $relative;
        $digest = is_file($path) && !is_link($path)
            ? hash_file('sha256', $path)
            : false;
        return is_string($digest) ? $digest : null;
    }

}
