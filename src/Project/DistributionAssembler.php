<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class DistributionAssembler
{
    public function __construct(
        private readonly DistributionVerifier $verifier = new DistributionVerifier()
    ) {
    }

    /**
     * Package an already compiled ELF. The compiler stage must supply the ELF;
     * this boundary does not fall back to an executable on the host PATH.
     *
     * @param array{
     *   sourceTreeSha256:string,
     *   composerLockSha256:string,
     *   profile:string,
     *   compatibilityLockSha256:string,
     *   toolchainLockSha256:string,
     *   normalizedInputSha256:string,
     *   extensions:list<string>,
     *   arguments:list<string>
     * } $inputs
     * @param list<string> $sensitiveMarkers
     * @param (\Closure(string):void)|null $beforeStage
     * @param list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}> $generatedMappings
     * @param array<string,string> $generatedAdaptations
     * @return array{path:string,previous:?string,verification:array<string,mixed>}
     */
    public function assemble(
        string $projectDirectory,
        string $mirrorDirectory,
        string $elfFile,
        string $compatibilityLockFile,
        string $toolchainLockFile,
        array $inputs,
        string $host,
        array $sensitiveMarkers = [],
        ?\Closure $beforeStage = null,
        array $generatedMappings = [],
        array $generatedAdaptations = []
    ): array {
        $project = realpath($projectDirectory);
        $mirror = realpath($mirrorDirectory);
        $elf = realpath($elfFile);
        if (!is_string($project)
            || !is_string($mirror)
            || !is_string($elf)
            || is_link($projectDirectory)
            || is_link($mirrorDirectory)
            || is_link($elfFile)
            || !ProjectMirror::isOwnedPath($mirror, $project)
            || $this->normalizePath(dirname($elf))
                !== $this->normalizePath($mirror) . '/build'
            || !is_file($elf)
        ) {
            throw new ConfigurationException(
                'distribution assembly requires an owned mirror and compiled ELF'
            );
        }
        $workspace = $project . '/.webman-aot-builder/workspace.json';
        $metadata = $this->readJson($workspace);
        $source = (new SourceTreeSnapshot($project))->capture();
        if (($metadata['schema'] ?? null) !== 'webman-aot-builder-project-workspace-v1'
            || ($metadata['sourceSha256'] ?? null) !== $source['sha256']
            || ($inputs['sourceTreeSha256'] ?? null) !== $source['sha256']
            || ($inputs['composerLockSha256'] ?? null)
                !== $this->digest($project . '/composer.lock')
            || ($inputs['compatibilityLockSha256'] ?? null)
                !== $this->digest($compatibilityLockFile)
            || ($inputs['toolchainLockSha256'] ?? null)
                !== $this->digest($toolchainLockFile)
            || ($inputs['arguments'] ?? null) !== ['--full-static']
        ) {
            throw new ConfigurationException(
                'distribution assembly inputs differ from locked project sources'
            );
        }
        $profile = (new ProfileDetector($mirror))->detect();
        if ($profile->name() !== ($inputs['profile'] ?? null)) {
            throw new ConfigurationException(
                'distribution assembly profile differs from the generated project'
            );
        }
        $sensitiveMarkers = array_values(array_unique(array_merge(
            $sensitiveMarkers,
            [$project, $mirror],
            [str_replace('\\', '/', $project), str_replace('\\', '/', $mirror)],
            [str_replace('/', '\\', $project), str_replace('/', '\\', $mirror)]
        )));
        $beforeStage?->__invoke('coverage');
        $plan = (new GeneratedProjectCoveragePlanner())->plan(
            $mirror,
            $profile,
            $compatibilityLockFile,
            $generatedMappings,
            $generatedAdaptations
        );
        foreach ($plan['resources']->entries() as $resource) {
            if ($resource['kind'] !== 'file') {
                continue;
            }
            if ($this->digest($project . '/' . $resource['path'])
                !== $resource['sourceSha256']
            ) {
                throw new ConfigurationException(
                    "runtime resource differs from original project: {$resource['path']}"
                );
            }
        }

        $build = $project . '/.webman-aot-builder/build';
        $candidate = $build . '/dist-candidate-' . bin2hex(random_bytes(8));
        $beforeStage?->__invoke('candidate');
        if (!mkdir($candidate, 0700)) {
            throw new ConfigurationException('unable to create distribution candidate');
        }
        $beforeStage?->__invoke('binary');
        if (!copy($elf, $candidate . '/server')
            || !chmod($candidate . '/server', 0755)
            || hash_file('sha256', $candidate . '/server') !== hash_file('sha256', $elf)
        ) {
            throw new ConfigurationException('compiled ELF copy failed verification');
        }
        $beforeStage?->__invoke('resources');
        (new RuntimeResourcePackager())->package(
            $mirror,
            $candidate,
            $plan['resources']
        );
        $beforeStage?->__invoke('launchers');
        (new RuntimeLauncherWriter())->write($candidate);
        $beforeStage?->__invoke('manifest');
        (new DistributionManifestWriter())->write(
            $candidate,
            $plan['coverage'],
            $plan['resources'],
            $inputs,
            $host
        );
        $beforeStage?->__invoke('verify');
        $verification = $this->verifier->verify($candidate, $sensitiveMarkers);
        $this->assertSourceUnchanged($project, $source);
        $beforeStage?->__invoke('publish');
        $published = (new DistributionPublisher($project))->publish(
            $candidate,
            function (string $path) use (
                $project,
                $source,
                $sensitiveMarkers
            ): void {
                $this->verifier->verify($path, $sensitiveMarkers);
                $this->assertSourceUnchanged($project, $source);
            }
        );
        return $published + ['verification' => $verification];
    }

    /**
     * @param array{sha256:string,files:int} $expected
     */
    private function assertSourceUnchanged(string $project, array $expected): void
    {
        if ((new SourceTreeSnapshot($project))->capture() !== $expected) {
            throw new ConfigurationException(
                'project source changed during distribution assembly'
            );
        }
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $contents = is_file($path) && !is_link($path)
            ? file_get_contents($path)
            : false;
        if (!is_string($contents)) {
            throw new ConfigurationException('project workspace marker is missing');
        }
        try {
            $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException(
                'project workspace marker is invalid',
                previous: $exception
            );
        }
        if (!is_array($value)) {
            throw new ConfigurationException('project workspace marker is invalid');
        }
        return $value;
    }

    private function digest(string $path): ?string
    {
        $digest = is_file($path) && !is_link($path)
            ? hash_file('sha256', $path)
            : false;
        return is_string($digest) ? $digest : null;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
