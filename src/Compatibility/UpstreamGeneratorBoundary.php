<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class UpstreamGeneratorBoundary
{
    /**
     * Project package versions and historical source hashes are compatibility examples.
     * The generator artifact and this generation's actual inputs remain integrity checked.
     *
     * @param array<string,array{version:string,reference:string}> $packages
     * @param array<string,array{shadow:string,sourceSha256:string,shadowSha256:string}> $expected
     * @return list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}>
     */
    public function run(
        string $mirrorDirectory,
        string $generatorFile,
        string $generatorSha256,
        array $packages,
        array $expected,
        \Closure $generate,
        bool $saiAdminGenerated = false
    ): array {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror) || is_link($mirrorDirectory)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
        ) {
            throw new ConfigurationException('upstream generator requires an isolated build mirror');
        }
        $this->assertDigest($generatorSha256);
        if (!is_file($generatorFile) || is_link($generatorFile)
            || hash_file('sha256', $generatorFile) !== $generatorSha256
        ) {
            throw new ConfigurationException('upstream generator source digest mismatch');
        }
        $generatedRoot = $mirror . '/.typephp/build';
        if (file_exists($generatedRoot) || is_link($generatedRoot) || is_link($mirror . '/.typephp')) {
            throw new ConfigurationException('upstream generator output is not a clean mirror');
        }
        $beforeSources = $this->sourceDigests($mirror);
        $result = $generate();
        if (!is_dir($generatedRoot) || is_link($generatedRoot) || is_link($mirror . '/.typephp')) {
            throw new ConfigurationException('upstream generator did not create a safe output directory');
        }
        if (hash_file('sha256', $generatorFile) !== $generatorSha256) {
            throw new ConfigurationException('upstream generator changed during execution');
        }
        $afterSources = $this->sourceDigests($mirror);
        if ($afterSources !== $beforeSources) {
            throw new ConfigurationException('upstream generator changed original PHP source inputs');
        }
        $manifest = [];
        if (is_array($result)) {
            $manifest = $result;
        } else {
            foreach ($expected as $source => $entry) {
                if (is_file($mirror . '/' . $entry['shadow'])) {
                    $manifest[] = [
                        'path' => $source,
                        'shadow' => $entry['shadow'],
                        'sourceSha256' => $this->digest($mirror, $source),
                        'shadowSha256' => $this->digest($mirror, $entry['shadow']),
                    ];
                }
            }
        }
        $inputs = $this->compilerInputs($mirror);
        $targets = [];
        $sources = [];
        foreach ($manifest as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)
                || !is_string($entry['shadow'] ?? null)
            ) {
                throw new ConfigurationException('upstream generator mapping is invalid');
            }
            $source = $entry['path'];
            $shadow = $entry['shadow'];
            $this->assertPath($source);
            $this->assertPath($shadow);
            if (!str_starts_with($shadow, '.typephp/build/') || isset($sources[$source])
                || (isset($targets[$shadow]) && $targets[$shadow] !== ($entry['shadowSha256'] ?? null))
            ) {
                throw new ConfigurationException("invalid upstream generator mapping: {$source}");
            }
            $this->assertDigest($entry['sourceSha256'] ?? '');
            $this->assertDigest($entry['shadowSha256'] ?? '');
            if ($this->digest($mirror, $source) !== $entry['sourceSha256']) {
                throw new ConfigurationException("upstream generator changed source: {$source}");
            }
            if ($this->digest($mirror, $shadow) !== $entry['shadowSha256']) {
                throw new ConfigurationException("upstream generator shadow drift: {$shadow}");
            }
            if (isset($entry['contractSha256'])
                && (!is_string($entry['contractSha256']) || !hash_equals($entry['contractSha256'], $entry['shadowSha256']))
            ) {
                throw new ConfigurationException('upstream generator shadow violates its source conversion contract: ' . $shadow);
            }
            if (!isset($inputs['sources'][$shadow]) || !isset($inputs['ignore'][$source])) {
                throw new ConfigurationException("upstream generator compiler coverage is open: {$source} => {$shadow}");
            }
            try {
                token_get_all((string) file_get_contents($mirror . '/' . $shadow), TOKEN_PARSE);
            } catch (\ParseError $exception) {
                throw new ConfigurationException("upstream generator emitted invalid PHP: {$shadow}", previous: $exception);
            }
            $targets[$shadow] = $entry['shadowSha256'];
            $sources[$source] = true;
        }
        $actual = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($generatedRoot, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) {
                throw new ConfigurationException('upstream generator output contains a symlink');
            }
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($mirror) + 1));
                $actual[$relative] = true;
            }
        }
        if (array_diff_key($actual, $targets) !== [] || array_diff_key($targets, $actual) !== []) {
            throw new ConfigurationException('upstream generator PHP output does not match actual source mappings');
        }
        return array_map(static fn(array $entry): array => [
            'path' => $entry['path'],
            'shadow' => $entry['shadow'],
            'sourceSha256' => $entry['sourceSha256'],
            'shadowSha256' => $entry['shadowSha256'],
        ], $manifest);
    }

    /** @return array{sources:array<string,true>,ignore:array<string,true>} */
    private function compilerInputs(string $mirror): array
    {
        $path = $mirror . '/project.linux.yml';
        $project = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($project)) {
            throw new ConfigurationException('upstream generator compiler manifest is missing');
        }
        $inputs = ['sources' => [], 'ignore' => []];
        $section = null;
        foreach (explode("\n", $project) as $line) {
            if ($line === 'sources:' || $line === 'ignore:') {
                $section = substr($line, 0, -1);
            } elseif ($line !== '' && $line[0] !== ' ') {
                $section = null;
            } elseif ($section !== null && str_starts_with($line, '  - ')) {
                $item = substr($line, 4);
                if (str_starts_with($item, '"')) {
                    $item = json_decode($item, true, flags: JSON_THROW_ON_ERROR);
                }
                if (!is_string($item) || $item === '' || isset($inputs[$section][$item])) {
                    throw new ConfigurationException("invalid upstream generator {$section} entry");
                }
                $inputs[$section][$item] = true;
            }
        }
        return $inputs;
    }

    /** @return array<string,string> */
    private function sourceDigests(string $mirror): array
    {
        $digests = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($mirror, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) {
                throw new ConfigurationException('upstream generator source contains a symlink');
            }
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($mirror) + 1));
            if (!str_starts_with($relative, '.typephp/build/')) {
                $digests[$relative] = $this->digest($mirror, $relative);
            }
        }
        ksort($digests, SORT_STRING);
        return $digests;
    }

    private function assertPath(string $path): void
    {
        if (!str_ends_with(strtolower($path), '.php') || str_starts_with($path, '/')
            || str_contains($path, '\\') || str_contains($path, '//')
            || in_array('..', explode('/', $path), true)
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
        ) {
            throw new ConfigurationException("unsafe upstream generator PHP path: {$path}");
        }
    }

    private function assertDigest(string $digest): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new ConfigurationException('invalid upstream generator digest');
        }
    }

    private function digest(string $mirror, string $relative): string
    {
        $path = $mirror . '/' . $relative;
        $canonical = realpath($path);
        if (!is_file($path) || is_link($path) || !is_string($canonical)
            || str_replace('\\', '/', $canonical) !== str_replace('\\', '/', $path)
        ) {
            throw new ConfigurationException("upstream generator file missing or unsafe: {$relative}");
        }
        return (string) hash_file('sha256', $path);
    }
}
