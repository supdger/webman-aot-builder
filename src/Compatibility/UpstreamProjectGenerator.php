<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class UpstreamProjectGenerator
{
    /**
     * @return array{projectFile:string,projectSha256:string,mainFile:string,mainSha256:string,mappings:int,coverageMappings:list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}>,entrypointMapping:?array,generatorRevision:string,status:string,adaptations:array<string,string>}
     */
    public function generate(
        string $mirror,
        string $archive,
        string $privateCache,
        string $lockFile,
        string $outputName,
        ?string $targetSdkRoot = null,
        ?string $explicitEntry = null
    ): array {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $outputName) !== 1) {
            throw new ConfigurationException('invalid PHP project output name');
        }
        $contents = is_file($lockFile) && !is_link($lockFile) ? file_get_contents($lockFile) : false;
        if (!is_string($contents)) {
            throw new ConfigurationException('upstream generator compatibility lock is missing');
        }
        try {
            $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException('upstream generator compatibility lock is invalid', previous: $exception);
        }
        if (!is_array($lock)
            || ($lock['schema'] ?? null) !== 'webman-aot-builder-upstream-generator-lock-v1'
            || !is_array($lock['generator'] ?? null)
            || ($lock['generator']['sourcePath'] ?? null) !== 'src/Compiler/ProjectGenerator.php'
        ) {
            throw new ConfigurationException('upstream generator compatibility lock shape drifted');
        }
        $generatorRoot = (new UpstreamGeneratorArchive())->materialize($archive, $lock['generator'], $privateCache);
        $generatorFile = $generatorRoot . '/src/Compiler/ProjectGenerator.php';
        $generatorSha256 = $lock['generator']['sourceSha256'];
        $generated = null;
        $manifest = (new UpstreamGeneratorBoundary())->run(
            $mirror,
            $generatorFile,
            $generatorSha256,
            [],
            [],
            static function () use ($mirror, $generatorRoot, $lock, $outputName, $explicitEntry, &$generated): array {
                $generated = (new GenericProjectGenerator())->generate(
                    $mirror,
                    $generatorRoot . '/src/Stubs/main.php.stub',
                    $lock['generator']['mainStubSha256'],
                    $outputName,
                    explicitEntry: $explicitEntry,
                );
                return $generated['mappings'];
            }
        );
        $adaptations = [];
        foreach ($manifest as &$mapping) {
            $mapping['shadowSha256'] = hash_file('sha256', $mirror . '/' . $mapping['shadow']);
        }
        unset($mapping);
        $projectFile = $mirror . '/project.linux.yml';
        $mainFile = $generated['mainFile'] === null ? null : $mirror . '/' . $generated['mainFile'];
        $projectSha256 = hash_file('sha256', $projectFile);
        $mainSha256 = $mainFile === null ? hash('sha256', '') : hash_file('sha256', $mainFile);
        if (!is_string($projectSha256) || !is_string($mainSha256)) {
            throw new ConfigurationException('PHP generator output cannot be hashed');
        }
        return [
            'projectFile' => $projectFile,
            'projectSha256' => $projectSha256,
            'mainFile' => $mainFile,
            'mainSha256' => $mainSha256,
            'mappings' => count($manifest),
            'coverageMappings' => $manifest,
            'entrypointMapping' => $generated['entrypointMapping'],
            'generatorRevision' => $lock['generator']['revision'],
            'status' => is_string($lock['status'] ?? null) ? $lock['status'] : 'unknown',
            'adaptations' => $adaptations,
        ];
    }
}
