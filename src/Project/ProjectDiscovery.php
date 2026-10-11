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
        $explicitSources = $this->explicitSources();
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
        $absoluteRoot = $this->projectDirectory;
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
            RuntimeDataPaths::assertEnvironmentPhpSource($entry->getPathname(), $path);
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
            || in_array($normalized, ['composer.json', 'composer.lock', 'project.linux.yml', 'vendor/autoload.php'], true)
            || preg_match('#^vendor/composer/[^/]+\.php$#D', $normalized) === 1
        ) {
            return self::SOURCE_METADATA;
        }
        $dynamicDigest = $this->dynamicPhp[$path] ?? null;
        if (str_starts_with($path, 'vendor/')
            && is_string($dynamicDigest)
            && preg_match('/^[a-f0-9]{64}$/D', $dynamicDigest) === 1
            && hash_file('sha256', $this->absolute($path)) === $dynamicDigest
        ) {
            return self::THIRD_PARTY_DYNAMIC_PHP;
        }
        if (str_starts_with($normalized, 'vendor/saithink/saiadmin/src/plugin/saiadmin/')
            || str_starts_with($normalized, 'vendor/saithink/saiadmin/src/orm/')
        ) {
            return self::INSTALL_ONLY;
        }
        if (in_array($normalized, [
            'vendor/workerman/webman-framework/src/install.php',
            'vendor/workerman/webman-framework/src/start.php',
            'vendor/workerman/webman-framework/src/windows.php',
            'vendor/workerman/webman-framework/src/support/plugin.php',
        ], true)
            || preg_match('#^plugin/[^/]+/(?:install\.php|db/)#D', $normalized) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/(?:install\.php|db/)#D',
                $normalized
            ) === 1
        ) {
            return self::INSTALL_ONLY;
        }
        if (str_starts_with($normalized, 'config/')
            || preg_match('#^plugin/[^/]+/config/#D', $normalized) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/config/#D',
                $normalized
            ) === 1
        ) {
            return $this->phpResourceCategory($path, self::CONFIG, $explicitSources);
        }
        if (preg_match('#^(?:app/)?(?:view|views|template|templates)/#D', $normalized) === 1
            || preg_match(
                '#^plugin/[^/]+/app/(?:view|views|template|templates)/#D',
                $normalized
            ) === 1
            || preg_match(
                '#^plugin/[^/]+/utils/code/stub/#D',
                $normalized
            ) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/app/(?:view|views|template|templates)/#D',
                $normalized
            ) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/utils/code/stub/#D',
                $normalized
            ) === 1
        ) {
            return $this->phpResourceCategory($path, self::TEMPLATE, $explicitSources);
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            return self::BUSINESS_PHP;
        }

        return self::STATIC_ASSET;
    }

    /** @param list<string> $explicitSources */
    private function phpResourceCategory(string $path, string $category, array $explicitSources): string
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php'
            && ($this->hasDeclarations($path) || $this->isExplicitSource($path, $explicitSources))
        ) {
            return self::BUSINESS_PHP;
        }
        return $category;
    }

    private function hasDeclarations(string $path): bool
    {
        $source = file_get_contents($this->absolute($path));
        if (!is_string($source)) {
            throw new ConfigurationException("unable to read project PHP: {$path}");
        }
        $tokens = token_get_all($source);
        $previous = null;
        foreach ($tokens as $index => $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($token)) {
                if (in_array($token[0], [T_INTERFACE, T_TRAIT, T_ENUM], true)
                    || ($token[0] === T_CLASS
                        && (!is_array($previous) || $previous[0] !== T_DOUBLE_COLON))
                ) {
                    return true;
                }
                if ($token[0] === T_FUNCTION) {
                    for ($next = $index + 1; isset($tokens[$next]); ++$next) {
                        $candidate = $tokens[$next];
                        if ((is_array($candidate)
                                && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
                            || (is_array($candidate) && $candidate[1] === '&')
                            || $candidate === '&'
                        ) {
                            continue;
                        }
                        if (is_array($candidate) && $candidate[0] === T_STRING) {
                            for ($afterName = $next + 1; isset($tokens[$afterName]); ++$afterName) {
                                $following = $tokens[$afterName];
                                if (is_array($following)
                                    && in_array($following[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
                                ) {
                                    continue;
                                }
                                if ($following === '(') {
                                    return true;
                                }
                                break;
                            }
                        }
                        break;
                    }
                }
            }
            $previous = $token;
        }
        return false;
    }

    /** @param list<string> $sources */
    private function isExplicitSource(string $path, array $sources): bool
    {
        foreach ($sources as $source) {
            if ($source === '.' || $path === $source || str_starts_with($path, $source . '/')) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function explicitSources(): array
    {
        $path = $this->absolute('project.linux.yml');
        if (is_link($path)) {
            throw new ConfigurationException('project discovery refuses symlink: project.linux.yml');
        }
        if (!is_file($path)) {
            return [];
        }
        $manifest = file_get_contents($path);
        if (!is_string($manifest)) {
            throw new ConfigurationException('unable to read project compiler sources');
        }
        $sources = [];
        $inSources = false;
        foreach (explode("\n", $manifest) as $line) {
            if (rtrim($line) === 'sources:') {
                $inSources = true;
                continue;
            }
            if ($line !== '' && $line[0] !== ' ' && $line[0] !== "\t") {
                $inSources = false;
            }
            if (!$inSources || preg_match('/^\s+-\s+(.+?)\s*$/D', $line, $matches) !== 1) {
                continue;
            }
            $source = $matches[1];
            if (str_starts_with($source, '"')) {
                $source = json_decode($source, true);
            } elseif (str_starts_with($source, "'")) {
                $source = str_ends_with($source, "'")
                    ? str_replace("''", "'", substr($source, 1, -1)) : null;
            }
            if (!is_string($source)) {
                throw new ConfigurationException('project compiler source entry is invalid');
            }
            while (str_starts_with($source, './')) {
                $source = substr($source, 2);
            }
            $source = rtrim($source, '/');
            if ($source === '' || str_contains($source, '\\')
                || str_starts_with($source, '/') || preg_match('/^[A-Za-z]:/', $source) === 1
                || in_array('..', explode('/', $source), true)
                || ($source !== '.' && in_array('.', explode('/', $source), true))
            ) {
                throw new ConfigurationException('project compiler source entry is unsafe');
            }
            $sources[] = $source;
        }
        return $sources;
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
