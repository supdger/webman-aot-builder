<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class ProjectWorkspace
{
    private const SCHEMA = 'webman-aot-builder-project-workspace-v1';

    private readonly string $root;

    public function __construct(private readonly string $projectDirectory, ?string $runtimeCache = null)
    {
        $project = realpath($projectDirectory);
        if (!is_string($project) || !is_dir($project)) { throw new ConfigurationException('unable to read project directory'); }
        $legacy = $project . '/.webman-aot-builder';
        if (self::isOwnedRoot($legacy, $project) && is_writable($legacy)) {
            $this->root = (string) realpath($legacy);
            return;
        }
        $cache = $runtimeCache ?? \WebmanAotBuilder\Platform\UserDirectoryLayout::detect()->path('cache');
        $this->createDirectory($cache);
        $cache = realpath($cache);
        if (!is_string($cache) || !is_writable($cache)) { throw new ConfigurationException('unable to write build workspace cache'); }
        $parent = $cache . '/projects';
        if ($cache === $project || str_starts_with($cache, $project . DIRECTORY_SEPARATOR)) {
            $parent = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/webman-aot-builder-projects';
        }
        $identity = hash('sha256', str_replace('\\', '/', $project));
        for ($index = 0; $index < 100; ++$index) {
            $root = $parent . '/' . $identity . ($index === 0 ? '' : '-' . $index) . '/.webman-aot-builder';
            if ($this->reserve($root, $project)) { $this->root = (string) realpath($root); return; }
        }
        throw new ConfigurationException('unable to allocate a writable owned build workspace');
    }

    public function root(): string { return $this->root; }

    public static function isOwnedRoot(string $root, ?string $project = null): bool
    {
        return self::ownershipRecord($root, $project) !== null;
    }

    /** @return array<string,string> Project-relative files produced in this workspace. */
    public static function generatedSourceFiles(string $projectDirectory): array
    {
        $project = realpath($projectDirectory);
        if (!is_string($project)) { return []; }
        $root = $project . '/.webman-aot-builder';
        $record = self::ownershipRecord($root, $project);
        if ($record === null || !is_array($record['files'] ?? null)) { return []; }
        $files = [];
        foreach ($record['files'] as $relative => $digest) {
            if (is_string($relative) && $relative !== '' && !str_contains($relative, '\\')
                && !str_starts_with($relative, '/') && preg_match('/^[A-Za-z]:/', $relative) !== 1
                && array_intersect(['', '.', '..'], explode('/', $relative)) === []
                && is_string($digest) && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1
            ) { $files['.webman-aot-builder/' . $relative] = $digest; }
        }
        return $files;
    }

    /** @return array<string,mixed>|null */
    private static function ownershipRecord(string $root, ?string $project = null): ?array
    {
        $actual = realpath($root);
        $file = $root . '/workspace.json';
        if (!is_string($actual) || basename($actual) !== '.webman-aot-builder'
            || !self::regularPath($file)
        ) { return null; }
        $metadata = json_decode((string) file_get_contents($file), true);
        if (!is_array($metadata) || ($metadata['schema'] ?? null) !== self::SCHEMA
            || !is_string($metadata['projectDirectory'] ?? null)
            || !is_string($metadata['ownershipFile'] ?? null)
            || !is_string($metadata['ownershipId'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $metadata['ownershipId']) !== 1
        ) { return null; }
        $canonical = realpath($metadata['projectDirectory']);
        $ownershipFile = $metadata['ownershipFile'];
        $expectedRecord = self::registryDirectory($canonical ?: '') . DIRECTORY_SEPARATOR . $metadata['ownershipId'] . '.json';
        if ($ownershipFile !== $expectedRecord) { return null; }
        if (!is_string($canonical) || ($project !== null && $canonical !== realpath($project))
            || !self::regularPath($ownershipFile)
        ) { return null; }
        $actualRecord = realpath($ownershipFile);
        if (!is_string($actualRecord) || $actualRecord === $canonical
            || str_starts_with($actualRecord, $canonical . DIRECTORY_SEPARATOR)
        ) { return null; }
        $record = json_decode((string) file_get_contents($ownershipFile), true);
        if (!is_array($record) || ($record['schema'] ?? null) !== 'webman-aot-builder-workspace-ownership-v1'
            || ($record['projectDirectory'] ?? null) !== $canonical
            || ($record['workspace'] ?? null) !== $actual
            || ($record['ownershipId'] ?? null) !== $metadata['ownershipId']
        ) { return null; }
        $record['ownershipFile'] = $ownershipFile;
        return $record;
    }

    private static function registryDirectory(string $project): string
    {
        $cache = \WebmanAotBuilder\Platform\UserDirectoryLayout::detect()->path('cache');
        if (!self::directoryPathHasLinks($cache)) { $cache = realpath($cache) ?: $cache; }
        if ($cache === $project || str_starts_with($cache, $project . DIRECTORY_SEPARATOR)) {
            $directory = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/webman-aot-builder-projects/ownership';
            return self::directoryPathHasLinks($directory) ? $directory : (realpath($directory) ?: $directory);
        }
        $directory = $cache . '/projects/ownership';
        return self::directoryPathHasLinks($directory) ? $directory : (realpath($directory) ?: $directory);
    }

    private static function directoryPathHasLinks(string $directory): bool
    {
        for ($path = $directory; $path !== dirname($path); $path = dirname($path)) {
            if (is_link($path)) { return true; }
        }
        return false;
    }

    private static function regularPath(string $file): bool
    {
        if (!is_file($file) || !is_readable($file) || is_link($file)) { return false; }
        for ($parent = dirname($file); $parent !== dirname($parent); $parent = dirname($parent)) {
            if (is_link($parent)) { return false; }
        }
        return true;
    }

    public static function rootFromBuild(string $directory, ?string $project = null): ?string
    {
        $build = realpath($directory);
        if (!is_string($build) || is_link($directory)) { return null; }
        $attempt = null;
        if (preg_match('/^attempt-[a-f0-9]{24}$/D', basename($build)) === 1) {
            $attempt = basename($build);
            $build = dirname($build);
        }
        $root = dirname($build);
        $record = self::ownershipRecord($root, $project);
        if (basename($build) !== 'build' || $record === null
            || ($attempt !== null && !in_array($attempt, $record['attempts'] ?? [], true))
        ) { return null; }
        return $root;
    }

    private function reserve(string $root, string $project): bool
    {
        if (self::isOwnedRoot($root, $project)) { return is_writable($root); }
        if (is_link($root)) { return false; }
        if (file_exists($root)) {
            for ($attempt = 0; $attempt < 20; ++$attempt) {
                if (self::isOwnedRoot($root, $project)) { return is_writable($root); }
                usleep(1000);
            }
            return false;
        }
        for ($parent = dirname($root); $parent !== dirname($parent); $parent = dirname($parent)) {
            if (is_link($parent)) { return false; }
        }
        if (!@mkdir($root, 0700, true)) {
            for ($attempt = 0; $attempt < 20; ++$attempt) {
                if (self::isOwnedRoot($root, $project)) { return is_writable($root); }
                usleep(1000);
            }
            return false;
        }
        $owner = bin2hex(random_bytes(16));
        $owners = self::registryDirectory($project);
        if (self::directoryPathHasLinks($owners)) {
            throw new ConfigurationException('workspace ownership registry path is unsafe');
        }
        $this->createDirectory($owners);
        if (self::directoryPathHasLinks($owners)) {
            throw new ConfigurationException('workspace ownership registry path changed during creation');
        }
        $owners = (string) realpath($owners);
        $ownershipFile = $owners . DIRECTORY_SEPARATOR . $owner . '.json';
        $record = ['schema' => 'webman-aot-builder-workspace-ownership-v1',
            'projectDirectory' => $project, 'workspace' => (string) realpath($root),
            'ownershipId' => $owner, 'files' => []];
        if (file_put_contents($ownershipFile, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n") === false) {
            throw new ConfigurationException('unable to record owned build workspace');
        }
        $marker = fopen($root . '/workspace.json', 'xb');
        if (!is_resource($marker)) { throw new ConfigurationException('unable to mark owned build workspace'); }
        try {
            $bytes = json_encode(['schema' => self::SCHEMA, 'projectDirectory' => $project,
                'ownershipId' => $owner, 'ownershipFile' => $ownershipFile,
                'cacheKey' => hash('sha256', ''), 'sourceSha256' => hash('sha256', '')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            if (fwrite($marker, $bytes) !== strlen($bytes)) { throw new ConfigurationException('unable to write owned build workspace marker'); }
        } finally { fclose($marker); }
        return true;
    }

    /**
     * @return array{root:string,build:string,cache:string,runs:string,cacheEntry:string}
     */
    public function prepare(string $cacheKey, string $sourceSha256): array
    {
        $this->assertDigest($cacheKey, 'cache key');
        $this->assertDigest($sourceSha256, 'source');
        $this->assertOwnedWorkspace();
        foreach ([$this->root, $this->build(), $this->cache(), $this->runs()] as $directory) {
            if (is_link($directory)) {
                throw new ConfigurationException("project workspace path cannot be a symlink: {$directory}");
            }
            $this->createDirectory($directory);
        }
        if (is_link($this->root . '/workspace.json')) {
            throw new ConfigurationException('project workspace metadata cannot be a symlink');
        }
        $ownership = self::ownershipRecord($this->root, $this->projectDirectory);
        $metadata = [
            'schema' => self::SCHEMA,
            'ownershipId' => $ownership['ownershipId'],
            'ownershipFile' => $ownership['ownershipFile'],
            'projectDirectory' => realpath($this->projectDirectory),
            'cacheKey' => $cacheKey,
            'sourceSha256' => $sourceSha256,
        ];
        $encoded = json_encode(
            $metadata,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if (file_put_contents($this->root . '/workspace.json', $encoded . "\n") === false) {
            throw new ConfigurationException('unable to write project workspace metadata');
        }

        return [
            'root' => $this->root,
            'build' => $this->newAttempt(),
            'cache' => $this->cache(),
            'runs' => $this->runs(),
            'cacheEntry' => $this->cache() . DIRECTORY_SEPARATOR . $cacheKey,
        ];
    }

    public function recordGeneratedFiles(): void
    {
        $this->assertOwnedWorkspace();
        $record = self::ownershipRecord($this->root, $this->projectDirectory);
        $files = $record['files'] ?? [];
        $directories = array_merge(['build', 'cache', 'runs'], $record['directories'] ?? []);
        foreach (array_slice($record['attempts'] ?? [], -1) as $attempt) {
            if (!is_string($attempt) || preg_match('/^attempt-[a-f0-9]{24}$/D', $attempt) !== 1) { continue; }
            $directory = $this->build() . '/' . $attempt;
            if (!is_dir($directory) || is_link($directory)) { continue; }
            $directories[] = 'build/' . $attempt;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $file) {
                if ($file->isDir() && !$file->isLink()) {
                    $directories[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($this->root) + 1));
                    continue;
                }
                if (!$file->isFile() || !self::regularPath($file->getPathname())) { continue; }
                $digest = hash_file('sha256', $file->getPathname());
                if (is_string($digest)) {
                    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($this->root) + 1));
                    $files[$relative] = $digest;
                }
            }
        }
        $record['files'] = $files;
        $record['directories'] = $directories;
        $this->writeOwnershipRecord($record);
    }

    /** @param array<string,mixed> $record */
    private function writeOwnershipRecord(array $record): void
    {
        $path = $record['ownershipFile'];
        unset($record['ownershipFile']);
        if (!self::regularPath($path)
            || file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false
        ) { throw new ConfigurationException('unable to update owned workspace output record'); }
    }

    public function finishAttempt(string $attempt): void
    {
        $this->assertOwnedWorkspace();
        $actual = realpath($attempt);
        if (!is_string($actual) || is_link($attempt)
            || self::rootFromBuild($actual, $this->projectDirectory) !== $this->root
            || dirname($actual) !== realpath($this->build())
            || preg_match('/^attempt-[a-f0-9]{24}$/D', basename($actual)) !== 1) {
            throw new ConfigurationException('refusing to clean an unrelated build attempt');
        }
        // Called only after compiler/link processes have exited and publication
        // has succeeded. Failed attempts may still have orphan writers.
        $this->assertRecordedContents($actual);
        $this->removeDirectory($actual);
    }

    private function newAttempt(): string
    {
        $path = $this->build() . '/attempt-' . bin2hex(random_bytes(12));
        $this->createDirectory($path);
        $record = self::ownershipRecord($this->root, $this->projectDirectory);
        $record['attempts'][] = basename($path);
        $this->writeOwnershipRecord($record);
        return $path;
    }

    public function cleanTransient(): void
    {
        $lease = new ProjectBuildLease($this->projectDirectory, $this->root);
        $this->assertOwnedWorkspace();
        foreach ([$this->build(), $this->runs()] as $directory) {
            $this->assertRecordedContents($directory);
        }
        foreach ([$this->build(), $this->runs()] as $directory) {
            $this->removeContents($directory);
            $this->createDirectory($directory);
        }
    }

    public function remove(): void
    {
        $this->assertOwnedWorkspace();
        $lease = new ProjectBuildLease($this->projectDirectory, $this->root);
        $this->assertRecordedContents($this->root);
        // Retain the lock inode and ownership marker while this lease is held.
        foreach (new \FilesystemIterator($this->root, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if (in_array($entry->getFilename(), ['build.lock', 'workspace.json'], true)) { continue; }
            if ($entry->isDir() && !$entry->isLink()) { $this->removeDirectory($entry->getPathname()); }
            elseif (!unlink($entry->getPathname())) { throw new ConfigurationException('cannot remove workspace file'); }
        }
    }

    private function assertRecordedContents(string $directory): void
    {
        if (!file_exists($directory) && !is_link($directory)) { return; }
        if (!is_dir($directory) || is_link($directory)) {
            throw new ConfigurationException('refusing to clean an unsafe workspace output');
        }
        $record = self::ownershipRecord($this->root, $this->projectDirectory);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,
            \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($this->root) + 1));
            if (in_array($relative, ['workspace.json', 'build.lock'], true)) { continue; }
            if (preg_match('~^build/(attempt-[a-f0-9]{24})(?:/|$)~D', $relative, $match) === 1
                && !in_array($match[1], $record['attempts'] ?? [], true)) {
                throw new ConfigurationException('refusing to clean an unrelated build attempt');
            }
            if ($entry->isDir() && !$entry->isLink()) {
                if (!in_array($relative, array_merge(['build', 'cache', 'runs'], $record['directories'] ?? []), true)) {
                    throw new ConfigurationException('refusing to clean an unrecorded workspace directory');
                }
                continue;
            }
            $digest = $record['files'][$relative] ?? null;
            if (!is_string($digest) || !self::regularPath($entry->getPathname())
                || @hash_file('sha256', $entry->getPathname()) !== $digest) {
                throw new ConfigurationException('refusing to clean an unrecorded workspace output');
            }
        }
    }

    private function assertOwnedWorkspace(): void
    {
        if (!is_dir($this->root) || is_link($this->root)) {
            throw new ConfigurationException('project workspace is missing or unsafe');
        }
        $metadataPath = $this->root . '/workspace.json';
        $contents = is_file($metadataPath) ? file_get_contents($metadataPath) : false;
        try {
            $metadata = is_string($contents)
                ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR)
                : null;
        } catch (\JsonException) {
            $metadata = null;
        }
        if (!is_array($metadata) || ($metadata['schema'] ?? null) !== self::SCHEMA) {
            throw new ConfigurationException(
                'refusing to clean an unmarked project workspace'
            );
        }
        if (!self::isOwnedRoot($this->root, $this->projectDirectory)) {
            throw new ConfigurationException('project workspace ownership differs from its project');
        }
    }

    private function build(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'build';
    }

    private function cache(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'cache';
    }

    private function runs(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'runs';
    }

    private function assertDigest(string $digest, string $name): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new ConfigurationException("invalid {$name} digest");
        }
    }

    private function createDirectory(string $directory): void
    {
        if (!is_dir($directory)
            && !@mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new ConfigurationException("unable to create project workspace: {$directory}");
        }
    }

    private function removeContents(string $directory): void
    {
        $this->assertRecordedContents($directory);
        if (!is_dir($directory) || is_link($directory)) {
            if (file_exists($directory) || is_link($directory)) {
                throw new ConfigurationException("workspace path is unsafe: {$directory}");
            }
            return;
        }
        $canonical = realpath($directory);
        $identity = lstat($directory);
        $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            $this->assertRecordedContents($directory);
            clearstatcache(true);
            $current = lstat($directory);
            if (is_link($directory) || realpath($directory) !== $canonical || !is_array($identity) || !is_array($current)
                || $identity['dev'] !== $current['dev'] || $identity['ino'] !== $current['ino']) {
                throw new ConfigurationException("workspace directory changed during content cleanup: {$directory}");
            }
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectory($entry->getPathname());
            } else {
                $path = $entry->getPathname();
                $windowsLink = PHP_OS_FAMILY === 'Windows' && $entry->isLink();
                $reason = 'file removal failed';
                $removed = false;
                for ($attempt = 0; $attempt < 6; $attempt++) {
                    clearstatcache(true);
                    $current = lstat($directory);
                    if (is_link($directory) || realpath($directory) !== $canonical || !is_array($identity) || !is_array($current)
                        || $identity['dev'] !== $current['dev'] || $identity['ino'] !== $current['ino']) {
                        throw new ConfigurationException("workspace directory changed during file cleanup: {$directory}");
                    }
                    set_error_handler(static function (int $severity, string $message) use (&$reason): bool {
                        $reason = $message;
                        return true;
                    }, E_WARNING);
                    try {
                        $removed = unlink($path);
                        if (!$removed && $windowsLink) {
                            clearstatcache(true);
                            $current = lstat($directory);
                            if (is_link($directory) || realpath($directory) !== $canonical || !is_array($current)
                                || $identity['dev'] !== $current['dev'] || $identity['ino'] !== $current['ino']) {
                                throw new ConfigurationException("workspace directory changed during file cleanup: {$directory}");
                            }
                            if (is_link($path)) { $removed = rmdir($path); }
                        }
                    }
                    finally { restore_error_handler(); }
                    if ($removed) { break; }
                    clearstatcache(true, $path);
                    if (!file_exists($path) && !is_link($path)) { $removed = true; break; }
                    if ($attempt < 5) { usleep(50000 * (2 ** $attempt)); }
                }
                if (!$removed) {
                    $reason = preg_replace('/[\x00-\x20\x7f]+/', ' ', str_replace($path, '<file>', $reason)) ?? 'file removal failed';
                    throw new ConfigurationException("unable to remove workspace file after 6 attempts: {$path}; " . substr($reason, 0, 400));
                }
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        clearstatcache(true);
        if (!is_dir($directory) || is_link($directory)) {
            throw new ConfigurationException("workspace directory is unsafe: {$directory}");
        }
        $canonical = realpath($directory);
        $identity = lstat($directory);
        $reason = 'directory removal failed';
        for ($attempt = 0; $attempt < 6; $attempt++) {
            clearstatcache(true);
            if (!is_dir($directory) || is_link($directory)) {
                throw new ConfigurationException("workspace directory is unsafe: {$directory}");
            }
            $current = lstat($directory);
            if (realpath($directory) !== $canonical || !is_array($identity) || !is_array($current)
                || $identity['dev'] !== $current['dev'] || $identity['ino'] !== $current['ino']) {
                throw new ConfigurationException("workspace directory changed during directory cleanup: {$directory}");
            }
            $this->removeContents($directory);
            set_error_handler(static function (int $severity, string $message) use (&$reason): bool {
                $reason = $message;
                return true;
            }, E_WARNING);
            try {
                $removed = rmdir($directory);
            } finally {
                restore_error_handler();
            }
            if ($removed) {
                return;
            }
            if ($attempt < 5) {
                usleep(50000 * (2 ** $attempt));
            }
        }
        $reason = str_replace($directory, '<directory>', $reason);
        $reason = preg_replace('/[\x00-\x20\x7f]+/', ' ', $reason) ?? 'directory removal failed';
        throw new ConfigurationException(
            "unable to remove workspace directory after 6 attempts: {$directory}; " . substr($reason, 0, 400)
            . '; close programs writing to the build workspace, check directory permissions, and retry webman-aot build'
        );
    }
}
