<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class ProjectMirror
{
    private const EXCLUDED_ROOTS = [
        '.git',
        '.webman-aot-builder',
        '.webman-aot',
        'dist-aot',
        'runtime',
        'node_modules',
    ];

    /** @var \Closure(string,string):bool */
    private readonly \Closure $move;

    /** @param (\Closure(string,string):bool)|null $move */
    public function __construct(
        private readonly string $projectDirectory,
        ?\Closure $move = null
    ) {
        $this->move = $move ?? static fn (string $from, string $to): bool => rename($from, $to);
    }

    /** Validate both the legacy mirror and independently owned attempt paths. */
    public static function isOwnedPath(string $path, ?string $project = null): bool
    {
        $mirror = realpath($path);
        if (!is_string($mirror) || is_link($path) || basename($mirror) !== 'project') { return false; }
        $build = dirname($mirror);
        if (preg_match('/^attempt-[a-f0-9]{24}$/D', basename($build)) === 1) { $build = dirname($build); }
        $workspace = dirname($build);
        return basename($build) === 'build' && basename($workspace) === '.webman-aot-builder'
            && ($project === null || dirname($workspace) === realpath($project));
    }

    /**
     * @return array{path:string,files:int,sha256:string,sourceSha256:string}
     */
    public function create(string $buildDirectory): array
    {
        $project = realpath($this->projectDirectory);
        $build = realpath($buildDirectory);
        $expected = is_string($project)
            ? realpath($project . '/.webman-aot-builder/build')
            : false;
        if (!is_string($project)
            || !is_string($build)
            || ($build !== $expected
                && !(dirname($build) === $expected
                    && preg_match('/^attempt-[a-f0-9]{24}$/D', basename($build)) === 1))
            || is_link($buildDirectory)
        ) {
            throw new ConfigurationException('project mirror requires the owned build workspace');
        }
        $destination = $build . '/project';
        if (file_exists($destination) || is_link($destination)) {
            throw new ConfigurationException('project build mirror already exists');
        }
        $sourceSnapshot = new SourceTreeSnapshot($project);
        $before = $sourceSnapshot->captureWithFiles();
        $candidate = $build . '/.project-' . bin2hex(random_bytes(8));
        if (!mkdir($candidate, 0700)) {
            throw new ConfigurationException('unable to create project build mirror');
        }
        try {
            $files = $this->copyProject($project, $candidate);
            $after = $sourceSnapshot->captureWithFiles();
            if ($after !== $before) {
                throw new ConfigurationException(
                    'project source changed while creating build mirror: '
                    . $this->describeChanges($before['digests'], $after['digests'])
                    . '; wait for source writes to finish and retry webman-aot build'
                );
            }
            ksort($files, SORT_STRING);
            $context = hash_init('sha256');
            foreach ($files as $path => $digest) {
                hash_update($context, $path . "\0" . $digest . "\n");
            }
            $this->activate($candidate, $destination, $sourceSnapshot, $before);
            return [
                'path' => $destination,
                'files' => count($files),
                'sha256' => hash_final($context),
                'sourceSha256' => $before['sha256'],
            ];
        } finally {
            if (is_dir($candidate)) {
                $this->removeCandidate($candidate);
            }
        }
    }

    /** @param array{sha256:string,files:int,digests:array<string,string>} $before */
    private function activate(
        string $candidate,
        string $destination,
        SourceTreeSnapshot $sourceSnapshot,
        array $before
    ): void {
        $reason = 'rename returned false';
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            clearstatcache(true, $destination);
            if (file_exists($destination) || is_link($destination)) {
                throw new ConfigurationException('project build mirror already exists; wait for the active build to finish and retry webman-aot build');
            }
            if ($attempt > 0) {
                $after = $sourceSnapshot->captureWithFiles();
                if ($after !== $before) {
                    throw new ConfigurationException(
                        'project source changed while activating build mirror: '
                        . $this->describeChanges($before['digests'], $after['digests'])
                        . '; wait for source writes to finish and retry webman-aot build'
                    );
                }
            }
            set_error_handler(static function (int $severity, string $message) use (&$reason): bool {
                $reason = $message;
                return true;
            }, E_WARNING);
            try {
                $moved = ($this->move)($candidate, $destination);
            } finally {
                restore_error_handler();
            }
            if ($moved) {
                return;
            }
            if ($attempt < 5) {
                usleep(50000 * (2 ** $attempt));
            }
        }
        $reason = str_replace([$candidate, $destination], ['<candidate>', '<project>'], $reason);
        $reason = preg_replace('/[\x00-\x20\x7f]+/', ' ', $reason) ?? 'rename failed';
        throw new ConfigurationException(
            'unable to activate project build mirror after 6 attempts: ' . substr($reason, 0, 400)
            . '; close programs holding files in .webman-aot-builder/build, check directory permissions, and retry webman-aot build'
        );
    }

    /**
     * @return array<string,string>
     */
    private function copyProject(string $project, string $candidate): array
    {
        $directory = new \RecursiveDirectoryIterator(
            $project,
            \FilesystemIterator::SKIP_DOTS
        );
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            function (\SplFileInfo $entry) use ($project): bool {
                $relative = str_replace(
                    '\\',
                    '/',
                    substr($entry->getPathname(), strlen($project) + 1)
                );
                $root = explode('/', $relative, 2)[0];
                if (in_array($root, self::EXCLUDED_ROOTS, true)
                    || RuntimeDataPaths::isSourceExcluded($relative)
                    || $entry->getFilename() === '.DS_Store'
                    || preg_match('/^\.env(?:\..+)?$/D', $entry->getFilename()) === 1
                ) {
                    return false;
                }
                if ($entry->isLink()) {
                    throw new ConfigurationException("project mirror refuses symlink: {$relative}");
                }
                return true;
            }
        );
        $files = [];
        foreach (new \RecursiveIteratorIterator($filter) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $relative = str_replace(
                '\\',
                '/',
                substr($entry->getPathname(), strlen($project) + 1)
            );
            $target = $candidate . '/' . $relative;
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new ConfigurationException("unable to prepare mirrored file: {$relative}");
            }
            $sourceDigest = hash_file('sha256', $entry->getPathname());
            if (!is_string($sourceDigest)
                || !copy($entry->getPathname(), $target)
                || hash_file('sha256', $target) !== $sourceDigest
            ) {
                throw new ConfigurationException("project mirror copy drift: {$relative}");
            }
            $files[$relative] = $sourceDigest;
        }
        return $files;
    }

    private function removeCandidate(string $candidate): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($candidate, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($candidate);
    }

    /**
     * @param array<string,string> $before
     * @param array<string,string> $after
     */
    private function describeChanges(array $before, array $after): string
    {
        $paths = array_unique(array_merge(array_keys($before), array_keys($after)));
        sort($paths, SORT_STRING);
        $changes = [];
        $count = 0;
        foreach ($paths as $path) {
            if (($before[$path] ?? null) === ($after[$path] ?? null)) {
                continue;
            }
            ++$count;
            if (count($changes) >= 5) {
                continue;
            }
            $kind = !isset($before[$path]) ? 'added' : (!isset($after[$path]) ? 'removed' : 'modified');
            $displayPath = strlen($path) > 120 ? substr($path, 0, 120) . '...' : $path;
            $changes[] = $kind . ' ' . json_encode(
                $displayPath,
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
        }
        $remaining = $count - count($changes);

        return implode(', ', $changes)
            . ($remaining > 0 ? " (+{$remaining} more)" : '');
    }
}
