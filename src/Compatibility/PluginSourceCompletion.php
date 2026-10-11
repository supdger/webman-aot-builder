<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectDiscovery;
use WebmanAotBuilder\Project\ProjectProfile;
use WebmanAotBuilder\Project\WebmanSupportOverride;

final class PluginSourceCompletion
{
    private array $sourcePlans = [];
    /**
     * Complete compiler coverage from actual project PHP, including dependencies.
     *
     * @param array<string,string> $dynamicPhp
     * @param list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}> $mappings
     */
    public function apply(
        string $mirrorDirectory,
        string $profile,
        array $dynamicPhp,
        array $mappings = [],
        ?array $entrypoint = null
    ): ?string {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror) || is_link($mirrorDirectory)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
            || !in_array($profile, [ProjectProfile::PHP, ProjectProfile::WEBMAN, ProjectProfile::SAIADMIN], true)
        ) {
            throw new ConfigurationException('PHP source completion requires an isolated project mirror');
        }
        $projectFile = $mirror . '/project.linux.yml';
        $project = is_file($projectFile) && !is_link($projectFile)
            ? file_get_contents($projectFile) : false;
        if (!is_string($project)) {
            throw new ConfigurationException('TypePHP project manifest is missing');
        }
        $project = str_replace("\r\n", "\n", $project);
        if (!str_ends_with($project, "\n")) {
            $project .= "\n";
        }
        $sources = $this->section($project, 'sources');
        $ignore = $this->section($project, 'ignore');
        $replacements = [];
        foreach (['Request', 'Response'] as $name) {
            $path = "vendor/workerman/webman-framework/src/support/{$name}.php";
            $replacement = "support/{$name}.php";
            if (WebmanSupportOverride::canReplace($mirror, $name)) {
                $replacements[$path] = $replacement;
                $ignore[] = $path;
            }
        }
        foreach ($mappings as $mapping) {
            $replacements[$mapping['path']] = $mapping['shadow'];
            $sources[] = $mapping['shadow'];
            $ignore[] = $mapping['path'];
        }
        if ($entrypoint !== null) {
            $replacements[$entrypoint['source']] = $entrypoint['replacement'];
            $ignore[] = $entrypoint['source'];
            foreach ($entrypoint['sources'] ?? [] as $path => $_) {
                $replacements[$path] = $entrypoint['replacement'];
                $ignore[] = $path;
            }
        }
        $discovery = (new ProjectDiscovery($mirror, $dynamicPhp))
            ->discover(new ProjectProfile(ProjectProfile::PHP, [], []), false);
        foreach ($discovery->files() as $file) {
            $path = $file['path'];
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            if (isset($replacements[$path])) {
                continue;
            }
            if ($this->covered($path, $ignore)) {
                throw new ConfigurationException("PHP input is excluded from TypePHP compilation: {$path}");
            }
            if (!$this->covered($path, $sources)) {
                $sources[] = $path;
            }
        }
        $sources = array_merge($sources, $this->replacementSources($mirror, $mappings, $sources, $ignore));
        $sources = array_values(array_unique($sources));
        $ignore = array_values(array_unique($ignore));
        $adapted = $this->replaceSection($project, 'sources', $sources);
        $adapted = $this->replaceSection($adapted, 'ignore', $ignore);
        if ($adapted === $project) {
            return null;
        }
        if (file_put_contents($projectFile, $adapted, LOCK_EX) !== strlen($adapted)) {
            throw new ConfigurationException('unable to complete PHP compiler sources');
        }
        return hash('sha256', $adapted);
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
        $sources = $this->section($project, 'sources');
        $ignore = $this->section($project, 'ignore');
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
            return [];
        }
        $devNames = $metadata['dev-package-names'] ?? [];
        if (!is_array($devNames) || !array_is_list($devNames)) {
            return [];
        }
        foreach ($devNames as $name) {
            if (!is_string($name)) { return []; }
        }
        $dev = array_fill_keys($devNames, true);
        $packages = [];
        foreach ($records as $record) {
            if (!is_array($record) || !is_array($record['require'] ?? []) || !is_array($record['autoload'] ?? [])) {
                return [];
            }
            $name = $record['name'] ?? null;
            if (!is_string($name) || isset($packages[$name])
                || !is_string($record['install-path'] ?? null)
            ) { return []; }
            if (isset($dev[$name])) { continue; }
            $record['root'] = $this->hintDirectory($mirror, $mirror . '/vendor/composer/' . $record['install-path']);
            if ($record['root'] === null) { return []; }
            $packages[$name] = $record;
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
                continue;
            }
            $queue[] = $owner;
        }
        $identity = hash('sha256', $mirror . hash_file('sha256', $metadataPath));
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
                if (!is_array($autoload)) { return []; }
                foreach ($autoload as $prefix => $paths) {
                    if (!is_string($prefix)) { return []; }
                    foreach (is_array($paths) ? $paths : [$paths] as $path) {
                        if (!is_string($path)) { return []; }
                        $absolute = $this->hintDirectory($package['root'], $package['root'] . '/' . $path);
                        if ($absolute === null) { return []; }
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
            $exclusions = $package['autoload']['exclude-from-classmap'] ?? [];
            if (!is_array($exclusions) || !array_is_list($exclusions)) { return []; }
            foreach (['classmap', 'files'] as $kind) {
                $paths = $package['autoload'][$kind] ?? [];
                if (!is_array($paths) || !array_is_list($paths)) { return []; }
                foreach ($paths as $path) {
                    if (!is_string($path)) { return []; }
                    if ($kind === 'classmap' && strpbrk($path, '*?[') !== false) {
                        if (str_starts_with($path, '/') || str_contains('/' . $path . '/', '/../')) {
                            return [];
                        }
                        $pathsToScan = glob($package['root'] . '/' . $path, GLOB_NOSORT);
                        if ($pathsToScan === false) { return []; }
                    } else { $pathsToScan = [$package['root'] . '/' . $path]; }
                    foreach ($pathsToScan as $absolute) {
                    $absolute = rtrim($absolute, '/');
                    $parent = $this->hintDirectory($package['root'], dirname($absolute));
                    if ($parent === null) { return []; }
                    $absolute = $parent . '/' . basename($absolute);
                    if (is_link($absolute) || (!is_file($absolute) && !is_dir($absolute))) { return []; }
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
                            foreach ($exclusions as $pattern) {
                                if (!is_string($pattern)) { return []; }
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

    private function hintDirectory(string $scope, string $path): ?string
    {
        $scope = str_replace(DIRECTORY_SEPARATOR, '/', $scope);
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        $resolved = realpath($path);
        $resolved = is_string($resolved) ? str_replace(DIRECTORY_SEPARATOR, '/', $resolved) : $resolved;
        if (!is_string($resolved) || !is_dir($resolved)
            || ($resolved !== $scope && !str_starts_with($resolved, $scope . '/'))
            || ($path !== $scope && !str_starts_with($path, $scope . '/'))
        ) { return null; }
        $cursor = $scope;
        foreach (explode('/', substr($path, strlen($scope) + 1)) as $part) {
            if ($part === '' || $part === '.') { continue; }
            $cursor = $part === '..' ? str_replace(DIRECTORY_SEPARATOR, '/', dirname($cursor)) : $cursor . '/' . $part;
            if (($cursor !== $scope && !str_starts_with($cursor, $scope . '/')) || is_link($cursor)) {
                return null;
            }
        }
        return $resolved;
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
    private function section(string $project, string $name): array
    {
        $section = $this->sectionRange($project, $name);
        if ($section === null) {
            return [];
        }
        $inline = trim($section['inline']);
        $body = $section['body'];
        if ($inline !== '' && !str_starts_with($inline, '#')) {
            if (!str_starts_with($inline, '[') || !str_ends_with($inline, ']')) {
                throw new ConfigurationException("TypePHP project {$name} must be a YAML list");
            }
            $items = [];
            $current = '';
            $quote = null;
            $escaped = false;
            foreach (str_split(substr($inline, 1, -1)) as $character) {
                if ($quote !== null) {
                    $current .= $character;
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($character === '\\' && $quote === '"') {
                        $escaped = true;
                    } elseif ($character === $quote) {
                        $quote = null;
                    }
                } elseif ($character === '"' || $character === "'") {
                    $quote = $character;
                    $current .= $character;
                } elseif ($character === ',') {
                    $items[] = $current;
                    $current = '';
                } else {
                    $current .= $character;
                }
            }
            if ($quote !== null || $escaped) {
                throw new ConfigurationException("invalid TypePHP project {$name} inline list");
            }
            if (trim($current) !== '') {
                $items[] = $current;
            }
            $body = implode("\n", array_map(static fn(string $item): string => '  - ' . trim($item), $items));
        }
        $entries = [];
        foreach (explode("\n", $body) as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (preg_match('/^[ \t]+-[ \t]+(.+)$/D', $line, $item) !== 1) {
                throw new ConfigurationException("invalid TypePHP project {$name} entry");
            }
            $path = trim($item[1]);
            if (str_starts_with($path, '"')) {
                $path = json_decode($path, true, flags: JSON_THROW_ON_ERROR);
            } elseif (str_starts_with($path, "'") && str_ends_with($path, "'")) {
                $path = str_replace("''", "'", substr($path, 1, -1));
            }
            if (!is_string($path) || $path === '' || str_starts_with($path, '/')
                || in_array('..', explode('/', str_replace('\\', '/', $path)), true)
                || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
            ) {
                throw new ConfigurationException("unsafe TypePHP project {$name} path");
            }
            $entries[] = rtrim($path, '/');
        }
        return $entries;
    }

    /** @param list<string> $entries */
    private function replaceSection(string $project, string $name, array $entries): string
    {
        $block = "{$name}:\n";
        foreach ($entries as $path) {
            $encoded = preg_match('~^[A-Za-z0-9_./*-]+$~D', $path) === 1
                ? $path : json_encode($path, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $block .= "  - {$encoded}\n";
        }
        $block .= "\n";
        $section = $this->sectionRange($project, $name);
        if ($section === null) {
            return rtrim($project) . "\n\n" . $block;
        }
        return substr_replace($project, $block, $section['start'], $section['end'] - $section['start']);
    }

    /** @return array{start:int,end:int,inline:string,body:string}|null */
    private function sectionRange(string $project, string $name): ?array
    {
        $lines = explode("\n", $project);
        $offset = 0;
        $length = strlen($project);
        foreach ($lines as $index => $line) {
            $next = min($length, $offset + strlen($line) + 1);
            if (!str_starts_with($line, $name . ':')) {
                $offset = $next;
                continue;
            }
            $end = $next;
            for ($cursor = $index + 1; $cursor < count($lines); ++$cursor) {
                $continuation = $lines[$cursor];
                if ($continuation !== '' && $continuation[0] !== ' ' && $continuation[0] !== "\t") {
                    break;
                }
                $end = min($length, $end + strlen($continuation) + 1);
            }
            return [
                'start' => $offset,
                'end' => $end,
                'inline' => substr($line, strlen($name) + 1),
                'body' => substr($project, $next, $end - $next),
            ];
        }
        return null;
    }

    /** @param list<string> $entries */
    private function covered(string $path, array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($path === $entry || str_starts_with($path, $entry . '/')
                || (str_contains($entry, '*') && fnmatch($entry, $path))
            ) {
                return true;
            }
        }
        return false;
    }
}
