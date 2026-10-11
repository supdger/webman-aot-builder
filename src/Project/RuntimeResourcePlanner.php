<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class RuntimeResourcePlanner
{
    private const DECLARED_FILE_ROLES = ['certificate', 'timezone', 'font'];

    public function __construct(private readonly string $projectDirectory)
    {
    }

    /**
     * @param list<array{path:string,role:string}> $requiredFiles
     * @param list<array{path:string,role:string}> $writableDirectories
     */
    public function plan(
        DiscoveryResult $discovery,
        CoverageLedger $coverage,
        array $requiredFiles = [],
        array $writableDirectories = []
    ): RuntimeResourceManifest {
        $coverageByPath = [];
        foreach ($coverage->files() as $file) {
            $coverageByPath[(string) $file['path']] = $file;
        }

        $entries = [];
        foreach ($discovery->files() as $file) {
            $path = $file['path'];
            $record = $coverageByPath[$path] ?? null;
            if ($record === null) {
                throw new ConfigurationException("runtime resource lacks coverage: {$path}");
            }
            if ($record['status'] !== CoverageLedger::RUNTIME_APPROVED) {
                continue;
            }
            $role = match ($file['category']) {
                ProjectDiscovery::CONFIG => 'configuration',
                ProjectDiscovery::TEMPLATE => 'template',
                ProjectDiscovery::STATIC_ASSET => $this->staticRole($path),
                ProjectDiscovery::THIRD_PARTY_DYNAMIC_PHP => 'third-party-dynamic-php',
                default => throw new ConfigurationException(
                    "runtime-approved file has unsupported category: {$path}"
                ),
            };
            $digest = $this->digestFile($path);
            if ($digest !== $record['sourceSha256']) {
                throw new ConfigurationException("runtime resource changed after coverage: {$path}");
            }
            $entries[$path] = [
                'path' => $path,
                'role' => $role,
                'kind' => 'file',
                'sourceSha256' => $digest,
                'mutable' => $role === 'configuration' || $role === 'template',
            ];
        }

        foreach ($requiredFiles as $declaration) {
            $path = $this->validatePath($declaration['path'] ?? '');
            $role = $declaration['role'] ?? '';
            if (!in_array($role, self::DECLARED_FILE_ROLES, true)) {
                throw new ConfigurationException("invalid declared runtime file role: {$role}");
            }
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
                throw new ConfigurationException(
                    "PHP cannot be declared as a runtime data resource: {$path}"
                );
            }
            $digest = $this->digestFile($path);
            if (isset($entries[$path])) {
                if ($entries[$path]['sourceSha256'] !== $digest) {
                    throw new ConfigurationException("runtime resource digest changed: {$path}");
                }
                $entries[$path]['role'] = $role;
                continue;
            }
            $entries[$path] = [
                'path' => $path,
                'role' => $role,
                'kind' => 'file',
                'sourceSha256' => $digest,
                'mutable' => false,
            ];
        }

        // The deployed .env is supplied by the operator; its contents are never
        // copied from the source project or recorded in this manifest.
        $entries['.env'] = [
            'path' => '.env',
            'role' => 'environment',
            'kind' => 'external-file',
            'sourceSha256' => null,
            'mutable' => true,
        ];

        foreach (array_merge(
            RuntimeDataPaths::writableDirectories($this->projectDirectory),
            $writableDirectories
        ) as $declaration) {
            $path = $this->validatePath($declaration['path'] ?? '');
            $role = $declaration['role'] ?? '';
            if (!in_array($role, ['uploads', 'logs'], true)) {
                throw new ConfigurationException("invalid writable runtime directory role: {$role}");
            }
            $absolute = $this->absolute($path);
            if (!is_dir($absolute) || isset($entries[$path])) {
                continue;
            }
            $entries[$path] = [
                'path' => $path,
                'role' => $role,
                'kind' => 'writable-directory',
                'sourceSha256' => null,
                'mutable' => true,
            ];
        }

        ksort($entries, SORT_STRING);

        return new RuntimeResourceManifest(array_values($entries));
    }

    private function staticRole(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($extension) {
            'crt', 'cer', 'pem' => 'certificate',
            'ttf', 'otf', 'woff', 'woff2' => 'font',
            default => str_contains(strtolower('/' . $path . '/'), '/zoneinfo/')
                ? 'timezone'
                : 'static',
        };
    }

    private function digestFile(string $path): string
    {
        $path = $this->validatePath($path);
        $absolute = $this->absolute($path);
        if (!is_file($absolute)) {
            throw new ConfigurationException("required runtime resource is missing or unsafe: {$path}");
        }
        $digest = hash_file('sha256', $absolute);
        if (!is_string($digest)) {
            throw new ConfigurationException("unable to hash runtime resource: {$path}");
        }

        return $digest;
    }

    private function validatePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === '' || $normalized !== trim($normalized, '/')
            || preg_match('/^[A-Za-z]:/', $normalized) === 1
            || in_array('..', explode('/', $normalized), true)
            || in_array('.', explode('/', $normalized), true)
            || in_array('', explode('/', $normalized), true)
        ) {
            throw new ConfigurationException("unsafe runtime resource path: {$path}");
        }

        return $normalized;
    }

    private function absolute(string $path): string
    {
        return rtrim($this->projectDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

}
