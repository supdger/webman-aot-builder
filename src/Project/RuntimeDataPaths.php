<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class RuntimeDataPaths
{
    /** @var array<string,true> */
    private static array $reportedUnreadableData = [];

    private const WRITABLE_DATA_DIRECTORIES = [
        'public/storage',
        'plugin/saiadmin/public/export',
    ];

    public static function isWritableDataPath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        foreach (self::WRITABLE_DATA_DIRECTORIES as $directory) {
            if ($path === $directory || str_starts_with($path, $directory . '/')) {
                return true;
            }
        }

        return false;
    }

    public static function assertEnvironmentPhpSource(string $absolutePath, string $relativePath): void
    {
        if (preg_match('/^\.env(?:\..+)?$/D', basename($relativePath)) !== 1
            || strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) !== 'php'
        ) {
            return;
        }
        $contents = file_get_contents($absolutePath);
        if (!is_string($contents)) {
            throw new ConfigurationException("unable to read environment-named PHP source: {$relativePath}");
        }
    }

    /**
     * @param list<string> $excludedPaths
     * @return array{files:array<string,array{absolute:string,identity:string}>,links:array<string,string>,cycles:array<string,string>}
     */
    public static function sourceFiles(string $projectDirectory, array $excludedPaths): array
    {
        clearstatcache(true);
        $root = realpath($projectDirectory);
        if (!is_string($root) || !is_dir($root)) {
            throw new ConfigurationException('unable to read project source directory');
        }
        $regularPath = static function (string $relative) use ($root): bool {
            $current = rtrim($root, '/\\');
            foreach (explode('/', $relative) as $component) {
                $current .= DIRECTORY_SEPARATOR . $component;
                if (is_link($current)) {
                    return false;
                }
            }
            $canonical = realpath($current);
            return is_file($current) && is_readable($current) && is_string($canonical)
                && str_replace('\\', '/', $canonical) === str_replace('\\', '/', $current);
        };
        $generatedFiles = ProjectWorkspace::generatedSourceFiles($root);
        $generationDirectory = ProjectMirror::generationSourceDirectory($root);
        $generationPrefix = is_string($generationDirectory)
            && str_starts_with($generationDirectory, $root . '/')
            ? substr($generationDirectory, strlen($root) + 1)
            : null;
        $originalInputs = self::originalSourceInputs($root);
        $distributionFiles = [];
        $manifestFile = $root . '/dist-aot/manifest.json';
        $bytes = $regularPath('dist-aot/manifest.json') ? file_get_contents($manifestFile) : false;
        $manifest = is_string($bytes) ? json_decode($bytes, true) : null;
        if (is_array($manifest)
            && ($manifest['schema'] ?? null) === 'webman-aot-builder-distribution-v1'
            && is_array($manifest['files'] ?? null)
        ) {
            $complete = true;
            foreach (['server', 'start.sh', 'stop.sh', 'coverage.json', 'resources.json'] as $required) {
                $digest = $manifest['files'][$required]['sha256'] ?? null;
                $file = $root . '/dist-aot/' . $required;
                if (!is_string($digest) || !$regularPath('dist-aot/' . $required)
                    || hash_file('sha256', $file) !== $digest
                ) {
                    $complete = false;
                    break;
                }
            }
            if (!$complete) {
                $manifest = null;
            }
        }
        if (is_array($manifest)
            && ($manifest['schema'] ?? null) === 'webman-aot-builder-distribution-v1'
            && is_array($manifest['files'] ?? null)
        ) {
            $distributionFiles['dist-aot/manifest.json'] = hash('sha256', $bytes);
            foreach ($manifest['files'] as $path => $record) {
                if (is_string($path) && $path !== '' && !str_contains($path, '\\')
                    && !str_starts_with($path, '/') && preg_match('/^[A-Za-z]:/', $path) !== 1
                    && !in_array('..', explode('/', $path), true) && !in_array('.', explode('/', $path), true)
                    && !in_array('', explode('/', $path), true) && is_array($record)
                    && $regularPath('dist-aot/' . $path)
                    && is_string($record['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $record['sha256']) === 1
                ) {
                    $distributionFiles['dist-aot/' . $path] = $record['sha256'];
                }
            }
        }
        $files = [];
        $unreadable = [];
        $links = [];
        $cycles = [];
        $walk = static function (string $directory, string $prefix, array $ancestors) use (
            &$walk, &$files, &$links, &$cycles, $excludedPaths, $distributionFiles, $generatedFiles, $generationPrefix, $originalInputs, $regularPath, &$unreadable
        ): void {
            $identity = realpath($directory);
            if (!is_string($identity)) {
                throw new ConfigurationException("unable to read project source directory: {$prefix}");
            }
            if (isset($ancestors[$identity])) {
                $cycles[$prefix] = $ancestors[$identity];
                return;
            }
            $ancestors[$identity] = $prefix;
            try {
                $entries = new \DirectoryIterator($directory);
            } catch (\UnexpectedValueException $exception) {
                throw new ConfigurationException("unable to read project source directory: {$prefix}", previous: $exception);
            }
            foreach ($entries as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                $path = $prefix === '' ? $entry->getFilename() : $prefix . '/' . $entry->getFilename();
                $top = explode('/', $path, 2)[0];
                $php = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php';
                $excluded = false;
                foreach ($excludedPaths as $excludedPath) {
                    if ($excludedPath !== '.git') {
                        continue;
                    }
                    if ($path === $excludedPath || str_starts_with($path, $excludedPath . '/')) {
                        $excluded = true;
                        break;
                    }
                }
                if ($excluded
                    || $entry->getFilename() === '.DS_Store'
                    || ($top === '.webman-aot-builder' && !$entry->isDir() && !$php)
                    || (self::isWritableDataPath($path) && !$entry->isDir() && !$php)
                    || (preg_match('/^\.env(?:\..+)?$/D', $entry->getFilename()) === 1 && !$php)
                    || (in_array($top, ['runtime', 'node_modules'], true) && !$entry->isDir() && !$php)
                ) {
                    continue;
                }
                $absolute = $entry->getPathname();
                $generatedDigest = $generatedFiles[$path] ?? $generatedFiles[$absolute] ?? null;
                if (!isset($originalInputs[$path]) && is_string($generatedDigest) && $regularPath($path)
                    && hash_file('sha256', $absolute) === $generatedDigest
                ) {
                    continue;
                }
                if ($generationPrefix !== null && $originalInputs !== null
                    && !isset($originalInputs[$path]) && $regularPath($path)
                    && str_starts_with($path, $generationPrefix . '/')
                ) {
                    continue;
                }

                if (isset($distributionFiles[$path]) && $entry->isFile()
                    && hash_file('sha256', $absolute) === $distributionFiles[$path]
                ) {
                    continue;
                }
                $target = realpath($absolute);
                if ($entry->isLink()) {
                    if (!is_string($target)) {
                        if ($php) {
                            throw new ConfigurationException("unable to read project PHP source: {$path}");
                        }
                        continue;
                    }
                    $links[$path] = hash('sha256', str_replace('\\', '/', $target));
                }
                if ($entry->isDir()) {
                    $walk($absolute, $path, $ancestors);
                    continue;
                }
                if (!$entry->isFile()) {
                    if ($php) {
                        throw new ConfigurationException("unable to read project PHP source: {$path}");
                    }
                    continue;
                }
                if (!is_string($target) || !is_readable($absolute)) {
                    if ($php) {
                        throw new ConfigurationException("unable to read project PHP source: {$path}");
                    }
                    $unreadable[] = $path;
                    continue;
                }
                $files[$path] = ['absolute' => $absolute, 'identity' => hash('sha256', str_replace('\\', '/', $target))];
            }
        };
        $walk($root, '', []);
        self::reportUnreadableData($root, $unreadable);
        ksort($files, SORT_STRING);
        ksort($links, SORT_STRING);
        ksort($cycles, SORT_STRING);

        return ['files' => $files, 'links' => $links, 'cycles' => $cycles];
    }

    /** @param list<string> $paths */
    public static function reportUnreadableData(string $projectDirectory, array $paths): void
    {
        $root = realpath($projectDirectory) ?: $projectDirectory;
        $unreported = [];
        foreach ($paths as $path) {
            $identity = $root . '/' . $path;
            if (!isset(self::$reportedUnreadableData[$identity])) {
                self::$reportedUnreadableData[$identity] = true;
                $unreported[] = $path;
            }
        }
        if ($unreported !== []) {
            $shown = implode(', ', array_slice($unreported, 0, 5));
            $remaining = count($unreported) - 5;
            fwrite(STDERR, '[source] skipped unreadable non-PHP input: ' . $shown
                . ($remaining > 0 ? " (+{$remaining} more)" : '') . "\n");
        }
    }

    /** @return array<string,true>|null */
    public static function originalSourceInputs(string $projectDirectory): ?array
    {
        $path = ProjectMirror::sourceInputManifestPath($projectDirectory);
        if ($path === null || !file_exists($path)) {
            return null;
        }
        $current = '';
        foreach (explode('/', $path) as $component) {
            if ($component === '') {
                continue;
            }
            $current .= '/' . $component;
            if (is_link($current)) {
                throw new ConfigurationException('owned project source input manifest is unsafe');
            }
        }
        $bytes = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        $manifest = is_string($bytes) ? json_decode($bytes, true) : null;
        if (!is_array($manifest)
            || ($manifest['schema'] ?? null) !== 'webman-aot-builder-source-inputs-v1'
            || !is_array($manifest['paths'] ?? null) || !array_is_list($manifest['paths'])
        ) {
            throw new ConfigurationException('owned project source input manifest cannot be read');
        }
        $inputs = [];
        foreach ($manifest['paths'] as $input) {
            if (!is_string($input) || $input === '' || str_contains($input, '\\')
                || str_starts_with($input, '/') || preg_match('/^[A-Za-z]:/', $input) === 1
                || array_intersect(['', '.', '..'], explode('/', $input)) !== []
                || isset($inputs[$input])
            ) {
                throw new ConfigurationException('owned project source input manifest contains an unsafe or duplicate path');
            }
            $inputs[$input] = true;
        }
        return $inputs;
    }

    /**
     * @return list<array{path:string,role:string}>
     */
    public static function writableDirectories(string $projectDirectory): array
    {
        $root = rtrim($projectDirectory, '/\\');
        $directories = [];
        foreach ([
            ['path' => 'public/storage', 'role' => 'uploads'],
            ['path' => 'runtime/logs', 'role' => 'logs'],
            ['path' => 'plugin/saiadmin/public/export', 'role' => 'uploads'],
        ] as $directory) {
            if (is_dir($root . '/' . $directory['path'])) {
                $directories[] = $directory;
            }
        }

        return $directories;
    }
}
