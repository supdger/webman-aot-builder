<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectDiscovery;
use WebmanAotBuilder\Project\ProjectProfile;

final class PluginSourceCompletion
{
    /**
     * Complete discovered project sources and production namespaces used by verified replacements.
     * Existing source and ignore decisions remain untouched.
     *
     * @param array<string,string> $dynamicPhp
     * @param list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}> $generatedMappings
     */
    public function apply(string $mirrorDirectory, string $profile, array $dynamicPhp, array $generatedMappings = []): ?string
    {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror) || is_link($mirrorDirectory)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
            || !in_array($profile, [ProjectProfile::WEBMAN, ProjectProfile::SAIADMIN], true)
        ) {
            throw new ConfigurationException('plugin source completion requires an isolated project mirror');
        }
        $projectFile = $mirror . '/project.linux.yml';
        $project = is_file($projectFile) && !is_link($projectFile)
            ? file_get_contents($projectFile)
            : false;
        if (!is_string($project) || str_contains($project, "\r")
            || substr_count($project, "\nsources:\n") !== 1
            || substr_count($project, "\nignore:\n") !== 1
            || substr_count($project, "\noutput:") !== 1
        ) {
            throw new ConfigurationException('generated plugin source list structure drifted');
        }
        $sources = $this->section($project, 'sources', 'ignore');
        $ignore = $this->section($project, 'ignore', 'output');
        $discovery = (new ProjectDiscovery($mirror, $dynamicPhp))
            ->discover(new ProjectProfile($profile, [], []));
        $missing = [];
        foreach ($discovery->files() as $file) {
            $path = $file['path'];
            $plugin = str_starts_with($file['owner'], 'plugin:');
            $support = $file['owner'] === 'project' && str_starts_with($path, 'support/')
                && $path !== 'support/bootstrap.php';
            if ($file['category'] !== ProjectDiscovery::BUSINESS_PHP
                || (!$plugin && !$support)
                || $this->covered($path, $sources)
            ) {
                continue;
            }
            if (preg_match('~^(?:plugin/[A-Za-z0-9_-]+|support)/[A-Za-z0-9_./-]+\.php$~D', $path) !== 1
                || $this->covered($path, $ignore)
                || !is_file($mirror . '/' . $path)
                || is_link($mirror . '/' . $path)
            ) {
                throw new ConfigurationException(
                    "discovered project PHP cannot be added to compiler sources: {$path}"
                );
            }
            $missing[] = $path;
        }
        $missing = array_values(array_unique(array_merge($missing, $this->replacementSources($mirror, $generatedMappings, $sources, $ignore))));
        if ($missing === []) {
            return null;
        }
        sort($missing, SORT_STRING);
        $lines = '';
        foreach ($missing as $path) {
            $lines .= "  - {$path}\n";
        }
        $adapted = str_replace("\nignore:\n", "\n{$lines}ignore:\n", $project);
        if ($adapted === $project
            || file_put_contents($projectFile, $adapted, LOCK_EX) !== strlen($adapted)
        ) {
            throw new ConfigurationException('unable to complete project compiler sources');
        }
        return hash(
            'sha256',
            json_encode($missing, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
    }

    public function includes(string $mirrorDirectory, string $path): bool
    {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror) || is_link($mirrorDirectory)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
        ) { throw new ConfigurationException('compiler source selection requires an isolated project mirror'); }
        $projectPath = $mirror . '/project.linux.yml';
        $project = is_file($projectPath) && !is_link($projectPath) ? file_get_contents($projectPath) : false;
        if (!is_string($project)) { throw new ConfigurationException('compiler source selection is missing'); }
        return is_file($mirror . '/' . $path) && !is_link($mirror . '/' . $path)
            && $this->covered($path, $this->section($project, 'sources', 'ignore'))
            && !$this->covered($path, $this->section($project, 'ignore', 'output'));
    }

    /** @param list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}> $mappings */
    private function replacementSources(string $mirror, array $mappings, array $sources, array $ignore): array
    {
        if ($mappings === []) { return []; }
        $metadataPath = $mirror . '/vendor/composer/installed.json';
        $metadata = is_file($metadataPath) && !is_link($metadataPath)
            ? json_decode((string) file_get_contents($metadataPath), true)
            : null;
        $records = is_array($metadata) ? ($metadata['packages'] ?? $metadata) : null;
        if (!is_array($records) || !array_is_list($records)) {
            throw new ConfigurationException('replacement dependency installed package metadata is missing or invalid');
        }
        $devNames = $metadata['dev-package-names'] ?? [];
        if (!is_array($devNames) || !array_is_list($devNames)) {
            throw new ConfigurationException('replacement dependency development package metadata is invalid');
        }
        foreach ($devNames as $name) {
            if (!is_string($name)) { throw new ConfigurationException('replacement dependency development package identity is invalid'); }
        }
        $dev = array_fill_keys($devNames, true);
        $packages = [];
        foreach ($records as $record) {
            if (!is_array($record) || !is_array($record['require'] ?? []) || !is_array($record['autoload'] ?? [])) {
                throw new ConfigurationException('replacement dependency package declarations are invalid');
            }
            $name = $record['name'] ?? null;
            if (!is_string($name) || isset($packages[$name])
                || !is_string($record['install-path'] ?? null)
            ) { throw new ConfigurationException('replacement dependency installed package identity is invalid'); }
            if (isset($dev[$name])) { continue; }
            $record['root'] = $this->sourceDirectory($mirror, $mirror . '/vendor/composer/' . $record['install-path']);
            $packages[$name] = $record;
        }
        $queue = [];
        foreach ($mappings as $mapping) {
            $origin = $mapping['path'] ?? null;
            if (!is_string($origin) || !str_starts_with($origin, 'vendor/')) { continue; }
            if (!is_string($mapping['shadow'] ?? null)) {
                throw new ConfigurationException('replacement dependency mapping target is invalid');
            }
            if (!$this->covered($mapping['shadow'], $sources)
                || $this->covered($mapping['shadow'], $ignore)
            ) { continue; }
            if (hash_file('sha256', $mirror . '/' . $origin) !== ($mapping['sourceSha256'] ?? null)
                || hash_file('sha256', $mirror . '/' . $mapping['shadow']) !== ($mapping['shadowSha256'] ?? null)
            ) { throw new ConfigurationException('replacement dependency mapping evidence drifted: ' . $origin); }
            $owner = null;
            foreach ($packages as $name => $package) {
                $root = $package['root'];
                if (str_starts_with($mirror . '/' . $origin, $root . '/')
                    && ($owner === null || strlen($root) > strlen($packages[$owner]['root']))
                ) { $owner = $name; }
            }
            if ($owner === null) {
                throw new ConfigurationException('replacement dependency origin has no installed production package: ' . $origin);
            }
            $queue[] = $owner;
        }
        $selected = [];
        $visited = [];
        while ($queue !== []) {
            $name = array_pop($queue);
            if (isset($visited[$name])) { continue; }
            $visited[$name] = true;
            $package = $packages[$name];
            foreach (array_keys($package['require'] ?? []) as $dependency) {
                if (isset($packages[$dependency])) { $queue[] = $dependency; }
            }
            $autoload = $package['autoload']['psr-4'] ?? [];
            if (!is_array($autoload)) { throw new ConfigurationException('replacement dependency PSR-4 declaration is invalid: ' . $name); }
            foreach ($autoload as $prefix => $paths) {
                if (!is_string($prefix)) { throw new ConfigurationException('replacement dependency namespace is invalid: ' . $name); }
                foreach (is_array($paths) ? $paths : [$paths] as $path) {
                    if (!is_string($path)) { throw new ConfigurationException('replacement dependency source path is invalid: ' . $name); }
                    $absolute = $this->sourceDirectory($package['root'], $package['root'] . '/' . $path);
                    $relative = substr($absolute, strlen($mirror) + 1);
                    if (!$this->covered($relative, $sources) && !$this->covered($relative, $ignore)) {
                        $selected[$relative] = true;
                    }
                }
            }
        }
        return array_keys($selected);
    }

    private function sourceDirectory(string $scope, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $resolved = realpath($path);
        if (!is_string($resolved) || !is_dir($resolved)
            || ($resolved !== $scope && !str_starts_with($resolved, $scope . '/'))
            || !str_starts_with($path, $scope . '/')
        ) { throw new ConfigurationException('replacement dependency source directory escapes its declared scope: ' . $path); }
        $cursor = $scope;
        foreach (explode('/', substr($path, strlen($scope) + 1)) as $part) {
            if ($part === '' || $part === '.') { continue; }
            $cursor = $part === '..' ? dirname($cursor) : $cursor . '/' . $part;
            if (($cursor !== $scope && !str_starts_with($cursor, $scope . '/')) || is_link($cursor)) {
                throw new ConfigurationException('replacement dependency source directory is unsafe: ' . $path);
            }
        }
        return $resolved;
    }

    /** @return list<string> */
    private function section(string $project, string $name, string $next): array
    {
        $startMarker = "\n{$name}:\n";
        $endMarker = "\n{$next}:";
        $start = strpos($project, $startMarker);
        $end = strpos($project, $endMarker);
        if ($start === false || $end === false || $start >= $end) {
            throw new ConfigurationException("generated {$name} section order drifted");
        }
        $block = substr($project, $start + strlen($startMarker), $end - $start - strlen($startMarker));
        $entries = [];
        foreach (explode("\n", $block) as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('~^  - ([A-Za-z0-9_./-]+)$~D', $line, $matches) !== 1
                || str_contains('/' . $matches[1] . '/', '/../')
                || str_contains('/' . $matches[1] . '/', '/./')
            ) {
                throw new ConfigurationException("generated {$name} entry drifted");
            }
            $entries[] = rtrim($matches[1], '/');
        }
        if ($entries === [] || count($entries) !== count(array_unique($entries))) {
            throw new ConfigurationException("generated {$name} entries are empty or duplicated");
        }
        return $entries;
    }

    /** @param list<string> $entries */
    private function covered(string $path, array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($path === $entry || str_starts_with($path, $entry . '/')) {
                return true;
            }
        }
        return false;
    }
}
