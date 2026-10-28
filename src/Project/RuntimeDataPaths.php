<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class RuntimeDataPaths
{
    private const WRITABLE_DATA_DIRECTORIES = [
        'public/storage',
        'plugin/saiadmin/public/export',
    ];

    private const DEV_FIXTURE_DIRECTORIES = [
        'test', 'tests', 'testing', 'doc', 'docs',
        'fixture', 'fixtures', 'spec', 'specs', 'benchmark', 'benchmarks',
        '.github', '.gitlab',
    ];

    public static function isDevFixturePath(string $path): bool
    {
        foreach (explode('/', str_replace('\\', '/', $path)) as $component) {
            if (in_array(strtolower($component), self::DEV_FIXTURE_DIRECTORIES, true)) {
                return true;
            }
        }

        return false;
    }

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
        $hasPhp = false;
        foreach (token_get_all($contents) as $token) {
            if (!is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true)) {
                $hasPhp = true;
            }
            if ($token[0] === T_INLINE_HTML && trim($token[1]) !== '') {
                throw new ConfigurationException(
                    "environment-named PHP contains non-PHP data: {$relativePath}; "
                    . 'keep environment data in .env and provide PHP source separately'
                );
            }
        }
        if (!$hasPhp) {
            throw new ConfigurationException("environment-named PHP has no PHP source: {$relativePath}");
        }
    }

    public static function assertSafeWritableDirectories(string $projectDirectory): void
    {
        $root = rtrim($projectDirectory, '/\\');
        foreach (self::WRITABLE_DATA_DIRECTORIES as $directory) {
            $absolute = $root . '/' . $directory;
            if (is_link($absolute)) {
                throw new ConfigurationException(
                    "writable runtime directory is a symlink: {$directory}"
                );
            }
            if (!is_dir($absolute)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                $relative = $directory . substr(
                    str_replace('\\', '/', $file->getPathname()),
                    strlen(str_replace('\\', '/', $absolute))
                );
                if ($file->isLink()) {
                    throw new ConfigurationException(
                        "writable runtime directory contains symlink: {$relative}"
                    );
                }
            }
        }
    }

    /**
     * @return list<array{path:string,role:string}>
     */
    public static function writableDirectories(string $projectDirectory): array
    {
        $directories = [
            ['path' => 'public/storage', 'role' => 'uploads'],
            ['path' => 'runtime/logs', 'role' => 'logs'],
        ];
        if (is_dir(rtrim($projectDirectory, '/\\') . '/plugin/saiadmin')) {
            $directories[] = [
                'path' => 'plugin/saiadmin/public/export',
                'role' => 'uploads',
            ];
        }

        return $directories;
    }
}
