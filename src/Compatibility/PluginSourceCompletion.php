<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectDiscovery;
use WebmanAotBuilder\Project\ProjectProfile;

final class PluginSourceCompletion
{
    private array $sourcePlans = [];
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
        $mirror = is_string($mirror) ? str_replace(DIRECTORY_SEPARATOR, '/', $mirror) : $mirror;
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
        $mirror = is_string($mirror) ? str_replace(DIRECTORY_SEPARATOR, '/', $mirror) : $mirror;
        if (!is_string($mirror) || is_link($mirrorDirectory)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
        ) { throw new ConfigurationException('compiler source selection requires an isolated project mirror'); }
        $projectPath = $mirror . '/project.linux.yml';
        $project = is_file($projectPath) && !is_link($projectPath) ? file_get_contents($projectPath) : false;
        if (!is_string($project)) { throw new ConfigurationException('compiler source selection is missing'); }
        if (!is_file($mirror . '/' . $path) || is_link($mirror . '/' . $path)) { return false; }
        $sources = $this->section($project, 'sources', 'ignore');
        $ignore = $this->section($project, 'ignore', 'output');
        if ($this->covered($path, $ignore)) { return false; }
        return $this->covered($path, $sources)
            || in_array($path, $this->replacementSources($mirror, [], $sources, $ignore), true);
    }

    /** @param list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}> $mappings */
    private function replacementSources(string $mirror, array $mappings, array $sources, array $ignore): array
    {
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
        $lockFile = $mirror . '/composer.lock';
        $lock = is_file($lockFile) && !is_link($lockFile) ? json_decode((string) file_get_contents($lockFile), true) : null;
        if (!is_array($lock) || !is_array($lock['packages'] ?? null)) { throw new ConfigurationException('production dependency Composer lock is missing or invalid'); }
        $locked = [];
        foreach ($lock['packages'] as $record) {
            if (!is_array($record) || !is_string($record['name'] ?? null) || isset($locked[$record['name']])) { throw new ConfigurationException('production dependency Composer lock identities are invalid'); }
            $locked[$record['name']] = $record;
        }
        foreach ($packages as $name => $record) {
            $expected = $locked[$name] ?? null;
            if (!is_array($expected) || ($record['version'] ?? null) !== ($expected['version'] ?? null)
                || ($record['source']['reference'] ?? null) !== ($expected['source']['reference'] ?? null)
                || ($record['dist']['reference'] ?? null) !== ($expected['dist']['reference'] ?? null)
                || ($record['autoload'] ?? []) !== ($expected['autoload'] ?? [])
            ) { throw new ConfigurationException('production dependency installed identity differs from Composer lock: ' . $name); }
        }
        $queue = array_keys($packages);
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
        $identity = hash('sha256', $mirror . hash_file('sha256', $metadataPath) . hash_file('sha256', $lockFile));
        if (isset($this->sourcePlans[$identity])) { return $this->selectPlannedSources($mirror, $this->sourcePlans[$identity], $sources, $ignore); }
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
            foreach (['psr-4', 'psr-0'] as $kind) {
                $autoload = $package['autoload'][$kind] ?? [];
                if (!is_array($autoload)) { throw new ConfigurationException('production dependency namespace declaration is invalid: ' . $name); }
                foreach ($autoload as $prefix => $paths) {
                    if (!is_string($prefix)) { throw new ConfigurationException('production dependency namespace is invalid: ' . $name); }
                    foreach (is_array($paths) ? $paths : [$paths] as $path) {
                        if (!is_string($path)) { throw new ConfigurationException('production dependency source path is invalid: ' . $name); }
                        $absolute = $this->sourceDirectory($package['root'], $package['root'] . '/' . $path);
                        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
                        foreach ($iterator as $file) {
                            if ($file->isLink()) { throw new ConfigurationException('production dependency namespace contains a link'); }
                            if (!$file->isFile() || $file->getExtension() !== 'php' || !$this->hasClassDeclaration($file->getPathname())) { continue; }
                            $filePath = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
                            $autoloadPath = substr($filePath, strlen($absolute) + 1, -4);
                            $matches = false;
                            foreach ($this->classDeclarations($file->getPathname()) as $declaration) {
                                if ($kind === 'psr-4') {
                                    $matches = $declaration === $prefix . str_replace('/', '\\', $autoloadPath);
                                } else {
                                    $separator = strrpos($declaration, '\\');
                                    $classPath = $separator === false ? str_replace('_', '/', $declaration)
                                        : str_replace('\\', '/', substr($declaration, 0, $separator + 1)) . str_replace('_', '/', substr($declaration, $separator + 1));
                                    $matches = str_starts_with($declaration, $prefix) && $classPath === $autoloadPath;
                                }
                                if ($matches) { break; }
                            }
                            if (!$matches) { continue; }
                            $relative = substr($filePath, strlen($mirror) + 1);
                            $selected[$relative] = true;
                        }
                    }
                }
            }
            foreach (['classmap', 'files'] as $kind) {
                $paths = $package['autoload'][$kind] ?? [];
                if (!is_array($paths) || !array_is_list($paths)) { throw new ConfigurationException('production dependency autoload paths are invalid: ' . $name); }
                foreach ($paths as $path) {
                    if (!is_string($path)) { throw new ConfigurationException('production dependency source path is invalid: ' . $name); }
                    if ($kind === 'classmap' && strpbrk($path, '*?[') !== false) {
                        if (str_starts_with($path, '/') || str_contains('/' . $path . '/', '/../')) {
                            throw new ConfigurationException('production dependency classmap pattern escapes its declared scope');
                        }
                        $pathsToScan = glob($package['root'] . '/' . $path, GLOB_NOSORT);
                        if ($pathsToScan === false) { throw new ConfigurationException('production dependency classmap pattern is invalid'); }
                    } else { $pathsToScan = [$package['root'] . '/' . $path]; }
                    foreach ($pathsToScan as $absolute) {
                    $absolute = rtrim($absolute, '/');
                    $parent = $this->sourceDirectory($package['root'], dirname($absolute));
                    $absolute = $parent . '/' . basename($absolute);
                    if (is_link($absolute) || (!is_file($absolute) && !is_dir($absolute))) { throw new ConfigurationException('production dependency autoload path is missing or unsafe: ' . $name); }
                    $files = is_file($absolute) ? [$absolute] : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->sourceDirectory($package['root'], $absolute), \FilesystemIterator::SKIP_DOTS));
                    foreach ($files as $file) {
                        if ($file instanceof \SplFileInfo) {
                            if ($file->isLink()) { throw new ConfigurationException('production dependency classmap contains a link'); }
                            if (!$file->isFile()) { continue; }
                            $file = $file->getPathname();
                        }
                        $file = str_replace(DIRECTORY_SEPARATOR, '/', $file);
                        if (!str_ends_with($file, '.php') || ($kind === 'classmap' && !$this->hasClassDeclaration($file))) { continue; }
                        $relative = substr($file, strlen($mirror) + 1);
                        $excluded = false;
                        if ($kind === 'classmap') {
                            foreach ($package['autoload']['exclude-from-classmap'] ?? [] as $pattern) {
                                if (!is_string($pattern)) { throw new ConfigurationException('production dependency classmap exclusion is invalid'); }
                                $pattern = str_replace(['\*\*', '\*'], ['.*', '[^/]*'], preg_quote(trim($pattern, '/'), '#'));
                                if (preg_match('#^' . $pattern . '(?:/|$)#', substr($file, strlen($package['root']) + 1)) === 1) { $excluded = true; break; }
                            }
                        }
                        if (!$excluded) { $selected[$relative] = true; }
                    }
                    }
                }
            }

        }
        $plan = [];
        foreach (array_keys($selected) as $path) { $plan[$path] = hash_file('sha256', $mirror . '/' . $path); }
        $this->sourcePlans[$identity] = $plan;
        return $this->selectPlannedSources($mirror, $plan, $sources, $ignore);
    }

    private function selectPlannedSources(string $mirror, array $plan, array $sources, array $ignore): array
    {
        $selected = [];
        foreach ($plan as $path => $digest) {
            $this->sourceDirectory($mirror, dirname($mirror . '/' . $path));
            if (is_link($mirror . '/' . $path) || !is_file($mirror . '/' . $path)
                || !is_string($digest) || hash_file('sha256', $mirror . '/' . $path) !== $digest
            ) { throw new ConfigurationException('production dependency source plan evidence drifted: ' . $path); }
            if (!$this->covered($path, $sources) && !$this->covered($path, $ignore)) { $selected[] = $path; }
        }
        return $selected;
    }

    private function hasClassDeclaration(string $path): bool
    {
        return $this->classDeclarations($path) !== [];
    }

    /** @return list<string> */
    private function classDeclarations(string $path): array
    {
        $source = file_get_contents($path);
        if (!is_string($source)) { throw new ConfigurationException('production dependency source cannot be read'); }
        $tokens = token_get_all($source);
        $namespace = '';
        $declarations = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) { continue; }
            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for (++$index; isset($tokens[$index]); ++$index) {
                    $next = $tokens[$index];
                    if ($next === ';' || $next === '{') { break; }
                    if (is_array($next) && in_array($next[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED], true)) { $namespace .= $next[1]; }
                }
                continue;
            }
            if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) { continue; }
            for (++$index; isset($tokens[$index]); ++$index) {
                $next = $tokens[$index];
                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                if (is_array($next) && $next[0] === T_STRING) { $declarations[] = ($namespace === '' ? '' : $namespace . '\\') . $next[1]; }
                break;
            }
        }
        return array_values(array_unique($declarations));
    }

    private function sourceDirectory(string $scope, string $path): string
    {
        $scope = str_replace(DIRECTORY_SEPARATOR, '/', $scope);
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        $resolved = realpath($path);
        $resolved = is_string($resolved) ? str_replace(DIRECTORY_SEPARATOR, '/', $resolved) : $resolved;
        if (!is_string($resolved) || !is_dir($resolved)
            || ($resolved !== $scope && !str_starts_with($resolved, $scope . '/'))
            || ($path !== $scope && !str_starts_with($path, $scope . '/'))
        ) { throw new ConfigurationException('replacement dependency source directory escapes its declared scope: ' . $path); }
        $cursor = $scope;
        foreach (explode('/', substr($path, strlen($scope) + 1)) as $part) {
            if ($part === '' || $part === '.') { continue; }
            $cursor = $part === '..' ? str_replace(DIRECTORY_SEPARATOR, '/', dirname($cursor)) : $cursor . '/' . $part;
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
