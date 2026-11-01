<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

final class UnifiedPatchApplier
{
    /** Verify the complete numbered patch chain before any source is replaced. */
    public function verifyManifestCoverage(string $manifestFile, array $rules): void
    {
        $patches = $this->enumeratePatches(dirname($manifestFile));
        if ($patches === [] && preg_match('~/toolchain/patches/typephp/[^/]+/manifest\.json$~D', str_replace('\\', '/', $manifestFile))) {
            throw new \RuntimeException('official TypePHP patch chain is missing');
        }
        $covered = [];
        foreach ($rules as $rule) { $covered[(string) ($rule['path'] ?? '')] = $rule; }
        $created = [];
        foreach ($patches as $patch) {
            $source = is_file($patch) && !is_link($patch) ? file_get_contents($patch) : false;
            if (!is_string($source)) { throw new \RuntimeException('unsafe TypePHP source patch: ' . $patch); }
            $lines = explode("\n", str_replace("\r\n", "\n", $source));
            $targets = 0;
            foreach ($lines as $index => $line) {
                $create = $line === '--- /dev/null';
                if (!$create && !str_starts_with($line, '--- a/')) { continue; }
                $header = $lines[$index + 1] ?? '';
                if (!str_starts_with($header, '+++ b/')) { throw new \RuntimeException('missing TypePHP patch target header'); }
                $path = substr($header, 6);
                $this->assertSafeRelativePath($path);
                if (str_contains($path, '\\') || str_contains($path, '//') || in_array('.', explode('/', $path), true)) {
                    throw new \RuntimeException('unsafe patch target path: ' . $path);
                }
                if ((!$create && substr($line, 6) !== $path) || !isset($covered[$path])) {
                    throw new \RuntimeException('TypePHP patch target missing from manifest: ' . $path);
                }
                if ($create) {
                    if (($covered[$path]['added'] ?? false) !== true || isset($created[$path])) {
                        throw new \RuntimeException('TypePHP patch creation identity differs from manifest: ' . $path);
                    }
                    $created[$path] = true;
                } elseif (($covered[$path]['added'] ?? false) === true && !isset($created[$path])) {
                    throw new \RuntimeException('TypePHP added source lacks preceding creation patch: ' . $path);
                }
                ++$targets;
            }
            if ($targets === 0) { throw new \RuntimeException('TypePHP source patch has no targets: ' . $patch); }
        }
        if ($patches !== []) {
            foreach ($covered as $path => $rule) {
                if (($rule['added'] ?? false) === true && !isset($created[$path])) {
                    throw new \RuntimeException('TypePHP added source lacks creation patch: ' . $path);
                }
            }
        }
    }

    /**
     * Enumerate the complete numbered patch chain of a patch directory in application order.
     *
     * Every caller that applies, hashes or verifies the chain must use this single source of
     * truth instead of a hand-maintained list: a stale list silently drops patches and only
     * surfaces as an unexplained afterSha256 mismatch much later.
     *
     * @return list<string> absolute patch paths sorted by their four-digit number
     */
    public function enumeratePatches(string $patchDirectory): array
    {
        $patchDirectory = rtrim(str_replace('\\', '/', $patchDirectory), '/');
        $patches = glob($patchDirectory . '/[0-9][0-9][0-9][0-9]-*.patch');
        if ($patches === false) {
            throw new \RuntimeException('unable to enumerate TypePHP source patches');
        }
        $patches = array_map(static fn (string $path): string => str_replace('\\', '/', $path), $patches);
        sort($patches, SORT_STRING);
        $numbers = [];
        foreach ($patches as $patch) {
            $number = substr(basename($patch), 0, 4);
            if (isset($numbers[$number])) {
                throw new \RuntimeException('duplicate TypePHP patch number: ' . $number);
            }
            $numbers[$number] = true;
        }
        return array_values($patches);
    }

    /** Apply one patch file; equivalent to applyChain([$patchPath], $root). */
    public function apply(string $patchPath, string $root): void
    {
        $this->applyChain([$patchPath], $root);
    }

    /**
     * Apply an ordered chain of patches atomically.
     *
     * All hunks of all patches are matched in memory first (later patches see the in-memory
     * result of earlier ones), so a failure anywhere in the chain leaves the tree untouched and
     * names the offending patch, hunk and target. Only after the whole chain matched are the
     * results staged and written, with rollback on write failure.
     *
     * @param list<string> $patchPaths
     * @return list<string> basenames of the applied patches, in order
     */
    public function applyChain(array $patchPaths, string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($root === '' || !is_dir($root)) {
            throw new \RuntimeException("patch root does not exist: {$root}");
        }
        if ($patchPaths === []) {
            throw new \RuntimeException('patch chain is empty');
        }

        /** @var array<string, array{source:string|null,result:string,create:bool,mode:int,patches:list<string>}> $changes */
        $changes = [];
        $applied = [];
        foreach ($patchPaths as $patchPath) {
            $this->computeChanges((string) $patchPath, $root, $changes);
            $applied[] = basename((string) $patchPath);
        }
        $this->writeChanges($changes);
        return $applied;
    }

    /**
     * Parse one patch and apply its hunks against the current overlay (disk + earlier patches).
     *
     * @param array<string, array{source:string|null,result:string,create:bool,mode:int,patches:list<string>}> $changes
     */
    private function computeChanges(string $patchPath, string $root, array &$changes): void
    {
        $contents = is_file($patchPath) && !is_link($patchPath) ? file_get_contents($patchPath) : false;
        if ($contents === false) {
            throw new \RuntimeException("unable to read patch: {$patchPath}");
        }
        $patchName = basename($patchPath);

        $lines = explode("\n", str_replace("\r\n", "\n", $contents));
        $index = 0;
        $touched = [];
        while ($index < count($lines)) {
            if ($lines[$index] === '') {
                $index++;
                continue;
            }
            $create = $lines[$index] === '--- /dev/null';
            if (!$create && !str_starts_with($lines[$index], '--- a/')) {
                throw new \RuntimeException(
                    sprintf('unsupported patch header at line %d in %s', $index + 1, $patchPath)
                );
            }

            $oldPath = $create ? null : substr($lines[$index], strlen('--- a/'));
            $index++;
            if (!isset($lines[$index]) || !str_starts_with($lines[$index], '+++ b/')) {
                throw new \RuntimeException(
                    sprintf('missing new-file header at line %d in %s', $index + 1, $patchPath)
                );
            }
            $newPath = substr($lines[$index], strlen('+++ b/'));
            $index++;
            if (!$create && $oldPath !== $newPath) {
                throw new \RuntimeException('file deletion and rename patches are not supported');
            }
            $oldPath = $newPath;
            $this->assertSafeRelativePath($newPath);

            $target = $root . '/' . $newPath;
            $this->assertSafeTarget($root, $newPath);
            if (isset($touched[$target]) || ($create && (isset($changes[$target]) || file_exists($target)))) {
                throw new \RuntimeException(
                    "patch cannot create or repeat an existing target: {$target} ({$patchName})"
                );
            }
            $touched[$target] = true;
            $previous = $changes[$target] ?? null;
            if ($create) {
                $source = '';
            } elseif ($previous !== null) {
                // Later patches in the chain operate on the in-memory result of earlier ones.
                $source = $previous['result'];
            } else {
                $source = is_file($target) && !is_link($target) ? file_get_contents($target) : false;
                if ($source === false) {
                    throw new \RuntimeException("patch target does not exist: {$target} ({$patchName})");
                }
            }
            $sourceHadCr = !$create && str_contains($source, "\r");
            $trailingNewline = $create || str_ends_with($source, "\n");
            $sourceLines = $create ? [] : explode("\n", str_replace(["\r\n", "\r"], "\n", $source));
            if (!$create && $trailingNewline) {
                array_pop($sourceLines);
            }

            $offset = 0;
            $hunkCount = 0;
            while ($index < count($lines) && !str_starts_with($lines[$index], '--- ')) {
                if ($lines[$index] === '') {
                    $index++;
                    continue;
                }
                if (!preg_match(
                    '/^@@ -([0-9]+)(?:,([0-9]+))? \+([0-9]+)(?:,([0-9]+))? @@(?: .*)?$/D',
                    $lines[$index],
                    $matches
                )) {
                    throw new \RuntimeException(
                        sprintf('unsupported hunk header at line %d in %s', $index + 1, $patchPath)
                    );
                }
                $oldStart = (int) $matches[1];
                $oldCount = ($matches[2] ?? '') === '' ? 1 : (int) $matches[2];
                $newCount = ($matches[4] ?? '') === '' ? 1 : (int) $matches[4];
                $index++;

                $before = [];
                $after = [];
                while ($index < count($lines)
                    && !str_starts_with($lines[$index], '@@ ')
                    && !str_starts_with($lines[$index], '--- ')
                ) {
                    $line = $lines[$index];
                    if ($line === '\\ No newline at end of file') {
                        throw new \RuntimeException('patches without a final newline are not supported');
                    }
                    if ($line === '') {
                        break;
                    }
                    $marker = $line[0];
                    $value = substr($line, 1);
                    if ($marker === ' ') {
                        $before[] = $value;
                        $after[] = $value;
                    } elseif ($marker === '-') {
                        $before[] = $value;
                    } elseif ($marker === '+') {
                        $after[] = $value;
                    } else {
                        throw new \RuntimeException(
                            sprintf('unsupported patch line at %d in %s', $index + 1, $patchPath)
                        );
                    }
                    $index++;
                }

                if (count($before) !== $oldCount || count($after) !== $newCount) {
                    throw new \RuntimeException(
                        sprintf('hunk line count mismatch for %s at old line %d', $oldPath, $oldStart)
                    );
                }
                if ($create && ($hunkCount !== 0 || $oldStart !== 0 || $oldCount !== 0
                    || (int) $matches[3] !== 1)) {
                    throw new \RuntimeException("new-file patch must contain one complete hunk: {$newPath}");
                }
                $position = ($create ? 0 : $oldStart - 1) + $offset;
                $actual = $position < 0 ? [] : array_slice($sourceLines, $position, $oldCount);
                if ($position < 0 || $actual !== $before) {
                    // Never skip: an unmatched hunk is a hard error naming patch, hunk and target.
                    throw new \RuntimeException($this->describeHunkMismatch(
                        $patchName, $hunkCount + 1, $oldPath, $oldStart, $position, $before, $actual,
                        $sourceHadCr, $previous['patches'] ?? []
                    ));
                }
                array_splice($sourceLines, $position, $oldCount, $after);
                $offset += $newCount - $oldCount;
                $hunkCount++;
            }

            if ($hunkCount === 0) {
                throw new \RuntimeException("patch contains no hunks for {$oldPath} ({$patchName})");
            }
            $result = implode("\n", $sourceLines) . ($trailingNewline ? "\n" : '');
            $changes[$target] = [
                // The on-disk content the write phase must still observe (null for created files).
                'source' => $previous !== null ? $previous['source'] : ($create ? null : $source),
                'result' => $result,
                'create' => $previous !== null ? $previous['create'] : $create,
                'mode' => $previous !== null ? $previous['mode'] : ($create ? 0644 : (fileperms($target) & 0777)),
                'patches' => array_merge($previous['patches'] ?? [], [$patchName]),
            ];
        }
        if ($touched === []) {
            throw new \RuntimeException("patch has no targets: {$patchName}");
        }
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     * @param list<string> $earlierPatches
     */
    private function describeHunkMismatch(
        string $patchName,
        int $hunkNumber,
        string $target,
        int $oldStart,
        int $position,
        array $expected,
        array $actual,
        bool $sourceHadCr,
        array $earlierPatches
    ): string {
        $firstDifference = null;
        $limit = max(count($expected), count($actual));
        for ($line = 0; $line < $limit; $line++) {
            if (($expected[$line] ?? null) !== ($actual[$line] ?? null)) {
                $firstDifference = $line;
                break;
            }
        }
        $message = sprintf(
            'hunk context mismatch: patch %s hunk #%d target %s at old line %d (resolved line %d)',
            $patchName,
            $hunkNumber,
            $target,
            $oldStart,
            $position + 1
        );
        if ($firstDifference !== null) {
            $message .= sprintf(
                '; first difference at context line %d: expected %s, found %s',
                $firstDifference + 1,
                json_encode($expected[$firstDifference] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($actual[$firstDifference] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }
        if ($sourceHadCr) {
            $message .= '; target contained CR line endings (normalized to LF before matching)';
        }
        if ($earlierPatches !== []) {
            $message .= '; target already modified in this chain by: ' . implode(', ', $earlierPatches);
        }
        return $message;
    }

    /**
     * Stage every result next to its target, then publish all of them; roll back on failure.
     *
     * @param array<string, array{source:string|null,result:string,create:bool,mode:int,patches:list<string>}> $changes
     */
    private function writeChanges(array $changes): void
    {
        $staged = [];
        $applied = [];
        try {
            // Validate every hunk before touching any target, then stage writes in the same directory.
            foreach ($changes as $target => $change) {
                $temporary = tempnam(dirname($target), '.typephp-patch-');
                if ($temporary === false) {
                    throw new \RuntimeException("unable to stage patched file: {$target}");
                }
                $staged[$target] = $temporary;
                if (file_put_contents($temporary, $change['result']) !== strlen($change['result'])
                    || !chmod($temporary, $change['mode'])) {
                    throw new \RuntimeException("unable to stage patched file: {$target}");
                }
            }
            foreach ($changes as $target => $change) {
                if ($change['create']) {
                    // link() creates a missing path atomically and never replaces an existing file.
                    if (!link($staged[$target], $target)) {
                        throw new \RuntimeException("patch creation target already exists or cannot be written: {$target}");
                    }
                    unlink($staged[$target]);
                } elseif (!is_file($target) || is_link($target)
                    || file_get_contents($target) !== $change['source']
                    || !rename($staged[$target], $target)) {
                    throw new \RuntimeException("patch target changed or cannot be written: {$target}");
                }
                unset($staged[$target]);
                $applied[$target] = $change;
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($applied, true) as $target => $change) {
                if (is_file($target) && !is_link($target)
                    && hash_file('sha256', $target) === hash('sha256', $change['result'])) {
                    if ($change['create']) {
                        unlink($target);
                    } else {
                        file_put_contents($target, $change['source']);
                        chmod($target, $change['mode']);
                    }
                }
            }
            throw $exception;
        } finally {
            foreach ($staged as $temporary) {
                if (is_file($temporary)) { unlink($temporary); }
            }
        }
    }

    private function assertSafeTarget(string $root, string $path): void
    {
        $current = $root;
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            $current .= '/' . $part;
            if (is_link($current)) {
                throw new \RuntimeException("symlink patch target is not supported: {$path}");
            }
        }
        if (!is_dir(dirname($current))) {
            throw new \RuntimeException("patch target parent does not exist: {$path}");
        }
    }

    private function assertSafeRelativePath(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === ''
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//D', $normalized) === 1
            || in_array('..', explode('/', $normalized), true)
        ) {
            throw new \RuntimeException("unsafe patch path: {$path}");
        }
    }
}
