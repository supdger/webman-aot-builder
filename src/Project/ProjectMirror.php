<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class ProjectMirror
{
    private const EXCLUDED_PATHS = [
        '.git',
        '.typephp/build',
        '.webman-aot-builder/build',
        '.webman-aot-builder/cache',
        '.webman-aot-builder/runs',
        '.webman-aot-builder/releases',
        '.webman-aot-builder/build.lock',
        '.webman-aot-builder/workspace.json',
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
        if (!is_string($mirror) || is_link($path) || basename($mirror) !== 'project'
            || preg_match('/^attempt-[a-f0-9]{24}$/D', basename(dirname($mirror))) !== 1
        ) { return false; }
        return ProjectWorkspace::rootFromBuild(dirname($mirror), $project) !== null;
    }

    public static function sourceInputManifestPath(string $mirror): ?string
    {
        return self::isOwnedPath($mirror) ? dirname((string) realpath($mirror)) . '/source-inputs.json' : null;
    }

    public static function sourceAliasManifestPath(string $mirror): ?string
    {
        $inputs = self::sourceInputManifestPath($mirror);
        return is_string($inputs) ? dirname($inputs) . '/source-aliases.json' : null;
    }

    public static function generationMetadataDirectory(string $mirror): ?string
    {
        $inputs = self::sourceInputManifestPath($mirror);
        return is_string($inputs) ? dirname($inputs) . '/metadata' : null;
    }

    public static function generationSourceDirectory(string $mirror): ?string
    {
        $inputFile = self::sourceInputManifestPath($mirror);
        if (!is_string($inputFile)) { return null; }
        $metadata = self::generationMetadataDirectory($mirror);
        if (!is_string($metadata)) { return null; }
        if (is_link($metadata) || (!is_dir($metadata) && !mkdir($metadata, 0700))) {
            throw new ConfigurationException('unable to allocate generation metadata');
        }
        $recordFile = $metadata . '/source-directory.json';
        $inputs = is_file($inputFile) && !is_link($inputFile)
            ? json_decode((string) file_get_contents($inputFile), true) : null;
        if (!is_array($inputs) || !is_array($inputs['paths'] ?? null)) {
            throw new ConfigurationException('owned mirror source input record is missing');
        }
        $originalPaths = $inputs['paths'];
        $workspace = ProjectWorkspace::rootFromBuild(dirname($mirror));
        $workspaceMetadata = is_string($workspace)
            ? json_decode((string) file_get_contents($workspace . '/workspace.json'), true) : null;
        $originalProject = is_array($workspaceMetadata) ? ($workspaceMetadata['projectDirectory'] ?? null) : null;
        if (!is_string($originalProject)) { throw new ConfigurationException('owned mirror project record is missing'); }
        $collides = static function (string $relative) use ($originalPaths): bool {
            foreach ($originalPaths as $path) {
                if (!is_string($path) || $path === $relative
                    || str_starts_with($path, $relative . '/')
                    || str_starts_with($relative, $path . '/')
                ) { return true; }
            }
            return false;
        };
        if (file_exists($recordFile) || is_link($recordFile)) {
            $record = is_file($recordFile) && !is_link($recordFile)
                ? json_decode((string) file_get_contents($recordFile), true) : null;
            $relative = $record['relative'] ?? null;
            if (!is_array($record) || ($record['schema'] ?? null) !== 'webman-aot-builder-generation-source-directory-v1'
                || !is_string($relative)
                || preg_match('#^(?:\.typephp|\.typephp-generated-[a-f0-9]{16})/build$#D', $relative) !== 1
                || $collides($relative)
            ) { throw new ConfigurationException('owned generation source record is invalid'); }
            $directory = $mirror . '/' . $relative;
            if (!is_dir($directory) || is_link($directory) || is_link(dirname($directory))) {
                throw new ConfigurationException('owned generation source directory is unsafe');
            }
            return $directory;
        }
        $relative = '.typephp/build';
        if ($collides('.typephp') || file_exists($mirror . '/.typephp') || is_link($mirror . '/.typephp')
            || file_exists($originalProject . '/.typephp') || is_link($originalProject . '/.typephp')
        ) {
            do { $relative = '.typephp-generated-' . bin2hex(random_bytes(8)) . '/build'; }
            while ($collides(dirname($relative)) || file_exists($mirror . '/' . dirname($relative)) || is_link($mirror . '/' . dirname($relative))
                || file_exists($originalProject . '/' . dirname($relative)) || is_link($originalProject . '/' . dirname($relative)));
        }
        $directory = $mirror . '/' . $relative;
        if (!mkdir($directory, 0700, true)) {
            throw new ConfigurationException('unable to allocate generation source directory');
        }
        if (file_put_contents($recordFile, json_encode([
            'schema' => 'webman-aot-builder-generation-source-directory-v1',
            'relative' => $relative,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n") === false) {
            throw new ConfigurationException('unable to record generation source directory');
        }
        return $directory;
    }

    /**
     * @return array{path:string,files:int,sha256:string,sourceSha256:string}
     */
    public function create(string $buildDirectory): array
    {
        $project = realpath($this->projectDirectory);
        $build = realpath($buildDirectory);
        if (!is_string($project) || !is_string($build)
            || ProjectWorkspace::rootFromBuild($build, $project) === null
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
            $this->writeSourceAliases($candidate, $before);
            $this->activate($candidate, $destination, $sourceSnapshot, $before);
            $inputFile = self::sourceInputManifestPath($destination);
            if (!is_string($inputFile) || is_link($inputFile)
                || file_put_contents($inputFile, json_encode([
                    'schema' => 'webman-aot-builder-source-inputs-v1',
                    'paths' => array_keys($before['digests']),
                    'digests' => $before['digests'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n") === false
            ) { throw new ConfigurationException('unable to record original mirrored source inputs'); }
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

    /** @param array{sha256:string,files:int,digests:array<string,string>,identities:array<string,string>,links:array<string,string>,cycles:array<string,string>} $before */
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
        $inputs = RuntimeDataPaths::sourceFiles($project, self::EXCLUDED_PATHS);
        $files = [];
        foreach ($inputs['files'] as $relative => $input) {
            $target = $candidate . '/' . $relative;
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new ConfigurationException("unable to prepare mirrored file: {$relative}");
            }
            $php = strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'php';
            if (!$php && !is_readable($input['absolute'])) {
                fwrite(STDERR, "[input] skipped unreadable optional file: {$relative}\n");
                continue;
            }
            $sourceDigest = @hash_file('sha256', $input['absolute']);
            $copied = is_string($sourceDigest) && @copy($input['absolute'], $target);
            if (!$copied || hash_file('sha256', $target) !== $sourceDigest) {
                if (!$php) {
                    if (is_file($target)) { unlink($target); }
                    fwrite(STDERR, "[input] skipped unavailable optional file: {$relative}\n");
                    continue;
                }
                throw new ConfigurationException("project mirror copy drift: {$relative}");
            }
            $files[$relative] = $sourceDigest;
        }
        return $files;
    }

    /** @param array{digests:array<string,string>,identities:array<string,string>,links:array<string,string>,cycles:array<string,string>} $snapshot */
    private function writeSourceAliases(string $candidate, array $snapshot): void
    {
        if ($snapshot['links'] === [] && $snapshot['cycles'] === []) {
            return;
        }
        $groups = [];
        foreach ($snapshot['identities'] as $path => $identity) {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
                $groups[$identity][] = $path;
            }
        }
        $sources = [];
        $project = str_replace('\\', '/', (string) realpath($this->projectDirectory));
        foreach ($groups as $identity => $paths) {
            sort($paths, SORT_STRING);
            $representative = $paths[0];
            foreach ($paths as $path) {
                if (hash('sha256', $project . '/' . $path) === $identity) {
                    $representative = $path;
                    break;
                }
            }
            foreach ($paths as $path) {
                $sources[$path] = [
                    'canonicalSha256' => $identity,
                    'sourceSha256' => $snapshot['digests'][$path],
                    'representative' => $representative,
                ];
            }
        }
        ksort($sources, SORT_STRING);
        $build = dirname($candidate);
        if ((is_dir($build) || mkdir($build, 0700, true))
            && file_put_contents($build . '/source-aliases.json', json_encode([
                'schema' => 'webman-aot-builder-source-aliases-v1',
                'sources' => $sources,
                'links' => $snapshot['links'],
                'cycles' => $snapshot['cycles'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n") !== false
        ) {
            return;
        }
        throw new ConfigurationException('unable to write mirrored source alias evidence');
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
