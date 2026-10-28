<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Cli\UnavailableException;

final class MinimalComponent
{
    /**
     * @param \Closure(string):void|null $progress
     */
    public function extract(
        string $archive,
        string $expectedArchiveSha256,
        string $candidate,
        string $host,
        string $expectedLockSha256,
        ?\Closure $progress = null
    ): string {
        if (!is_file($archive) || is_link($archive)
            || !hash_equals($expectedArchiveSha256, (string) hash_file('sha256', $archive))
        ) {
            throw new UnavailableException('minimal toolchain component is missing or its SHA-256 differs');
        }
        if (file_exists($candidate) || is_link($candidate)
            || !mkdir($candidate, 0700, true)
        ) {
            throw new ConfigurationException('minimal toolchain candidate is not an empty new directory');
        }
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new UnavailableException('minimal toolchain component ZIP cannot be opened');
        }
        try {
            $manifestJson = $zip->getFromName('minimal-component.json');
            $manifest = is_string($manifestJson)
                ? json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR)
                : null;
            $entries = is_array($manifest) ? ($manifest['entries'] ?? null) : null;
            if (!is_array($entries)
                || ($manifest['schema'] ?? null) !== 'webman-aot-builder-minimal-component-v1'
                || ($manifest['host'] ?? null) !== $host
                || ($manifest['toolchainLockSha256'] ?? null) !== $expectedLockSha256
            ) {
                throw new ConfigurationException('minimal toolchain component host or lock differs');
            }
            $this->assertEntries($entries);
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || isset($names[$name])
                    || ($name !== 'minimal-component.json'
                        && (($entries[$name]['type'] ?? null) !== 'file'))
                ) {
                    throw new ConfigurationException('minimal component ZIP has an unknown or repeated entry');
                }
                $names[$name] = true;
            }
            foreach ($entries as $name => $entry) {
                if ($entry['type'] === 'file' && !isset($names[$name])) {
                    throw new ConfigurationException("minimal component ZIP is missing {$name}");
                }
            }
            foreach ($entries as $name => $entry) {
                if ($entry['type'] !== 'directory') {
                    continue;
                }
                $directory = $candidate . '/' . $name;
                if (!is_dir($directory)
                    && !mkdir($directory, 0700, true) && !is_dir($directory)
                ) {
                    throw new \RuntimeException("cannot create minimal component directory: {$name}");
                }
            }
            $done = 0;
            $total = count(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['type'] === 'file'
            ));
            foreach ($entries as $name => $entry) {
                if ($entry['type'] !== 'file') {
                    continue;
                }
                $target = $candidate . '/' . $name;
                $directory = dirname($target);
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new \RuntimeException("cannot create minimal component directory: {$name}");
                }
                $source = $zip->getStream($name);
                $destination = fopen($target, 'xb');
                if (!is_resource($source) || !is_resource($destination)) {
                    throw new \RuntimeException("cannot extract minimal component file: {$name}");
                }
                try {
                    $copied = stream_copy_to_stream($source, $destination);
                } finally {
                    fclose($source);
                    fclose($destination);
                }
                if ($copied !== $entry['size']
                    || !hash_equals($entry['sha256'], (string) hash_file('sha256', $target))
                    || !chmod($target, $entry['executable'] ? 0700 : 0600)
                ) {
                    throw new UnavailableException("minimal component file failed SHA-256 verification: {$name}");
                }
                $done++;
                if ($done % 250 === 0 || $done === $total) {
                    $progress?->__invoke("Extracted {$done}/{$total} verified entries");
                }
            }
            foreach ($entries as $name => $entry) {
                if ($entry['type'] !== 'link') {
                    continue;
                }
                $target = $candidate . '/' . $name;
                $directory = dirname($target);
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new \RuntimeException("cannot create minimal component link directory: {$name}");
                }
                if (!symlink($entry['target'], $target)) {
                    throw new \RuntimeException("cannot restore minimal component link: {$name}");
                }
            }
            if (file_put_contents($candidate . '/minimal-component.json', $manifestJson, LOCK_EX) === false) {
                throw new \RuntimeException('cannot preserve minimal component manifest');
            }
            $this->verifyGeneration(
                $candidate,
                $host,
                hash('sha256', $manifestJson),
                $expectedLockSha256
            );
            $progress?->__invoke('Minimal component SHA-256 verification complete');

            return hash('sha256', $manifestJson);
        } finally {
            $zip->close();
        }
    }

    /** Build a separate generation; every reused byte must match the new locked manifest. */
    public function reuse(
        string $previous,
        string $previousManifestSha256,
        string $previousLockSha256,
        string $manifestPath,
        string $replacementRoot,
        string $candidate,
        string $host,
        string $manifestSha256,
        string $lockSha256,
        ?\Closure $progress = null
    ): void {
        foreach ([$previous, $replacementRoot, dirname($candidate)] as $root) {
            if (is_link($root) || !is_dir($root) || realpath($root) === false) {
                throw new ConfigurationException('upgrade component root is unsafe');
            }
            for ($parent = $root; ; $parent = dirname($parent)) {
                if (is_link($parent)) { throw new ConfigurationException('upgrade component root has a linked ancestor'); }
                if ($parent === dirname($parent)) { break; }
            }
        }
        $previous = (string) realpath($previous);
        $replacementRoot = (string) realpath($replacementRoot);
        $candidate = realpath(dirname($candidate)) . '/' . basename($candidate);
        $this->verifyGeneration($previous, $host, $previousManifestSha256, $previousLockSha256);
        $json = is_file($manifestPath) && !is_link($manifestPath) ? file_get_contents($manifestPath) : false;
        if (!is_string($json) || !hash_equals($manifestSha256, hash('sha256', $json))) {
            throw new UnavailableException('upgrade component manifest differs from its lock');
        }
        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $entries = $manifest['entries'] ?? null;
        if (!is_array($entries) || ($manifest['schema'] ?? null) !== 'webman-aot-builder-minimal-component-v1'
            || ($manifest['host'] ?? null) !== $host || ($manifest['toolchainLockSha256'] ?? null) !== $lockSha256) {
            throw new ConfigurationException('upgrade component host or lock differs');
        }
        $this->assertEntries($entries);
        // A link may not be an ancestor of another entry, even when its target is inside the manifest.
        foreach ($entries as $name => $entry) {
            for ($parent = dirname($name); $parent !== '.'; $parent = dirname($parent)) {
                if (isset($entries[$parent]) && $entries[$parent]['type'] !== 'directory') {
                    throw new ConfigurationException('upgrade component parent is not a directory: ' . $name);
                }
            }
        }
        if (file_exists($candidate) || is_link($candidate) || !mkdir($candidate, 0700, true)) {
            throw new ConfigurationException('upgrade component candidate is not an empty new directory');
        }
        foreach ($entries as $name => $entry) {
            if ($entry['type'] === 'directory' && !is_dir($candidate . '/' . $name)
                && !mkdir($candidate . '/' . $name, 0700, true)) {
                throw new ConfigurationException('cannot create upgrade component directory: ' . $name);
            }
        }
        $reused = 0;
        $replaced = 0;
        $reusedBytes = 0;
        $replacedBytes = 0;
        $reviewedSources = is_file($replacementRoot . '/prepared/prepared-toolchain.json')
            ? $this->candidateSourceReplacements($replacementRoot) : [];
        foreach ($entries as $name => $entry) {
            if ($entry['type'] !== 'file') { continue; }
            $guard = $reviewedSources[$name] ?? null;
            $guarded = is_array($guard) && in_array($entry['sha256'], array_filter([
                $guard['beforeSha256'], $guard['preparedBeforeSha256'] ?? null, $guard['afterSha256'],
            ]), true);
            $expected = $guarded ? $guard['afterSha256'] : $entry['sha256'];
            $source = null;
            foreach ([$previous, $replacementRoot] as $root) {
                $path = $root . '/' . $name;
                $safe = !is_link($root);
                for ($parent = dirname($path); $safe && $parent !== $root; $parent = dirname($parent)) {
                    $safe = !is_link($parent) && is_dir($parent) && $parent !== dirname($parent);
                }
                if ($safe && is_file($path) && !is_link($path)
                    && ($expected !== $entry['sha256'] || filesize($path) === $entry['size'])
                    && hash_equals($expected, (string) hash_file('sha256', $path))) {
                    $source = $path;
                    break;
                }
            }
            if ($source === null) {
                throw new UnavailableException('no verified reusable or bundled replacement file: ' . $name);
            }
            $target = $candidate . '/' . $name;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) {
                throw new ConfigurationException('cannot create implicit upgrade parent: ' . $name);
            }
            if (!copy($source, $target) || !chmod($target, $entry['executable'] ? 0700 : 0600)
                || filesize($target) !== filesize($source)
                || !hash_equals($expected, (string) hash_file('sha256', $target))) {
                throw new UnavailableException('copied upgrade component differs: ' . $name);
            }
            if (str_starts_with($source, $previous . '/')) { $reused++; $reusedBytes += filesize($source); }
            else { $replaced++; $replacedBytes += filesize($source); }
            if (($reused + $replaced) % 250 === 0) {
                $progress?->__invoke('Copied ' . ($reused + $replaced) . ' verified component files');
            }
        }
        foreach ($entries as $name => $entry) {
            if ($entry['type'] === 'link' && !is_dir(dirname($candidate . '/' . $name))
                && !mkdir(dirname($candidate . '/' . $name), 0700, true)) {
                throw new ConfigurationException('cannot create implicit upgrade link parent');
            }
            if ($entry['type'] === 'link' && !symlink($entry['target'], $candidate . '/' . $name)) {
                throw new ConfigurationException('cannot restore upgrade component link: ' . $name);
            }
        }
        if (file_put_contents($candidate . '/minimal-component.json', $json, LOCK_EX) === false) {
            throw new ConfigurationException('cannot preserve upgrade component manifest');
        }
        $this->verifyGeneration($candidate, $host, $manifestSha256, $lockSha256);
        $progress?->__invoke(sprintf('Reused %d files (%d bytes); bundled replacements %d files (%d bytes).',
            $reused, $reusedBytes, $replaced, $replacedBytes));
    }

    public function verifyGeneration(
        string $generation,
        string $host,
        string $expectedManifestSha256,
        string $expectedLockSha256
    ): void {
        $path = $generation . '/minimal-component.json';
        $json = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($json)
            || !hash_equals($expectedManifestSha256, hash('sha256', $json))
        ) {
            throw new UnavailableException('installed minimal component manifest differs');
        }
        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $entries = is_array($manifest) ? ($manifest['entries'] ?? null) : null;
        if (!is_array($entries)
            || ($manifest['schema'] ?? null) !== 'webman-aot-builder-minimal-component-v1'
            || ($manifest['host'] ?? null) !== $host
            || ($manifest['toolchainLockSha256'] ?? null) !== $expectedLockSha256
            || !hash_equals(
                $expectedLockSha256,
                (string) hash_file('sha256', $generation . '/toolchain.lock.json')
            )
        ) {
            throw new ConfigurationException('installed minimal component host or lock differs');
        }
        $this->assertEntries($entries);
        $sourceReplacements = null;
        foreach ($entries as $name => $entry) {
            $path = $generation . '/' . $name;
            if ($entry['type'] === 'directory') {
                if (!is_dir($path) || is_link($path)) {
                    throw new UnavailableException("installed minimal component directory differs: {$name}");
                }
                continue;
            }
            if ($entry['type'] === 'link') {
                if (!is_link($path) || readlink($path) !== $entry['target']) {
                    throw new UnavailableException("installed minimal component link differs: {$name}");
                }
                continue;
            }
            $digest = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            if (is_string($digest) && hash_equals($entry['sha256'], $digest)
                && filesize($path) === $entry['size'] && is_executable($path) === $entry['executable']
            ) { continue; }
            $sourceReplacements ??= $this->candidateSourceReplacements($generation);
            $reviewedSource = isset($sourceReplacements[$name])
                && (hash_equals($entry['sha256'], $sourceReplacements[$name]['beforeSha256'])
                    || (isset($sourceReplacements[$name]['preparedBeforeSha256'])
                        && hash_equals($entry['sha256'], $sourceReplacements[$name]['preparedBeforeSha256'])))
                && is_string($digest)
                && hash_equals($sourceReplacements[$name]['afterSha256'], $digest);
            if (!is_string($digest) || is_executable($path) !== $entry['executable']
                || (!$reviewedSource && (!hash_equals($entry['sha256'], $digest) || filesize($path) !== $entry['size']))) {
                throw new UnavailableException("installed minimal component file differs: {$name}");
            }
        }
    }

    /** @return array<string,array{beforeSha256:string,afterSha256:string}> */
    private function candidateSourceReplacements(string $generation): array
    {
        $manifestFile = dirname(__DIR__, 2) . '/toolchain/patches/typephp/0.9.2/manifest.json';
        (new TypePhpPatchManifestFingerprint())->digest($manifestFile);
        $manifest = json_decode(file_get_contents($manifestFile), true, flags: JSON_THROW_ON_ERROR);
        $preparedFile = $generation . '/prepared/prepared-toolchain.json';
        $prepared = is_file($preparedFile) && !is_link($preparedFile)
            ? json_decode(file_get_contents($preparedFile), true, flags: JSON_THROW_ON_ERROR)
            : null;
        $typephp = is_array($prepared) ? ($prepared['typephp'] ?? null) : null;
        if (!is_string($typephp) || preg_match('~^[A-Za-z0-9._/-]+$~D', $typephp) !== 1
            || in_array('..', explode('/', $typephp), true)
            || in_array('.', explode('/', $typephp), true)
            || in_array('', explode('/', $typephp), true)
        ) {
            throw new ConfigurationException('minimal component selected TypePHP source is unsafe');
        }
        $replacements = [];
        foreach ($manifest['rules'] as $rule) {
            $replacement = [
                'beforeSha256' => $rule['beforeSha256'],
                'afterSha256' => $rule['afterSha256'],
            ];
            if (isset($rule['preparedBeforeSha256'])) { $replacement['preparedBeforeSha256'] = $rule['preparedBeforeSha256']; }
            $replacements['prepared/' . $typephp . '/' . $rule['path']] = $replacement;
        }
        return $replacements;
    }

    /**
     * @param array<string, mixed> $entries
     */
    private function assertEntries(array $entries): void
    {
        if ($entries === []) {
            throw new ConfigurationException('minimal component has no files');
        }
        foreach ($entries as $name => $entry) {
            if (!is_string($name) || !$this->safeName($name)
                || $name === 'minimal-component.json' || !is_array($entry)
            ) {
                throw new ConfigurationException('minimal component path is unsafe');
            }
            if (($entry['type'] ?? null) === 'directory') {
                continue;
            }
            if (($entry['type'] ?? null) === 'file') {
                if (!is_string($entry['sha256'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $entry['sha256']) !== 1
                    || !is_int($entry['size'] ?? null) || $entry['size'] < 0
                    || !is_bool($entry['executable'] ?? null)
                ) {
                    throw new ConfigurationException("minimal component file metadata is invalid: {$name}");
                }
                continue;
            }
            if (($entry['type'] ?? null) !== 'link'
                || !is_string($entry['target'] ?? null)
                || !$this->safeLinkTarget($name, $entry['target'], $entries)
            ) {
                throw new ConfigurationException("minimal component link is unsafe: {$name}");
            }
        }
    }

    private function safeName(string $name): bool
    {
        return $name !== '' && !str_starts_with($name, '/')
            && !str_contains($name, '\\') && !str_contains($name, "\0")
            && preg_match('/^[A-Za-z]:/', $name) !== 1
            && !in_array('', explode('/', $name), true)
            && !in_array('.', explode('/', $name), true)
            && !in_array('..', explode('/', $name), true);
    }

    /**
     * @param array<string, mixed> $entries
     */
    private function safeLinkTarget(string $name, string $target, array $entries): bool
    {
        if ($target === '' || str_starts_with($target, '/')
            || str_contains($target, '\\') || str_contains($target, "\0")
        ) {
            return false;
        }
        $resolved = [];
        foreach (explode('/', dirname($name) . '/' . $target) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                if ($resolved === []) {
                    return false;
                }
                array_pop($resolved);
            } else {
                $resolved[] = $segment;
            }
        }
        return isset($entries[implode('/', $resolved)]);
    }
}
