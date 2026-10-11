<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class ProjectDiscovery
{
    public const BUSINESS_PHP = 'business-php';
    public const CONFIG = 'config';
    public const TEMPLATE = 'template';
    public const STATIC_ASSET = 'static';
    public const INSTALL_ONLY = 'install-only';
    public const THIRD_PARTY_DYNAMIC_PHP = 'third-party-dynamic-php';
    public const SOURCE_METADATA = 'source-metadata';
    public const UNCLASSIFIED = 'unclassified';

    /**
     * @param array<string,string> $dynamicPhp Exact third-party path to SHA-256 registrations.
     */
    public function __construct(
        private readonly string $projectDirectory,
        private readonly array $dynamicPhp = []
    ) {
    }

    public function discover(ProjectProfile $profile, bool $useRecordedInputs = true): DiscoveryResult
    {
        if (!is_dir($this->projectDirectory)) {
            throw new ConfigurationException('project discovery requires a real project directory');
        }
        $sourceInputs = $useRecordedInputs ? $this->sourceInputs() : null;
        $files = [];
        $this->scanRoot($files, $sourceInputs);
        foreach ($sourceInputs ?? [] as $path => $present) {
            if (!isset($files[$path])) {
                throw new ConfigurationException("recorded project source is missing or unsafe: {$path}");
            }
        }
        ksort($files, SORT_STRING);

        return new DiscoveryResult(array_values($files));
    }

    /**
     * @param array<string, array{path:string,category:string,owner:string}> $files
     * @param array<string,true>|null $sourceInputs
     */
    private function scanRoot(array &$files, ?array $sourceInputs): void
    {
        $inputs = RuntimeDataPaths::sourceFiles($this->projectDirectory, [
            '.git',
            '.typephp/build',
            '.webman-aot-builder/build',
            '.webman-aot-builder/cache',
            '.webman-aot-builder/runs',
            '.webman-aot-builder/releases',
            '.webman-aot-builder/build.lock',
            '.webman-aot-builder/workspace.json',
        ]);
        foreach ($inputs['files'] as $path => $input) {
            if ($sourceInputs !== null && !isset($sourceInputs[$path])) {
                continue;
            }
            $category = $this->classify($path);
            $record = [
                'path' => $path,
                'category' => $category,
                'owner' => $this->owner($path),
            ];
            if (isset($files[$path]) && $files[$path] !== $record) {
                throw new ConfigurationException("project file has conflicting discovery owners: {$path}");
            }
            $files[$path] = $record;
        }
    }

    /** @return array<string,true>|null */
    private function sourceInputs(): ?array
    {
        return RuntimeDataPaths::originalSourceInputs($this->projectDirectory);
    }

    private function classify(string $path): string
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            return self::BUSINESS_PHP;
        }
        $normalized = strtolower(trim(str_replace('\\', '/', $path), '/'));
        $basename = basename($normalized);
        if ($basename === '.ds_store'
            || preg_match('/^(?:readme|license|changelog|notice)(?:\.(?:md|txt|rst))?$/D', $basename) === 1
            || in_array($normalized, ['composer.json', 'composer.lock', 'project.linux.yml'], true)
        ) {
            return self::SOURCE_METADATA;
        }
        if (str_starts_with($normalized, 'config/')
            || preg_match('#^plugin/[^/]+/config/#D', $normalized) === 1
            || preg_match('#^vendor/saithink/saiadmin/src/plugin/[^/]+/config/#D', $normalized) === 1
        ) {
            return self::CONFIG;
        }
        if (preg_match('#^(?:app/)?(?:view|views|template|templates)/#D', $normalized) === 1
            || preg_match('#^plugin/[^/]+/app/(?:view|views|template|templates)/#D', $normalized) === 1
            || preg_match('#^plugin/[^/]+/utils/code/stub/#D', $normalized) === 1
            || preg_match('#^vendor/saithink/saiadmin/src/plugin/[^/]+/app/(?:view|views|template|templates)/#D', $normalized) === 1
            || preg_match('#^vendor/saithink/saiadmin/src/plugin/[^/]+/utils/code/stub/#D', $normalized) === 1
        ) {
            return self::TEMPLATE;
        }

        return self::STATIC_ASSET;
    }

    private function owner(string $path): string
    {
        if (str_starts_with($path, 'vendor/workerman/webman-framework/src/')) {
            return 'webman-core';
        }
        if (preg_match('#^plugin/([^/]+)/#D', $path, $matches) === 1) {
            return 'plugin:' . $matches[1];
        }
        if (preg_match('#^vendor/([^/]+/[^/]+)/#D', $path, $matches) === 1) {
            return 'dependency:' . $matches[1];
        }
        return 'project';
    }

    private function relative(string $path): string
    {
        $root = rtrim(realpath($this->projectDirectory) ?: $this->projectDirectory, '/\\');
        $normalizedRoot = str_replace('\\', '/', $root);
        $normalizedPath = str_replace('\\', '/', $path);
        $prefix = $normalizedRoot . '/';
        if (!str_starts_with($normalizedPath, $prefix)) {
            throw new ConfigurationException("discovered path escaped project root: {$path}");
        }

        return substr($normalizedPath, strlen($prefix));
    }

    private function absolute(string $relativePath): string
    {
        return rtrim($this->projectDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
