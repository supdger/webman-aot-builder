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
        ?string $sdkDirectory = null,
        array $sdkContext = []
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
        $targetCapabilities = [];
        if ($sdkDirectory !== null) {
            $policy = $lock['optionalAdaptations']['symfony/polyfill-deepclone'] ?? [];
            if (($policy['sdkPolicy'] ?? null) !== 'selected.prepared-toolchain-sdk.v1') {
                throw new ConfigurationException('generator selected SDK policy is missing');
            }
            $this->assertSelectedSdk($sdkDirectory, $sdkContext);
            $targetCapabilities = (new \WebmanAotBuilder\Toolchain\StaticTargetLayout())->runtimeCapabilities($sdkDirectory);
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
            $profile === ProjectProfile::SAIADMIN,
            $targetCapabilities
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
        $runtimeMappings = (new UpstreamMappingContract())->prepare($mirror, $lock['mappings']);
        $manifest = (new UpstreamGeneratorBoundary())->run(
            $mirror,
            $generatorFile,
            $generatorSha256,
            $lock['packages'],
            $runtimeMappings,
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
        if (hash_file('sha256', $mirror . '/main.php') !== $lock['generator']['mainStubSha256']) {
            throw new ConfigurationException('generated main entrypoint differs from the verified generator stub');
        }
        $adaptations = (new GeneratedProjectAdapter())->apply($mirror, $lock, $manifest);
        if (isset($targetCapabilities['sdkFunctionExportsSha256'])) { $adaptations['sdkFunctionExportsSha256'] = $targetCapabilities['sdkFunctionExportsSha256']; }
        foreach ($manifest as &$mapping) {
            if ($mapping['shadow'] === '.typephp/build/workerman-worker.php') {
                $mapping['shadowSha256'] = $adaptations['workerShadowSha256'];
            }
        }
        unset($mapping);
        $bootstrapPath = $mirror . '/vendor/workerman/webman-framework/src/support/bootstrap.php';
        $bootstrap = is_file($bootstrapPath) && !is_link($bootstrapPath) ? file_get_contents($bootstrapPath) : false;
        if (!is_string($bootstrap)) {
            throw new ConfigurationException('Webman bootstrap entrypoint is missing or unsafe');
        }
        (new BootstrapEntrypointRule())->validate($bootstrap);
        $adaptations['bootstrapSourceSha256'] = hash('sha256', $bootstrap);
        $adaptations['bootstrapReplacementSha256'] = hash_file('sha256', $mirror . '/main.php');
        if ($profile === ProjectProfile::SAIADMIN) {
            $adaptations['saiAdminGeneratorOverlaySha256'] = $generatorSha256;
            $adaptations['saiAdminProfileOverlaySha256'] = hash_file('sha256', $profileFile);
        }
        $completion = new PluginSourceCompletion();
        $intlActive = true;
        foreach (['grapheme', 'idn', 'normalizer'] as $name) {
            $intlActive = $intlActive && $completion->includes($mirror, ".typephp/build/symfony-{$name}-functions.php");
        }
        if ($intlActive) {
            $intl = (new NativeIntlPolyfillRule())->apply($mirror, $lock['optionalAdaptations']['symfony/native-intl-polyfill'] ?? [], $targetCapabilities, $manifest);
            $adaptations['nativeIntlShadowSha256'] = $intl['shadowSha256'];
            $adaptations['nativeIntlProjectSha256'] = $intl['projectSha256'];
            $adaptations['nativeIntlProviderSha256'] = hash('sha256', json_encode($intl['providerShadows'], JSON_THROW_ON_ERROR));
            foreach ($manifest as &$mapping) {
                if (isset($intl['providerShadows'][$mapping['shadow']])) { $mapping['shadowSha256'] = $intl['providerShadows'][$mapping['shadow']]; }
                if ($mapping['shadow'] === '.typephp/build/symfony-grapheme-functions.php') {
                    $mapping['shadowSha256'] = $intl['shadowSha256'];
                }
            }
            unset($mapping);
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
        $pluginSourcesSha256 = $completion->apply($mirror, $profile, $lock['dynamicPhp'] ?? [], $manifest);
        if ($pluginSourcesSha256 !== null) {
            $adaptations['pluginSourcesSha256'] = $pluginSourcesSha256;
        }
        if ($completion->includes($mirror, 'vendor/monolog/monolog/src/Monolog/Processor/WebProcessor.php')) {
            $monologDigest = (new MonologWebProcessorRule())->apply($mirror, $lock['optionalAdaptations']['monolog/monolog'] ?? []);
            if ($monologDigest !== null) { $adaptations['monologWebProcessorSha256'] = $monologDigest; }
        }
        $this->assertIntlFunctionProviders($mirror, $targetCapabilities, $manifest);
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

    /** Validate the two PHP85 Intl fallbacks against the selected SDK and final compiler input. */
    private function assertIntlFunctionProviders(string $mirror, array $capabilities, array $manifest): void
    {
        $active = false;
        foreach ($manifest as $mapping) {
            if (($mapping['path'] ?? null) === 'vendor/symfony/polyfill-php85/bootstrap.php') { $active = true; }
        }
        if (!$active) { return; }
        $resolvedMirror = !is_link($mirror) ? realpath($mirror) : false;
        if (!is_string($resolvedMirror) || !is_dir($resolvedMirror)) {
            throw new ConfigurationException('PHP85 Intl provider mirror is missing or unsafe');
        }
        $mirror = str_replace(DIRECTORY_SEPARATOR, '/', $resolvedMirror);
        foreach (['intlEnabled', 'nativeLocaleIsRightToLeft', 'nativeGraphemeLevenshtein', 'nativeGraphemeStrrev'] as $key) {
            if (!is_bool($capabilities[$key] ?? null)) {
                throw new ConfigurationException("PHP85 Intl provider target capability is missing: {$key}");
            }
        }
        $project = file_get_contents($mirror . '/project.linux.yml');
        if (!is_string($project) || preg_match('/\nsources:\n(.*?)\nignore:\n(.*?)\noutput:/s', $project, $sections) !== 1) {
            throw new ConfigurationException('PHP85 Intl provider compiler selection is invalid');
        }
        $selection = [];
        foreach ([1, 2] as $section) {
            $paths = [];
            foreach (explode("\n", $sections[$section]) as $line) {
                if (trim($line) === '' || str_starts_with(trim($line), '#')) { continue; }
                if (preg_match('/^  - ([A-Za-z0-9_.\/-]+)$/D', $line, $matches) !== 1
                    || str_starts_with($matches[1], '/') || in_array('..', explode('/', $matches[1]), true)
                ) { throw new ConfigurationException('PHP85 Intl provider compiler path is invalid'); }
                $paths[] = rtrim($matches[1], '/');
            }
            $selection[$section] = $paths;
        }
        $covered = static function (string $path, array $paths): bool {
            foreach ($paths as $candidate) {
                if ($path === $candidate || str_starts_with($path, $candidate . '/')) { return true; }
            }
            return false;
        };
        $files = [];
        foreach ($selection[1] as $source) {
            if ($covered($source, $selection[2])) { continue; }
            $absolute = $mirror . '/' . $source;
            $resolved = realpath($absolute);
            $resolved = is_string($resolved) ? str_replace(DIRECTORY_SEPARATOR, '/', $resolved) : $resolved;
            if (!is_string($resolved) || !str_starts_with($resolved, $mirror . '/')) { throw new ConfigurationException('PHP85 Intl provider source escapes the mirror'); }
            if (is_link($absolute)) { throw new ConfigurationException('PHP85 Intl provider input is a symlink'); }
            if (is_file($absolute)) { $files[$source] = $absolute; continue; }
            if (!is_dir($absolute)) { throw new ConfigurationException("PHP85 Intl provider source is missing: {$source}"); }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($mirror) + 1));
                if ($covered($path, $selection[2])) { continue; }
                if ($file->isLink()) { throw new ConfigurationException('PHP85 Intl provider input is a symlink'); }
                if ($file->isFile() && str_ends_with($path, '.php')) { $files[$path] = $file->getPathname(); }
            }
        }
        $digests = [];
        foreach ($manifest as $mapping) {
            foreach (['path' => 'sourceSha256', 'shadow' => 'shadowSha256'] as $pathKey => $digestKey) {
                $digests[$mapping[$pathKey]] = $mapping[$digestKey];
            }
        }
        $providers = ['locale_is_right_to_left' => [], 'grapheme_levenshtein' => [], 'grapheme_strrev' => []];
        foreach ($files as $path => $absolute) {
            if (!str_ends_with($path, '.php')) { continue; }
            $source = file_get_contents($absolute);
            if (!is_string($source) || (isset($digests[$path]) && hash('sha256', $source) !== $digests[$path])) {
                throw new ConfigurationException("PHP85 Intl provider current mapping evidence drifted: {$path}");
            }
            foreach ($this->intlGlobalDeclarations($source) as $name) { $providers[$name][] = $path; }
        }
        foreach (['locale_is_right_to_left' => 'nativeLocaleIsRightToLeft', 'grapheme_levenshtein' => 'nativeGraphemeLevenshtein', 'grapheme_strrev' => 'nativeGraphemeStrrev'] as $name => $key) {
            $expected = !$capabilities['intlEnabled'] || $capabilities[$key] ? 0 : 1;
            if (count($providers[$name]) !== $expected) {
                throw new ConfigurationException("PHP85 Intl provider {$name} expected {$expected} global PHP declarations, got " . count($providers[$name]));
            }
        }
    }

    /** @return list<string> */
    private function intlGlobalDeclarations(string $source): array
    {
        try { $raw = token_get_all($source, TOKEN_PARSE); }
        catch (\ParseError $error) { throw new ConfigurationException('PHP85 Intl provider PHP syntax is invalid', previous: $error); }
        $tokens = [];
        foreach ($raw as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            $tokens[] = is_array($token) ? ['id' => $token[0], 'text' => $token[1]] : ['id' => null, 'text' => $token];
        }
        $namespace = '';
        $pending = null;
        $stack = [];
        $classDepth = 0;
        $functionDepth = 0;
        $declarations = [];
        $conditionalBodies = [];
        foreach ($tokens as $index => $token) {
            if (in_array($token['id'], [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_SWITCH], true)) {
                $depth = 0;
                for ($next = $index + 1; isset($tokens[$next]); ++$next) {
                    if ($tokens[$next]['text'] === '(') { ++$depth; }
                    elseif ($tokens[$next]['text'] === ')' && --$depth === 0) { $conditionalBodies[$next + 1] = true; break; }
                }
            }
            if (isset($conditionalBodies[$index]) && $token['text'] === ':') { $stack[] = ['conditional', null]; }
            if (in_array($token['id'], [T_ENDIF, T_ENDFOR, T_ENDFOREACH, T_ENDWHILE, T_ENDSWITCH], true)
                && ($stack[count($stack) - 1][0] ?? null) === 'conditional'
            ) { array_pop($stack); }
            if ($token['id'] === T_NAMESPACE) {
                $name = '';
                for ($next = $index + 1; isset($tokens[$next]) && !in_array($tokens[$next]['text'], [';', '{'], true); ++$next) { $name .= $tokens[$next]['text']; }
                if (($tokens[$next]['text'] ?? null) === '{') { $pending = ['namespace', $namespace]; }
                $namespace = trim($name, '\\');
            } elseif (in_array($token['id'], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)
                && ($tokens[$index - 1]['id'] ?? null) !== T_DOUBLE_COLON
            ) { $pending = ['class', null]; }
            elseif ($token['id'] === T_FUNCTION) {
                $next = $index + 1;
                if (($tokens[$next]['text'] ?? null) === '&') { ++$next; }
                if ($classDepth === 0 && $functionDepth === 0 && $namespace === '' && ($tokens[$next]['id'] ?? null) === T_STRING) {
                    $name = strtolower($tokens[$next]['text']);
                    if (in_array($name, ['locale_is_right_to_left', 'grapheme_levenshtein', 'grapheme_strrev'], true)) {
                        foreach ($stack as $scope) {
                            if ($scope[0] !== 'namespace') { throw new ConfigurationException("PHP85 Intl provider {$name} declaration is conditional"); }
                        }
                        if (isset($conditionalBodies[$index])) { throw new ConfigurationException("PHP85 Intl provider {$name} declaration is conditional"); }
                        $declarations[] = $name;
                    }
                }
                $pending = ['function', null];
            }
            if ($token['text'] === '{') {
                $scope = $pending ?? ['block', null];
                $stack[] = $scope;
                if ($scope[0] === 'class') { ++$classDepth; }
                if ($scope[0] === 'function') { ++$functionDepth; }
                $pending = null;
            } elseif ($token['text'] === '}') {
                $scope = array_pop($stack);
                if (($scope[0] ?? null) === 'class') { --$classDepth; }
                if (($scope[0] ?? null) === 'function') { --$functionDepth; }
                if (($scope[0] ?? null) === 'namespace') { $namespace = $scope[1]; }
            } elseif ($token['text'] === ';') { $pending = null; }
        }
        return $declarations;
    }
}
