<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\UpstreamProjectGenerator;
use WebmanAotBuilder\Toolchain\FullStaticProjectOverlay;
use WebmanAotBuilder\Toolchain\TypePhpPatchManifestFingerprint;
use WebmanAotBuilder\Toolchain\TypePhpProjectCompiler;

final class ProjectBuilder
{
    /**
     * @param array{php:string,typephp:string,phpx:string,compiler:string,objcopy:string,sysroot:string,phprc:string,sdkSha256:string} $tools
     * @param (\Closure(string):void)|null $beforeStage
     * @return array{profile:string,sourceSha256:string,normalizedInputSha256:string,elfSha256:string,distribution:array<string,mixed>}
     */
    public function build(
        string $projectDirectory,
        string $generatorArchive,
        string $privateCache,
        string $compatibilityLockFile,
        string $toolchainLockFile,
        string $compilerPatchManifestFile,
        array $tools,
        string $host,
        ?\Closure $beforeStage = null,
        ?string $explicitProfile = null,
        bool $fresh = false
    ): array {
        $project = realpath($projectDirectory);
        if (!is_string($project) || is_link($projectDirectory)) {
            throw new ConfigurationException('build requires a real project directory');
        }
        $lease = new ProjectBuildLease($project);
        $beforeStage?->__invoke('profile');
        $profile = (new ProfileDetector($project))->detect($explicitProfile);
        $compatibilityLock = $this->readLock($compatibilityLockFile, 'compatibility');
        $toolchainLock = $this->readLock($toolchainLockFile, 'toolchain');
        $dynamicPhp = $compatibilityLock['dynamicPhp'] ?? null;
        $extensions = $toolchainLock['requiredExtensions'] ?? null;
        if (!is_array($dynamicPhp) || !is_array($extensions) || $extensions === []) {
            throw new ConfigurationException('build locks lack dynamic PHP or static extension policy');
        }
        $beforeStage?->__invoke('discovery');
        $discovery = (new ProjectDiscovery($project, $dynamicPhp))->discover($profile);
        $coverage = (new CoveragePlanner($project))->plan($discovery);
        $source = (new SourceTreeSnapshot($project))->capture();
        $compatibilitySha256 = $this->digest($compatibilityLockFile);
        $toolchainSha256 = $this->digest($toolchainLockFile);
        if (($tools['sdkContext']['toolchainLockSha256'] ?? null) !== $toolchainSha256
            || realpath($tools['sdkContext']['toolchainLockFile'] ?? '') !== realpath($toolchainLockFile)
        ) { throw new ConfigurationException('selected SDK context belongs to a different build toolchain lock'); }
        $compilerPatchSha256 = (new TypePhpPatchManifestFingerprint())
            ->digest($compilerPatchManifestFile);
        $composerSha256 = $this->digest($project . '/composer.lock');
        $cacheKey = (new WorkspaceCacheKey())->create(
            $profile,
            $coverage,
            $toolchainSha256,
            $compatibilitySha256,
            $compilerPatchSha256
        );
        $beforeStage?->__invoke('workspace');
        $workspace = new ProjectWorkspace($project);
        $paths = $workspace->prepare($cacheKey, $source['sha256']);
        $beforeStage?->__invoke('mirror');
        $mirror = (new ProjectMirror($project))->create($paths['build'])['path'];
        $cache = realpath($privateCache);
        if (!is_string($cache) || !is_dir($cache) || is_link($privateCache)) {
            throw new ConfigurationException('private upstream generator cache is missing or unsafe');
        }
        $beforeStage?->__invoke('generate');
        $generated = (new UpstreamProjectGenerator())->generate(
            $mirror,
            $generatorArchive,
            $cache,
            $compatibilityLockFile,
            $profile->name(),
            'webman-server',
            $tools['phpx'] . '/full-static/sdk',
            $tools['sdkContext'] ?? []
        );
        $beforeStage?->__invoke('overlay');
        (new FullStaticProjectOverlay())->apply(
            $generated['projectFile'],
            $tools['sysroot'],
            $profile->name(),
            $tools['phpx'] . '/full-static/sdk'
        );
        $beforeStage?->__invoke('coverage');
        (new GeneratedProjectCoveragePlanner())->plan(
            $mirror,
            $profile,
            $compatibilityLockFile,
            $generated['coverageMappings'],
            $generated['adaptations']
        );
        $extensionNames = array_keys($extensions);
        sort($extensionNames, SORT_STRING);
        $normalizedInput = [
            'schema' => 'webman-aot-builder-project-normalized-input-v1',
            'sourceTreeSha256' => $source['sha256'],
            'composerLockSha256' => $composerSha256,
            'profile' => $profile->name(),
            'compatibilityLockSha256' => $compatibilitySha256,
            'toolchainLockSha256' => $toolchainSha256,
            'compilerPatchManifestSha256' => $compilerPatchSha256,
            'sdkSha256' => $tools['sdkSha256'],
            'generatedProjectSha256' => $generated['projectSha256'],
            'generatedEntrypointSha256' => $generated['mainSha256'],
            'generatedAdaptations' => $generated['adaptations'],
            'extensions' => $extensionNames,
            'arguments' => ['--full-static'],
        ];
        $normalizedInputSha256 = hash(
            'sha256',
            json_encode($normalizedInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
        $beforeStage?->__invoke('fingerprint');
        $identity = (new \WebmanAotBuilder\Toolchain\CompileInputFingerprint())->digest($tools, [
            'toolchain' => $toolchainSha256,
            'compatibility' => $compatibilitySha256,
            'patches' => $compilerPatchSha256,
            'project' => hash_file('sha256', $generated['projectFile']),
            'profile' => $profile->name(),
            'sdk' => $tools['sdkSha256'],
        ], $beforeStage === null ? null : static fn(string $name) => $beforeStage('fingerprint-' . $name));
        fwrite(STDERR, "[恢复] 已完成单元缓存：{$paths['cache']}/objects；全量重建不会清空缓存。\n");
        $beforeStage?->__invoke('compile');
        $compiled = (new TypePhpProjectCompiler())->compile(
            $mirror,
            'webman-server',
            $tools,
            $paths['cache'] . '/objects',
            $identity,
            $fresh
        );
        $beforeStage?->__invoke('package');
        $distribution = (new DistributionAssembler())->assemble(
            $project,
            $mirror,
            $compiled['artifact'],
            $compatibilityLockFile,
            $toolchainLockFile,
            [
                'sourceTreeSha256' => $source['sha256'],
                'composerLockSha256' => $composerSha256,
                'profile' => $profile->name(),
                'compatibilityLockSha256' => $compatibilitySha256,
                'toolchainLockSha256' => $toolchainSha256,
                'normalizedInputSha256' => $normalizedInputSha256,
                'extensions' => $extensionNames,
                'arguments' => ['--full-static'],
            ],
            $host,
            beforeStage: $beforeStage,
            generatedMappings: $generated['coverageMappings'],
            generatedAdaptations: $generated['adaptations']
        );
        $workspace->finishAttempt($paths['build']);
        return [
            'profile' => $profile->name(),
            'sourceSha256' => $source['sha256'],
            'normalizedInputSha256' => $normalizedInputSha256,
            'elfSha256' => $compiled['sha256'],
            'distribution' => $distribution,
        ];
    }

    /** @return array<string,mixed> */
    private function readLock(string $path, string $name): array
    {
        $contents = is_file($path) && !is_link($path)
            ? file_get_contents($path)
            : false;
        if (!is_string($contents)) {
            throw new ConfigurationException("build {$name} lock is missing");
        }
        try {
            $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException(
                "build {$name} lock is invalid",
                previous: $exception
            );
        }
        if (!is_array($value)) {
            throw new ConfigurationException("build {$name} lock is invalid");
        }
        return $value;
    }

    private function digest(string $path): string
    {
        $digest = is_file($path) && !is_link($path)
            ? hash_file('sha256', $path)
            : false;
        if (!is_string($digest)) {
            throw new ConfigurationException("build input cannot be hashed: " . basename($path));
        }
        return $digest;
    }
}
