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
        if (!is_dir($this->projectDirectory) || is_link($this->projectDirectory)) {
            throw new ConfigurationException('project discovery requires a real project directory');
        }
        RuntimeDataPaths::assertSafeWritableDirectories($this->projectDirectory);
        $sourceInputs = $useRecordedInputs ? $this->sourceInputs() : null;
        $explicitSources = [];
        $files = [];
        $this->scanRoot($files, $sourceInputs, $explicitSources);
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
     * @param list<string> $explicitSources
     */
    private function scanRoot(array &$files, ?array $sourceInputs, array $explicitSources): void
    {
        $sourceDirectories = [];
        foreach (array_keys($sourceInputs ?? []) as $path) {
            for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) {
                $sourceDirectories[$parent] = true;
            }
        }
        $absoluteRoot = realpath($this->projectDirectory) ?: $this->projectDirectory;
        $directory = new \RecursiveDirectoryIterator(
            $absoluteRoot,
            \FilesystemIterator::SKIP_DOTS
        );
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            function (\SplFileInfo $entry) use ($sourceInputs, $sourceDirectories): bool {
                $relative = $this->relative($entry->getPathname());
                $root = explode('/', $relative, 2)[0];
                if (in_array($root, [
                    '.git', '.typephp', '.webman-aot-builder', '.webman-aot',
                    'dist-aot',
                ], true)
                    || (RuntimeDataPaths::isWritableDataPath($relative)
                        && !$entry->isDir() && strtolower($entry->getExtension()) !== 'php')
                    || (preg_match('/^\.env(?:\..+)?$/D', $entry->getFilename()) === 1
                        && strtolower($entry->getExtension()) !== 'php')
                    || (in_array($root, ['runtime', 'node_modules'], true)
                        && !$entry->isDir() && strtolower($entry->getExtension()) !== 'php')
                ) {
                    return false;
                }
                if ($sourceInputs !== null
                    && !isset($sourceInputs[$relative])
                    && !isset($sourceDirectories[$relative])
                ) {
                    return false;
                }
                if ($entry->isLink()) {
                    throw new ConfigurationException("project discovery refuses symlink: {$relative}");
                }
                return true;
            }
        );
        $iterator = new \RecursiveIteratorIterator($filter);
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                $relative = $this->relative($entry->getPathname());
                throw new ConfigurationException("project discovery refuses symlink: {$relative}");
            }
            if (!$entry->isFile()) {
                continue;
            }
            $path = $this->relative($entry->getPathname());
            $category = $this->classify($path, $explicitSources);
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
        $mirror = realpath($this->projectDirectory);
        if (!is_string($mirror)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
        ) {
            return null;
        }
        $relative = '.typephp/build/source-inputs.json';
        $path = $this->absolute($relative);
        $componentPath = rtrim($this->projectDirectory, '/\\');
        foreach (explode('/', $relative) as $component) {
            $componentPath .= DIRECTORY_SEPARATOR . $component;
            if (is_link($componentPath)) {
                throw new ConfigurationException('project source input manifest is unsafe');
            }
        }
        if (!file_exists($path)) {
            return null;
        }
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($bytes)) {
            throw new ConfigurationException('project source input manifest cannot be read');
        }
        try {
            $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException('project source input manifest is invalid', previous: $exception);
        }
        if (!is_array($manifest)
            || ($manifest['schema'] ?? null) !== 'webman-aot-builder-source-inputs-v1'
            || !is_array($manifest['paths'] ?? null)
            || !array_is_list($manifest['paths'])
            || $manifest['paths'] === []
        ) {
            throw new ConfigurationException('project source input manifest is invalid');
        }
        $paths = [];
        foreach ($manifest['paths'] as $source) {
            if (!is_string($source) || $source === ''
                || str_contains($source, '\\')
                || str_starts_with($source, '/')
                || preg_match('/^[A-Za-z]:/', $source) === 1
                || in_array('..', explode('/', $source), true)
                || in_array('.', explode('/', $source), true)
                || in_array('', explode('/', $source), true)
                || isset($paths[$source])
            ) {
                throw new ConfigurationException('project source input manifest contains an unsafe or duplicate path');
            }
            $paths[$source] = true;
        }
        return $paths;
    }

    /** @param list<string> $explicitSources */
    private function classify(string $path, array $explicitSources): string
    {
        $normalized = strtolower(trim(str_replace('\\', '/', $path), '/'));
        $basename = basename($normalized);
        if ($basename === '.ds_store'
            || preg_match('/^(?:readme|license|changelog|notice)(?:\.(?:md|txt|rst))?$/D', $basename) === 1
            || in_array($normalized, ['composer.json', 'composer.lock', 'project.linux.yml'], true)
        ) {
            return self::SOURCE_METADATA;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            return self::BUSINESS_PHP;
        }

        return self::STATIC_ASSET;
    }

    private function owner(string $path): string
    {
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
