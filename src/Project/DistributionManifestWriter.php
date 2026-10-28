<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class DistributionManifestWriter
{
    /**
     * @param array{
     *   sourceTreeSha256:string,
     *   composerLockSha256:string,
     *   compatibilityLockSha256:string,
     *   toolchainLockSha256:string,
     *   normalizedInputSha256:string,
     *   extensions:list<string>,
     *   arguments:list<string>
     * } $inputs
     * @param list<array{path:string,reason:string}> $interpreted Sources the
     *        native compiler could not translate. They ship as their original
     *        PHP text and run on the embedded Zend engine.
     * @return array<string,mixed>
     */
    public function write(
        string $candidateDirectory,
        CoverageLedger $coverage,
        RuntimeResourceManifest $resources,
        array $inputs,
        string $host,
        array $interpreted = []
    ): array {
        $candidate = realpath($candidateDirectory);
        if (!is_string($candidate)
            || $candidate === DIRECTORY_SEPARATOR
            || !is_dir($candidate)
            || is_link($candidateDirectory)
            || !in_array($host, ['macos-arm64', 'windows-x86_64'], true)
        ) {
            throw new ConfigurationException('distribution manifest requires a safe candidate and build host');
        }
        $this->assertInputs($inputs);
        foreach (['manifest.json', 'coverage.json', 'resources.json'] as $name) {
            if (file_exists($candidate . '/' . $name)
                || is_link($candidate . '/' . $name)
            ) {
                throw new ConfigurationException(
                    "distribution manifest cannot overwrite candidate file: {$name}"
                );
            }
        }
        foreach ([
            'coverage.json' => $coverage->toArray(),
            'resources.json' => $resources->toArray(),
        ] as $name => $payload) {
            $encoded = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if (file_put_contents($candidate . '/' . $name, $encoded . "\n") === false) {
                throw new ConfigurationException(
                    "distribution metadata cannot be written: {$name}"
                );
            }
        }

        $mutable = [];
        $writableDirectories = [];
        foreach ($resources->entries() as $entry) {
            if ($entry['kind'] === 'file') {
                $mutable[$entry['path']] = $entry['mutable'];
            } elseif ($entry['kind'] === 'writable-directory') {
                $writableDirectories[] = $entry['path'];
            }
        }
        sort($writableDirectories, SORT_STRING);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($candidate, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr(
                $file->getPathname(),
                strlen($candidate) + 1
            ));
            if ($file->isLink()) {
                throw new ConfigurationException(
                    "distribution manifest refuses symlink: {$relative}"
                );
            }
            if (!$file->isFile()) {
                continue;
            }
            $digest = hash_file('sha256', $file->getPathname());
            if (!is_string($digest)) {
                throw new ConfigurationException(
                    "distribution file cannot be hashed: {$relative}"
                );
            }
            $files[$relative] = [
                'sha256' => $digest,
                'mutable' => $mutable[$relative] ?? false,
                'mode' => in_array($relative, ['server', 'start.sh', 'stop.sh'], true)
                    ? '0755'
                    : '0644',
            ];
        }
        ksort($files, SORT_STRING);
        foreach (['server', 'start.sh', 'stop.sh', 'coverage.json', 'resources.json'] as $required) {
            if (!isset($files[$required])) {
                throw new ConfigurationException(
                    "distribution candidate is incomplete: {$required}"
                );
            }
        }
        $canonicalInputs = $inputs;
        sort($canonicalInputs['extensions'], SORT_STRING);
        $canonicalInputs = $this->sortRecursively($canonicalInputs);
        $inputJson = json_encode(
            $canonicalInputs,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $manifest = [
            'schema' => 'webman-aot-builder-distribution-v1',
            'target' => [
                'os' => 'linux',
                'architecture' => 'x86_64',
                'libc' => 'musl',
            ],
            'inputs' => $canonicalInputs,
            'inputSha256' => hash('sha256', $inputJson),
            'files' => $files,
            'writableDirectories' => $writableDirectories,
            'diagnosticHost' => $host,
            'interpretedSources' => $this->interpretedSources($interpreted),
        ];
        $encoded = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (file_put_contents($candidate . '/manifest.json', $encoded . "\n") === false) {
            throw new ConfigurationException('distribution manifest cannot be written');
        }
        return $manifest;
    }

    /**
     * Record the interpreted tier in a stable, auditable order.
     *
     * @param list<array{path:string,reason:string}> $interpreted
     * @return list<array{path:string,reason:string}>
     */
    private function interpretedSources(array $interpreted): array
    {
        $entries = [];
        foreach ($interpreted as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
                throw new ConfigurationException('interpreted source record is invalid');
            }
            $path = $entry['path'];
            if ($path === ''
                || str_contains($path, "\n")
                || str_starts_with($path, '/')
                || $path !== str_replace('\\', '/', $path)
                || in_array('..', explode('/', $path), true)
            ) {
                throw new ConfigurationException("interpreted source path is unsafe: {$path}");
            }
            $reason = $entry['reason'] ?? 'compilation failed';
            $entries[$path] = [
                'path' => $path,
                'reason' => is_string($reason) && $reason !== ''
                    ? str_replace("\n", ' ', $reason)
                    : 'compilation failed',
            ];
        }
        ksort($entries, SORT_STRING);

        return array_values($entries);
    }

    /**
     * @param array<string,mixed> $inputs
     */
    private function assertInputs(array $inputs): void
    {
        foreach ([
            'sourceTreeSha256',
            'composerLockSha256',
            'compatibilityLockSha256',
            'toolchainLockSha256',
            'normalizedInputSha256',
        ] as $key) {
            if (!is_string($inputs[$key] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $inputs[$key]) !== 1
            ) {
                throw new ConfigurationException("distribution input digest is invalid: {$key}");
            }
        }
        if (!is_array($inputs['extensions'] ?? null)
            || !array_is_list($inputs['extensions'])
            || !is_array($inputs['arguments'] ?? null)
            || !array_is_list($inputs['arguments'])
        ) {
            throw new ConfigurationException('distribution input lists are invalid');
        }
        foreach (array_merge($inputs['extensions'], $inputs['arguments']) as $value) {
            if (!is_string($value) || $value === '' || str_contains($value, "\n")) {
                throw new ConfigurationException('distribution input contains an unsafe value');
            }
        }
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function sortRecursively(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->sortRecursively($item);
            }
        }
        return $value;
    }
}
