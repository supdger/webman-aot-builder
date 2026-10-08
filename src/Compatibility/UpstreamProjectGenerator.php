<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectProfile;

final class UpstreamProjectGenerator
{
    /**
     * @return array{projectFile:string,projectSha256:string,mainSha256:string,mappings:int,coverageMappings:list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}>,generatorRevision:string,status:string,adaptations:array<string,string>}
     */
    public function generate(
        string $mirror,
        string $archive,
        string $privateCache,
        string $lockFile,
        string $profile,
        string $outputName,
        ?string $sdkDirectory = null
    ): array {
        if (!in_array($profile, [ProjectProfile::WEBMAN, ProjectProfile::SAIADMIN], true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $outputName) !== 1
        ) {
            throw new ConfigurationException('invalid upstream project profile or output name');
        }
        $contents = is_file($lockFile) && !is_link($lockFile)
            ? file_get_contents($lockFile)
            : false;
        if (!is_string($contents)) {
            throw new ConfigurationException('upstream generator compatibility lock is missing');
        }
        try {
            $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException(
                'upstream generator compatibility lock is invalid',
                previous: $exception
            );
        }
        if (!is_array($lock)
            || ($lock['schema'] ?? null) !== 'webman-aot-builder-upstream-generator-lock-v1'
            || !is_array($lock['generator'] ?? null)
            || !is_array($lock['packages'] ?? null)
            || !is_array($lock['mappings'] ?? null)
            || ($lock['generator']['sourcePath'] ?? null) !== 'src/Compiler/ProjectGenerator.php'
        ) {
            throw new ConfigurationException('upstream generator compatibility lock shape drifted');
        }
        $generatorRoot = (new UpstreamGeneratorArchive())->materialize(
            $archive,
            $lock['generator'],
            $privateCache
        );
        $generatorFile = $generatorRoot . '/src/Compiler/ProjectGenerator.php';
        $generatorSha256 = $lock['generator']['sourceSha256'];
        $profileFile = $generatorRoot . '/src/Compiler/Profile/SaiAdminProfile.php';
        $generatorOverlay = (new SaiAdminGeneratorOverlay())->prepare(
            $generatorFile,
            $generatorSha256,
            $lock['generator']['mainStubSha256'],
            $privateCache,
            $mirror,
            $lock['optionalAdaptations']['nesbot/carbon'] ?? [],
            $profile === ProjectProfile::SAIADMIN
        );
        $generatorFile = $generatorOverlay['path'];
        $generatorSha256 = $generatorOverlay['sha256'];
        if ($profile === ProjectProfile::SAIADMIN) {
            $profileFile = (new SaiAdminProfileOverlay())->prepare(
                $profileFile,
                $lock['generator']['profileSha256'],
                $mirror . '/composer.lock',
                $privateCache,
                $lock['optionalAdaptations']['nesbot/carbon'] ?? []
            );
        }
        require_once $profileFile;
        require_once $generatorFile;
        $manifest = (new UpstreamGeneratorBoundary())->run(
            $mirror,
            $generatorFile,
            $generatorSha256,
            $lock['packages'],
            $lock['mappings'],
            static function () use ($mirror, $profile, $outputName): void {
                $generator = new \Tinywan\Typephp\Compiler\ProjectGenerator($mirror);
                $generator->generateMain();
                $generator->generateProjectYml([
                    'profile' => $profile === ProjectProfile::SAIADMIN ? 'saiadmin' : null,
                    'build' => ['output_name' => $outputName],
                ]);
            },
            $profile === ProjectProfile::SAIADMIN
        );
        $adaptations = (new GeneratedProjectAdapter())->apply($mirror, $lock);
        if ($profile === ProjectProfile::SAIADMIN) {
            $adaptations['saiAdminGeneratorOverlaySha256'] = $generatorSha256;
            $adaptations['saiAdminProfileOverlaySha256'] = hash_file('sha256', $profileFile);
            $monologPolicy = $lock['optionalAdaptations']['monolog/monolog'] ?? null;
            if (!is_array($monologPolicy)) {
                throw new ConfigurationException('SaiAdmin Monolog adaptation policy is missing');
            }
            $monologDigest = (new MonologWebProcessorRule())->apply($mirror, $monologPolicy);
            if ($monologDigest !== null) {
                $adaptations['monologWebProcessorSha256'] = $monologDigest;
            }
            $intlPolicy = $lock['optionalAdaptations']['symfony/native-intl-polyfill'] ?? null;
            if (!is_array($intlPolicy)) {
                throw new ConfigurationException('SaiAdmin native intl adaptation policy is missing');
            }
            $intlAdaptation = (new NativeIntlPolyfillRule())->apply($mirror, $intlPolicy);
            $adaptations['nativeIntlShadowSha256'] = $intlAdaptation['shadowSha256'];
            $adaptations['nativeIntlProjectSha256'] = $intlAdaptation['projectSha256'];
            $deepClone = (new DeepClonePolyfillRule())->apply(
                $mirror,
                $lock['optionalAdaptations']['symfony/polyfill-deepclone'] ?? [],
                $sdkDirectory
            );
            foreach ($deepClone as $mapping) {
                $manifest[] = $mapping;
                $adaptations[$mapping['shadow']] = $mapping['shadowSha256'];
            }
        }
        $pluginSourcesSha256 = (new PluginSourceCompletion())->apply(
            $mirror,
            $profile,
            $lock['dynamicPhp'] ?? []
        );
        if ($pluginSourcesSha256 !== null) {
            $adaptations['pluginSourcesSha256'] = $pluginSourcesSha256;
        }
        $projectFile = $mirror . '/project.linux.yml';
        $mainFile = $mirror . '/main.php';
        $projectSha256 = hash_file('sha256', $projectFile);
        $mainSha256 = hash_file('sha256', $mainFile);
        if (!is_string($projectSha256) || !is_string($mainSha256)) {
            throw new ConfigurationException('upstream generator output cannot be hashed');
        }
        return [
            'projectFile' => $projectFile,
            'projectSha256' => $projectSha256,
            'mainSha256' => $mainSha256,
            'mappings' => count($manifest),
            'coverageMappings' => $manifest,
            'generatorRevision' => $lock['generator']['revision'],
            'status' => is_string($lock['status'] ?? null) ? $lock['status'] : 'unknown',
            'adaptations' => $adaptations,
        ];
    }
}
