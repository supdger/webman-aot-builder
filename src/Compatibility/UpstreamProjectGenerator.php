<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectProfile;

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
        string $profile,
        string $outputName,
        ?string $sdkDirectory = null,
        array $sdkContext = []
    ): array {
        if (!in_array($profile, [ProjectProfile::PHP, ProjectProfile::WEBMAN, ProjectProfile::SAIADMIN], true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $outputName) !== 1
        ) {
            throw new ConfigurationException('invalid PHP project profile or output name');
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
        $targetCapabilities = [];
        if ($sdkDirectory !== null) {
            $policy = $lock['optionalAdaptations']['symfony/polyfill-deepclone'] ?? [];
            if (($policy['sdkPolicy'] ?? null) !== 'selected.prepared-toolchain-sdk.v1') {
                throw new ConfigurationException('generator selected SDK policy is missing');
            }
            $this->assertSelectedSdk($sdkDirectory, $sdkContext);
            $runtimeCapabilities = (new \WebmanAotBuilder\Toolchain\StaticTargetLayout())->runtimeCapabilities($sdkDirectory);
            $targetCapabilities = (new TargetPhpCapabilities())->read($sdkDirectory);
            if ($targetCapabilities['phpVersionId'] !== $runtimeCapabilities['phpVersionId']) {
                throw new ConfigurationException('generator selected SDK runtime metadata is inconsistent');
            }
            $targetCapabilities = array_replace($targetCapabilities, $runtimeCapabilities);
            foreach (['sdkNamespacedFunctionExportsKnown', 'sdkNamespacedFunctionExports', 'sdkFunctionExportsSha256'] as $key) {
                if (($targetCapabilities[$key] ?? null) !== ($sdkContext[$key] ?? null)) { throw new ConfigurationException('generator selected SDK function export evidence drifted: ' . $key); }
            }
            if (($targetCapabilities['phpVersionId'] ?? null) !== $sdkContext['phpVersionId']) {
                throw new ConfigurationException('generator selected SDK runtime capability drifted');
            }
        }
        $targetCapabilities['deferredIntlProviderValidation'] = true;
        $generatorOverlay = (new SaiAdminGeneratorOverlay())->prepare(
            $generatorFile,
            $generatorSha256,
            $lock['generator']['mainStubSha256'],
            $privateCache,
            $mirror,
            $lock['optionalAdaptations']['nesbot/carbon'] ?? [],
            false,
            $targetCapabilities
        );
        $generatorFile = $generatorOverlay['path'];
        $generatorSha256 = $generatorOverlay['sha256'];
        $generatorClass = $generatorOverlay['class'];
        require_once $generatorFile;
        $generated = null;
        $manifest = (new UpstreamGeneratorBoundary())->run(
            $mirror,
            $generatorFile,
            $generatorSha256,
            [],
            [],
            static function () use ($mirror, $generatorRoot, $lock, $outputName, $targetCapabilities, $generatorClass, &$generated): array {
                $generated = (new GenericProjectGenerator())->generate(
                    $mirror,
                    $generatorRoot . '/src/Stubs/main.php.stub',
                    $lock['generator']['mainStubSha256'],
                    $outputName,
                    $lock['dynamicPhp'] ?? [],
                    $targetCapabilities['phpVersion'] ?? null,
                    $targetCapabilities,
                    $generatorClass
                );
                return $generated['mappings'];
            }
        );
        $adaptations = (new GeneratedProjectAdapter())->apply($mirror, $lock, $manifest);
        if (isset($targetCapabilities['sdkFunctionExportsSha256'])) {
            $adaptations['sdkFunctionExportsSha256'] = $targetCapabilities['sdkFunctionExportsSha256'];
        }
        foreach ($manifest as &$mapping) {
            $mapping['shadowSha256'] = hash_file('sha256', $mirror . '/' . $mapping['shadow']);
        }
        unset($mapping);
        $completion = new PluginSourceCompletion();
        $intlShadows = [];
        foreach ($manifest as $mapping) {
            foreach (['grapheme', 'idn', 'normalizer'] as $name) {
                if ($mapping['path'] === "vendor/symfony/polyfill-intl-{$name}/bootstrap80.php") {
                    $intlShadows[$name] = $mapping['shadow'];
                }
            }
        }
        $intlActive = count($intlShadows) === 3;
        foreach (['grapheme', 'idn', 'normalizer'] as $name) {
            $intlActive = $intlActive && $completion->includes($mirror, $intlShadows[$name] ?? '');
        }
        if ($intlActive && $this->supportsNativeIntlSources($mirror, $targetCapabilities, $manifest)) {
            $intl = (new NativeIntlPolyfillRule())->apply($mirror, $lock['optionalAdaptations']['symfony/native-intl-polyfill'] ?? [], $targetCapabilities, $manifest);
            if ($intl !== null) {
                $adaptations['nativeIntlShadowSha256'] = $intl['shadowSha256'];
                $adaptations['nativeIntlProjectSha256'] = $intl['projectSha256'];
                $adaptations['nativeIntlProviderSha256'] = hash('sha256', json_encode($intl['providerShadows'], JSON_THROW_ON_ERROR));
                foreach ($manifest as &$mapping) {
                    if (isset($intl['providerShadows'][$mapping['shadow']])) { $mapping['shadowSha256'] = $intl['providerShadows'][$mapping['shadow']]; }
                    if ($mapping['shadow'] === $intlShadows['grapheme']) {
                        $mapping['shadowSha256'] = $intl['shadowSha256'];
                    }
                }
                unset($mapping);
                // NativeIntl proved these declarations are supplied by the selected SDK.
                foreach (['idn', 'normalizer'] as $name) {
                    $shadow = $intlShadows[$name];
                    $path = $mirror . '/' . $shadow;
                    if (!is_file($path) || is_link($path) || str_replace(DIRECTORY_SEPARATOR, '/', realpath($path) ?: '') !== str_replace(DIRECTORY_SEPARATOR, '/', $path)
                        || file_put_contents($path, "<?php\n", LOCK_EX) !== 6
                    ) {
                        throw new ConfigurationException('unable to preserve native Intl compiler coverage: ' . $shadow);
                    }
                    foreach ($manifest as &$mapping) {
                        if ($mapping['shadow'] === $shadow) {
                            $mapping['shadowSha256'] = hash('sha256', "<?php\n");
                        }
                    }
                    unset($mapping);
                    $adaptations["nativeIntl{$name}ShadowSha256"] = hash('sha256', "<?php\n");
                }
            }
        }
        if ($completion->includes($mirror, 'vendor/symfony/polyfill-deepclone/DeepClone.php')) {
            $deepClone = (new DeepClonePolyfillRule())->apply(
                $mirror,
                $lock['optionalAdaptations']['symfony/polyfill-deepclone'] ?? [],
                $sdkDirectory,
                $sdkContext
            );
            foreach ($deepClone as $mapping) {
                $manifest[] = $mapping;
                $adaptations[$mapping['shadow']] = $mapping['shadowSha256'];
            }
        }
        $pluginSourcesSha256 = $completion->apply($mirror, $profile, $lock['dynamicPhp'] ?? [], $manifest, $generated['entrypointMapping']);
        if ($pluginSourcesSha256 !== null) {
            $adaptations['pluginSourcesSha256'] = $pluginSourcesSha256;
        }
        if ($completion->includes($mirror, 'vendor/monolog/monolog/src/Monolog/Processor/WebProcessor.php')) {
            $monologDigest = (new MonologWebProcessorRule())->apply($mirror, $lock['optionalAdaptations']['monolog/monolog'] ?? []);
            if ($monologDigest !== null) { $adaptations['monologWebProcessorSha256'] = $monologDigest; }
        }
        $projectFile = $mirror . '/project.linux.yml';
        $mainFile = $mirror . '/' . $generated['mainFile'];
        $projectSha256 = hash_file('sha256', $projectFile);
        $mainSha256 = hash_file('sha256', $mainFile);
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

    /** Inspect all inputs before NativeIntl can change a provider or compiler manifest. */
    private function supportsNativeIntlSources(string $mirror, array $capabilities, array $manifest): bool
    {
        if (($capabilities['intlEnabled'] ?? null) !== true) {
            return false;
        }
        $composerPath = $mirror . '/composer.lock';
        if (is_link($composerPath)) {
            throw new ConfigurationException('native Intl preflight Composer lock is unsafe');
        }
        if (!is_file($composerPath)) {
            return false;
        }
        $composerBytes = str_replace(DIRECTORY_SEPARATOR, '/', realpath($composerPath) ?: '') === str_replace(DIRECTORY_SEPARATOR, '/', $composerPath) ? file_get_contents($composerPath) : false;
        if (!is_string($composerBytes)) {
            throw new ConfigurationException('native Intl preflight Composer lock cannot be read safely');
        }
        $composer = json_decode($composerBytes, true);
        if (!is_array($composer) || !is_array($composer['packages'] ?? null)
            || !is_array($composer['packages-dev'] ?? [])
        ) {
            return false;
        }
        $packages = [];
        foreach (array_merge($composer['packages'], $composer['packages-dev'] ?? []) as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null) || isset($packages[$package['name']])) {
                return false;
            }
            $packages[$package['name']] = $package;
        }
        foreach (['grapheme', 'idn', 'normalizer'] as $name) {
            $package = $packages['symfony/polyfill-intl-' . $name] ?? null;
            if (!is_array($package) || !is_string($package['version'] ?? null) || $package['version'] === '') {
                return false;
            }
        }
        $rule = new NativeIntlPolyfillRule();
        $declarations = new \ReflectionMethod($rule, 'declarations');
        $tokens = new \ReflectionMethod($rule, 'tokens');
        $retained = ($capabilities['nativeGraphemeStrrev'] ?? false) ? [] : ['grapheme_strrev'];
        if (!($capabilities['nativeGraphemeLevenshtein'] ?? false)) {
            array_unshift($retained, 'grapheme_levenshtein');
        }
        foreach (['grapheme', 'idn', 'normalizer'] as $name) {
            $source = "vendor/symfony/polyfill-intl-{$name}/bootstrap80.php";
            $mapping = null;
            foreach ($manifest as $entry) {
                if ($entry['path'] === $source) {
                    $mapping = $entry;
                }
            }
            if ($mapping === null) {
                return false;
            }
            $shadow = $mapping['shadow'];
            $contents = [];
            foreach ([$source => 'sourceSha256', $shadow => 'shadowSha256'] as $relative => $digestKey) {
                $path = $mirror . '/' . $relative;
                $bytes = is_file($path) && !is_link($path) && str_replace(DIRECTORY_SEPARATOR, '/', realpath($path) ?: '') === str_replace(DIRECTORY_SEPARATOR, '/', $path) ? file_get_contents($path) : false;
                if (!is_string($bytes) || hash('sha256', $bytes) !== $mapping[$digestKey]) {
                    throw new ConfigurationException('native Intl preflight mapping evidence drifted: ' . $relative);
                }
                $contents[$relative] = $bytes;
            }
            try {
                $original = $declarations->invoke($rule, $contents[$source], $name, true);
                $prepared = $declarations->invoke($rule, $contents[$shadow], $name, false);
                $expected = $name === 'grapheme' && array_diff(array_keys($prepared), $retained) === []
                    ? array_intersect_key($original, array_flip($retained)) : $original;
                if (array_diff_key($expected, $prepared) !== [] || array_diff_key($prepared, $expected) !== []) {
                    fwrite(STDERR, "[adapt] skipped native Intl: unsupported {$name} declaration set remains compiler input\n");
                    return false;
                }
                foreach ($expected as $function => $body) {
                    if (array_column($tokens->invoke($rule, '<?php ' . $body), 'text')
                        !== array_column($tokens->invoke($rule, '<?php ' . $prepared[$function]), 'text')
                    ) {
                        fwrite(STDERR, "[adapt] skipped native Intl: unsupported {$function} conversion remains compiler input\n");
                        return false;
                    }
                }
            } catch (SourceShapeMismatch | \ParseError $exception) {
                fwrite(STDERR, "[adapt] skipped native Intl: {$exception->getMessage()}; original compiler inputs retained\n");
                return false;
            }
        }
        return true;
    }

    /** Recheck the SDK identity supplied by the validated prepared toolchain. */
    private function assertSelectedSdk(string $directory, array $context): void
    {
        $sdk = !is_link($directory) ? realpath($directory) : false;
        $selected = is_string($context['sdkDirectory'] ?? null) && !is_link($context['sdkDirectory'])
            ? realpath($context['sdkDirectory']) : false;
        if (!is_string($sdk) || !is_string($selected) || $sdk !== $selected
            || !is_int($context['phpVersionId'] ?? null) || $context['phpVersionId'] <= 0
            || !is_bool($context['deepcloneEnabled'] ?? null)
        ) { throw new ConfigurationException('generator SDK is not the selected prepared toolchain target'); }
        foreach (['sdkSha256', 'derivationSha256', 'toolchainLockSha256'] as $key) {
            if (!is_string($context[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $context[$key]) !== 1) {
                throw new ConfigurationException('generator selected SDK approval is missing: ' . $key);
            }
        }
        $lockFile = $context['toolchainLockFile'] ?? null;
        $lock = is_string($lockFile) && is_file($lockFile) && !is_link($lockFile) ? file_get_contents($lockFile) : false;
        if (!is_string($lock) || hash('sha256', $lock) !== $context['toolchainLockSha256']) {
            throw new ConfigurationException('generator selected SDK authority lock drifted');
        }
        try { $approval = json_decode($lock, true, flags: JSON_THROW_ON_ERROR)['evidence']['patchedSdk'] ?? null; }
        catch (\JsonException $error) { throw new ConfigurationException('generator selected SDK authority lock is invalid', previous: $error); }
        if (!is_array($approval) || ($approval['sdkSha256'] ?? null) !== $context['sdkSha256']
            || ($approval['derivationSha256'] ?? null) !== $context['derivationSha256']
        ) { throw new ConfigurationException('generator selected SDK identity differs from its authority lock'); }
        try {
            if ((new \WebmanAotBuilder\Toolchain\StaticSdkFingerprint())->digest($sdk) !== $context['sdkSha256']) {
                throw new \RuntimeException('static contents differ from the selected SDK');
            }
            (new \WebmanAotBuilder\Toolchain\SdkArchiveGuard())->assertDerivation($sdk, $context);
        } catch (\RuntimeException $error) {
            throw new ConfigurationException('generator selected SDK drifted: ' . $error->getMessage(), previous: $error);
        }
    }

}
