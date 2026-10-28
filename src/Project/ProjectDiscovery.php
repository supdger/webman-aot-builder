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

    /** @var list<string> */
    private array $skipped = [];

    /** @return list<string> */
    public function skippedPaths(): array
    {
        return $this->skipped;
    }

    public function discover(
        bool $useRecordedInputs = true,
        array $explicitSources = [],
        ?string $explicitEntry = null,
        array $explicitPhpSources = []
    ): DiscoveryResult
    {
        if (!is_dir($this->projectDirectory) || is_link($this->projectDirectory)) {
            throw new ConfigurationException('project discovery requires a real project directory');
        }
        if (!is_file($this->projectDirectory . '/composer.json')
            && !is_file($this->projectDirectory . '/start.php')
            && !is_file($this->projectDirectory . '/project.linux.yml')
            && !is_file($this->projectDirectory . '/project.yml')
        ) {
            throw new ConfigurationException(
                'not a Webman/PHP project directory: no composer.json, start.php, or project manifest found; run webman-aot build inside your project root'
            );
        }
        RuntimeDataPaths::assertSafeWritableDirectories($this->projectDirectory);
        $manifestFile = is_file($this->projectDirectory . '/project.linux.yml')
            ? $this->projectDirectory . '/project.linux.yml' : $this->projectDirectory . '/project.yml';
        $entry = (new CompilationEntryResolver($this->projectDirectory))->resolve(
            $explicitEntry,
            is_file($manifestFile) && !is_link($manifestFile) ? (string) file_get_contents($manifestFile) : null
        );
        if ($entry !== null) {
            $explicitSources[] = $entry;
            $explicitPhpSources[] = $entry;
        }
        $phpSources = array_values(array_unique(array_merge(
            array_values(array_filter($explicitSources, static fn(string $path): bool =>
                !CompilationSourceResolver::isNativeSourcePath($path))),
            $explicitPhpSources
        )));
        $explicitSources = array_values(array_unique(array_merge($explicitSources, $explicitPhpSources)));
        $sourceInputs = $useRecordedInputs ? $this->sourceInputs() : null;
        $files = [];
        $this->scanRoot($files, $sourceInputs, $phpSources);
        foreach ($sourceInputs ?? [] as $path => $present) {
            if (!isset($files[$path])) {
                throw new ConfigurationException("recorded project source is missing or unsafe: {$path}");
            }
        }
        ksort($files, SORT_STRING);

        $inventory = array_values($files);
        $selection = null;
        $receipt = $this->projectDirectory . '/.typephp/build/compilation-sources.json';
        $project = $this->projectDirectory . '/project.linux.yml';
        if ($useRecordedInputs
            && str_ends_with(str_replace('\\', '/', $this->projectDirectory), '/.webman-aot-builder/build/project')
            && is_file($receipt) && !is_link($receipt)
            && is_file($project) && !is_link($project)) {
            $record = json_decode((string) file_get_contents($receipt), true);
            if (is_array($record) && ($record['schema'] ?? null) === 'webman-aot-compilation-sources-v2'
                && ($record['manifestSha256'] ?? null) === hash_file('sha256', $project)
                && is_array($record['sources'] ?? null) && is_array($record['diagnostics'] ?? null)) {
                foreach ($record['sources'] as $path) {
                    if (!is_string($path) || !isset($files[$path])) {
                        throw new ConfigurationException('recorded compiler source is missing or unsafe');
                    }
                }
                foreach ($record['diagnostics'] as &$diagnostic) {
                    $set = $diagnostic['candidateSet'] ?? null;
                    if (!is_int($set) || !is_array($record['candidateSets'][$set] ?? null)) {
                        throw new ConfigurationException('recorded source diagnostic is invalid');
                    }
                    if ($record['candidateSets'][$set] === []
                        || !is_string($diagnostic['file'] ?? null)
                        || !is_int($diagnostic['line'] ?? null)
                        || !is_string($diagnostic['expression'] ?? null)) {
                        throw new ConfigurationException('recorded source uncertainty has no candidates');
                    }
                    foreach ($record['candidateSets'][$set] as $candidate) {
                        if (!is_string($candidate) || !isset($files[$candidate])) {
                            throw new ConfigurationException('recorded uncertain source candidate is missing or unsafe');
                        }
                    }
                    $diagnostic['candidates'] = $record['candidateSets'][$set];
                    unset($diagnostic['candidateSet']);
                }
                unset($diagnostic);
                $selection = $record;
            }
        }
        $selection ??= (new CompilationSourceResolver($this->projectDirectory))->resolve($inventory, $explicitSources);
        $nativeSources = array_fill_keys($selection['sources'], true);
        foreach ($inventory as &$file) {
            if (isset($nativeSources[$file['path']])) {
                $file['category'] = self::BUSINESS_PHP;
            }
        }
        unset($file);
        return new DiscoveryResult($inventory, $selection['sources'], $selection['diagnostics']);
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
        $absoluteRoot = $this->projectDirectory;
        $directory = new \RecursiveDirectoryIterator(
            $absoluteRoot,
            \FilesystemIterator::SKIP_DOTS
        );
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            function (\SplFileInfo $entry) use ($sourceInputs, $sourceDirectories): bool {
                if ($entry->isDir() && str_starts_with($entry->getFilename(), '.')) {
                    return false;
                }
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
                        && !$entry->isDir())
                    || RuntimeDataPaths::isDevFixturePath($relative)
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
                    $this->skipped[] = $relative;
                    return false;
                }
                return true;
            }
        );
        $iterator = new \RecursiveIteratorIterator($filter);
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                $this->skipped[] = $this->relative($entry->getPathname());
                continue;
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
            || !str_ends_with(str_replace('\\', '/', $mirror), '/.webman-aot-builder/build/project')
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
            if (explode('/', $source, 2)[0] === 'build') {
                // Tolerate manifests recorded before the compiler-owned
                // build/ tree was excluded from source inputs; its contents
                // are rewritten or pruned during compilation by design.
                continue;
            }
            $paths[$source] = true;
        }
        return $paths;
    }

    /** @param list<string> $explicitSources */
    private function classify(string $path, array $explicitSources): string
    {
        if (in_array($path, $explicitSources, true)) {
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
