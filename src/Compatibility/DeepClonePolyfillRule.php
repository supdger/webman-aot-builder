<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Toolchain\SdkArchiveGuard;
use WebmanAotBuilder\Toolchain\StaticSdkFingerprint;

final class DeepClonePolyfillRule
{
    /**
     * The locked PHP 8.4 static SDK has no deepclone module. Select its PHP
     * fallback from verified target SDK contents, never the host's extensions.
     *
     * @return list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}>
     */
    public function apply(string $directory, array $policy, ?string $sdkDirectory): array
    {
        $mirror = realpath($directory);
        if (!is_string($mirror) || is_link($directory)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
        ) {
            throw new ConfigurationException('deepclone adaptation requires an isolated project mirror');
        }
        $composer = json_decode($this->read($mirror . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $packages = array_values(array_filter(
            array_merge($composer['packages'] ?? [], $composer['packages-dev'] ?? []),
            static fn (array $package): bool => ($package['name'] ?? null) === 'symfony/polyfill-deepclone'
        ));
        $prefix = 'vendor/symfony/polyfill-deepclone/';
        if ($packages === [] && !file_exists($mirror . '/' . $prefix)) {
            return [];
        }
        if (count($packages) !== 1
            || ($policy['rule'] ?? null) !== 'symfony.deepclone-php84-fallback.v1'
            || !is_string($packages[0]['version'] ?? null)
            || $packages[0]['version'] === ''
        ) {
            throw new ConfigurationException('deepclone package policy drifted');
        }
        $sdk = is_string($sdkDirectory) && !is_link($sdkDirectory) ? realpath($sdkDirectory) : false;
        if (!is_string($sdk) || !is_dir($sdk)
            || !is_string($policy['sdkSha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $policy['sdkSha256']) !== 1
            || !is_string($policy['derivationSha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $policy['derivationSha256']) !== 1
        ) {
            throw new ConfigurationException('deepclone PHP 8.4 target SDK is missing or unapproved');
        }
        try {
            if ((new StaticSdkFingerprint())->digest($sdk) !== $policy['sdkSha256']) {
                throw new \RuntimeException('static contents differ from the approved SDK');
            }
            (new SdkArchiveGuard())->assertDerivation($sdk, $policy);
        } catch (\RuntimeException $exception) {
            throw new ConfigurationException('deepclone PHP 8.4 target SDK drifted: ' . $exception->getMessage(), previous: $exception);
        }
        $names = ['bootstrap.php', 'bootstrap81.php', 'Resources/stubs/ClassNotFoundException.php',
            'Resources/stubs/NotInstantiableException.php', 'DeepClone.php'];
        $sources = [];
        foreach ($names as $name) {
            $sources[$name] = $this->read($mirror . '/' . $prefix . $name);
            if (($policy['files'][$name] ?? null) !== hash('sha256', $sources[$name])) {
                throw new ConfigurationException("deepclone source drifted: {$name}");
            }
        }
        $shadows = [];
        // bootstrap.php only selects bootstrap81.php for PHP >= 8.1.
        $shadows['bootstrap.php'] = "<?php\n// PHP 8.4 fallback declarations are compiled from deepclone-bootstrap81.php.\n";
        $bootstrap = $sources['bootstrap81.php'];
        $bootstrap = str_replace("if (extension_loaded('deepclone')) {\n    return;\n}\n", '', $bootstrap);
        $bootstrap = preg_replace("/^if \(!(?:function_exists|defined)\('[A-Za-z0-9_]+'\)\) \{\n(.*?)^\}\n/ms", '$1', $bootstrap);
        $bootstrap = preg_replace("/    define\('(DEEPCLONE_[A-Z_]+)', (1 << [012])\);/", 'const $1 = $2;', $bootstrap);
        $shadows['bootstrap81.php'] = $bootstrap;
        foreach (['ClassNotFoundException', 'NotInstantiableException'] as $class) {
            $name = "Resources/stubs/{$class}.php";
            $source = str_replace("if (!\\extension_loaded('deepclone')) {\n", '', $sources[$name]);
            $shadows[$name] = substr($source, 0, -2);
        }
        // Keep the count-or-metadata value in the mixed array slot. TypePHP
        // cannot change the inferred type of a local from int to array.
        $originalMeta = <<<'PHP'
        $n = \count($metaOut);
        foreach ($metaOut as $v) {
            if (0 !== $v) {
                $n = $metaOut;
                break;
            }
        }

        $data = [
            'classes' => 1 === \count($classes) ? $classes[0] : ($classes ?: ''),
            'objectMeta' => $n,
            'prepared' => $prepared,
        ];
PHP;
        $adaptedMeta = <<<'PHP'
        $data = [
            'classes' => 1 === \count($classes) ? $classes[0] : ($classes ?: ''),
            'objectMeta' => \count($metaOut),
            'prepared' => $prepared,
        ];
        foreach ($metaOut as $v) {
            if (0 !== $v) {
                $data['objectMeta'] = $metaOut;
                break;
            }
        }
PHP;
        if (substr_count($sources['DeepClone.php'], $originalMeta) !== 1) {
            throw new ConfigurationException('deepclone metadata source structure drifted');
        }
        $shadows['DeepClone.php'] = str_replace($originalMeta, $adaptedMeta, $sources['DeepClone.php']);
        // The cached entry is either a two-string scope/name tuple or absent.
        // Keep assignment outside the condition; TypePHP rejects a list there.
        $originalScope = '            if ([$scopeName, $realName] = $propertyScopes[$name] ?? null) {';
        $adaptedScope = <<<'PHP'
            $propertyScope = $propertyScopes[$name] ?? null;
            if ($propertyScope) {
                $scopeName = $propertyScope[0];
                $realName = $propertyScope[1];
PHP;
        if (substr_count($sources['DeepClone.php'], $originalScope) !== 1) {
            throw new ConfigurationException('deepclone property scope source structure drifted');
        }
        $shadows['DeepClone.php'] = str_replace($originalScope, $adaptedScope, $shadows['DeepClone.php']);

        // The typed reflector is not used after the parent traversal. Test the
        // ReflectionClass|false result before assigning the next real parent.
        $originalParent = '        while ($class = $class->getParentClass()) {';
        $adaptedParent = "        while (\$parentClass = \$class->getParentClass()) {\n            \$class = \$parentClass;";
        if (substr_count($sources['DeepClone.php'], $originalParent) !== 1) {
            throw new ConfigurationException('deepclone parent reflection source structure drifted');
        }
        $shadows['DeepClone.php'] = str_replace($originalParent, $adaptedParent, $shadows['DeepClone.php']);

        $projectFile = $mirror . '/project.linux.yml';
        $project = $this->read($projectFile);
        if (substr_count($project, "\nignore:\n") !== 1) {
            throw new ConfigurationException('deepclone project source sections drifted');
        }
        $mappings = [];
        $writes = [];
        foreach ($shadows as $name => $source) {
            $relative = $prefix . $name;
            $shadow = '.typephp/build/deepclone-' . basename($name);
            if (!is_string($source) || file_exists($mirror . '/' . $shadow)
                || str_contains($project, "  - {$relative}\n")
                || str_contains($project, "  - {$shadow}\n")
            ) {
                throw new ConfigurationException("deepclone generated source entry drifted: {$name}");
            }
            $project = str_replace("\nignore:\n", "\n  - {$shadow}\n\nignore:\n  - {$relative}\n", $project);
            $writes[$mirror . '/' . $shadow] = $source;
            $mappings[] = ['path' => $relative, 'shadow' => $shadow,
                'sourceSha256' => hash('sha256', $sources[$name]), 'shadowSha256' => hash('sha256', $source)];
        }
        foreach ($writes + [$projectFile => $project] as $path => $source) {
            if (file_put_contents($path, $source, LOCK_EX) === false) {
                throw new ConfigurationException('unable to write deepclone fallback adaptation');
            }
        }
        return $mappings;
    }

    private function read(string $path): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new ConfigurationException('deepclone adaptation source is missing or unsafe');
        }
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new ConfigurationException('deepclone adaptation source is unreadable');
        }
        return $source;
    }
}
