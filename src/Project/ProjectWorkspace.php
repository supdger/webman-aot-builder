<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class ProjectWorkspace
{
    private const SCHEMA = 'webman-aot-builder-project-workspace-v1';

    private readonly string $root;

    public function __construct(private readonly string $projectDirectory)
    {
        $this->root = rtrim($projectDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . '.webman-aot-builder';
    }

    /**
     * @return array{root:string,build:string,cache:string,runs:string,cacheEntry:string}
     */
    public function prepare(string $cacheKey, string $sourceSha256): array
    {
        $this->assertDigest($cacheKey, 'cache key');
        $this->assertDigest($sourceSha256, 'source');
        if (is_link($this->root)) {
            throw new ConfigurationException('project workspace cannot be a symlink');
        }
        if (is_dir($this->root)) {
            $this->assertOwnedWorkspace();
        }
        foreach ([$this->root, $this->build(), $this->cache(), $this->runs()] as $directory) {
            if (is_link($directory)) {
                throw new ConfigurationException("project workspace path cannot be a symlink: {$directory}");
            }
            $this->createDirectory($directory);
        }
        if (is_link($this->root . '/workspace.json')) {
            throw new ConfigurationException('project workspace metadata cannot be a symlink');
        }
        $metadata = [
            'schema' => self::SCHEMA,
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
            'build' => $this->build(),
            'cache' => $this->cache(),
            'runs' => $this->runs(),
            'cacheEntry' => $this->cache() . DIRECTORY_SEPARATOR . $cacheKey,
        ];
    }

    public function cleanTransient(): void
    {
        $this->assertOwnedWorkspace();
        foreach ([$this->build(), $this->runs()] as $directory) {
            $this->removeContents($directory);
            $this->createDirectory($directory);
        }
    }

    public function remove(): void
    {
        $this->assertOwnedWorkspace();
        $this->removeDirectory($this->root);
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
        $project = realpath($this->projectDirectory);
        $workspace = realpath($this->root);
        if (!is_string($project)
            || !is_string($workspace)
            || dirname($workspace) !== $project
            || basename($workspace) !== '.webman-aot-builder'
        ) {
            throw new ConfigurationException('project workspace escaped the project root');
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
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new ConfigurationException("unable to create project workspace: {$directory}");
        }
    }

    private function removeContents(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            if (file_exists($directory) || is_link($directory)) {
                throw new ConfigurationException("workspace path is unsafe: {$directory}");
            }
            return;
        }
        $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            throw new ConfigurationException("workspace directory is unsafe: {$directory}");
        }
        $this->removeContents($directory);
        foreach (scandir($directory) ?: [] as $stray) {
            if ($stray === '.' || $stray === '..') { continue; }
            $p = $directory . '/' . $stray;
            if (is_file($p) && !is_link($p)) { unlink($p); }
        }
        if (!rmdir($directory)) {
            throw new ConfigurationException("unable to remove workspace directory: {$directory}");
        }
    }
}
