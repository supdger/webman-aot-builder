<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

final class UnifiedPatchApplier
{
    /** Verify the complete numbered patch chain before any source is replaced. */
    public function verifyManifestCoverage(string $manifestFile, array $rules): void
    {
        $patches = glob(dirname($manifestFile) . '/[0-9][0-9][0-9][0-9]-*.patch');
        if ($patches === false) { throw new \RuntimeException('unable to enumerate TypePHP source patches'); }
        sort($patches, SORT_STRING);
        if ($patches === [] && preg_match('~/toolchain/patches/typephp/[^/]+/manifest\.json$~D', str_replace('\\', '/', $manifestFile))) {
            throw new \RuntimeException('official TypePHP patch chain is missing');
        }
        $covered = [];
        foreach ($rules as $rule) { $covered[(string) ($rule['path'] ?? '')] = $rule; }
        $created = [];
        $numbers = [];
        foreach ($patches as $patch) {
            $number = substr(basename($patch), 0, 4);
            if (isset($numbers[$number])) { throw new \RuntimeException('duplicate TypePHP patch number: ' . $number); }
            $numbers[$number] = true;
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

    public function apply(string $patchPath, string $root): void
    {
        $contents = file_get_contents($patchPath);
        if ($contents === false) {
            throw new \RuntimeException("unable to read patch: {$patchPath}");
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($root === '' || !is_dir($root)) {
            throw new \RuntimeException("patch root does not exist: {$root}");
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $contents));
        $index = 0;
        $changes = [];
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
            if (isset($changes[$target]) || ($create && file_exists($target))) {
                throw new \RuntimeException("patch cannot create or repeat an existing target: {$target}");
            }
            $source = $create ? '' : file_get_contents($target);
            if ($source === false) {
                throw new \RuntimeException("patch target does not exist: {$target}");
            }
            $trailingNewline = $create || str_ends_with($source, "\n");
            $sourceLines = $create ? [] : explode("\n", str_replace("\r\n", "\n", $source));
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
                if ($position < 0 || array_slice($sourceLines, $position, $oldCount) !== $before) {
                    throw new \RuntimeException(
                        sprintf('hunk context mismatch for %s at old line %d', $oldPath, $oldStart)
                    );
                }
                array_splice($sourceLines, $position, $oldCount, $after);
                $offset += $newCount - $oldCount;
                $hunkCount++;
            }

            if ($hunkCount === 0) {
                throw new \RuntimeException("patch contains no hunks for {$oldPath}");
            }
            $result = implode("\n", $sourceLines) . ($trailingNewline ? "\n" : '');
            $changes[$target] = ['source' => $source, 'result' => $result, 'create' => $create,
                'mode' => $create ? 0644 : (fileperms($target) & 0777)];
        }
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
