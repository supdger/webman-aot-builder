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
        string $compatibilityLockFile,
        array $generatedMappings = []
    ): array {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror)
            || is_link($mirrorDirectory)
            || !str_ends_with(
                str_replace('\\', '/', $mirror),
                '/.webman-aot-builder/build/project'
            )
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
            ->discover();
        $coverage = (new CoveragePlanner($mirror))->plan($discovery);
        $compiled = (new CompilerCoverageAudit())->audit($mirror, $coverage);
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
