<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectDiscovery;
use WebmanAotBuilder\Project\CompilationSourceResolver;

final class PluginSourceCompletion
{
    /**
     * Complete compiler coverage from actual project PHP, including dependencies.
     *
     * @param array<string,string> $dynamicPhp
     * @param list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}> $mappings
     */
    public function apply(
        string $mirrorDirectory,
        array $dynamicPhp,
        array $mappings = [],
        ?array $entrypoint = null
    ): ?string {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror) || is_link($mirrorDirectory)
            || !str_ends_with(str_replace('\\', '/', $mirror), '/.webman-aot-builder/build/project')
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
        $phpSources = $this->section($project, 'php-sources');
        $ignore = $this->section($project, 'ignore');
        $receiptFile = $mirror . '/.typephp/build/compilation-sources.json';
        if (is_link($mirror . '/.typephp') || is_link($mirror . '/.typephp/build')) {
            throw new ConfigurationException('compiler source receipt directory is unsafe');
        }
        if (!is_dir(dirname($receiptFile))
            && !mkdir(dirname($receiptFile), 0700, true) && !is_dir(dirname($receiptFile))) {
            throw new ConfigurationException('unable to create compiler source receipt directory');
        }
        if (is_file($receiptFile) && !is_link($receiptFile)) {
            $receipt = json_decode((string) file_get_contents($receiptFile), true);
            if (is_array($receipt) && ($receipt['schema'] ?? null) === 'webman-aot-compilation-sources-v2'
                && ($receipt['manifestSha256'] ?? null) === hash('sha256', $project)
                && is_array($receipt['inputSources'] ?? null) && is_array($receipt['inputIgnore'] ?? null)) {
                $sources = $receipt['inputSources'];
                $ignore = $receipt['inputIgnore'];
                $phpSources = is_array($receipt['inputPhpSources'] ?? null) ? $receipt['inputPhpSources'] : [];
            }
        }
        $inputSources = $sources;
        $inputPhpSources = $phpSources;
        $discovery = (new ProjectDiscovery($mirror))
            ->discover(false, $inputSources,
                explicitPhpSources: $inputPhpSources);
        $sources = $discovery->compilerSources();
        $diagnostics = $discovery->sourceDiagnostics();
        foreach ($sources as $path) {
            if ($this->covered($path, $ignore)) {
                $diagnostics[] = [
                    'file' => 'project.linux.yml', 'line' => 1,
                    'expression' => 'User ignore excludes a possible compiler source: ' . $path,
                    'candidates' => [$path],
                ];
            }
        }
        $sources = array_values(array_filter($sources, fn(string $path): bool => !$this->covered($path, $ignore)));
        $sources = array_values(array_unique($sources));
        $ignore = array_values(array_unique($ignore));
        $nativeSources = [];
        foreach ($discovery->files() as $file) {
            $path = $file['path'];
            if (CompilationSourceResolver::isNativeSourcePath($path)
                && $this->covered($path, $inputSources) && !$this->covered($path, $ignore)) {
                $nativeSources[] = $path;
            }
        }
        $allSources = array_values(array_unique(array_merge($sources, $nativeSources)));
        sort($allSources, SORT_STRING);
        $adapted = $this->replaceSection($project, 'sources', $allSources);
        $adapted = $this->replaceSection($adapted, 'php-sources', $sources);
        $adapted = $this->replaceSection($adapted, 'ignore', $ignore);
        if ($adapted !== $project && file_put_contents($projectFile, $adapted, LOCK_EX) !== strlen($adapted)) {
            throw new ConfigurationException('unable to complete PHP compiler sources');
        }
        $candidateSets = [];
        $candidateKeys = [];
        foreach ($diagnostics as &$diagnostic) {
            $key = hash('sha256', implode("\0", $diagnostic['candidates']));
            if (!isset($candidateKeys[$key])) {
                $candidateKeys[$key] = count($candidateSets);
                $candidateSets[] = $diagnostic['candidates'];
            }
            $diagnostic['candidateSet'] = $candidateKeys[$key];
            unset($diagnostic['candidates']);
        }
        unset($diagnostic);
        $receipt = json_encode([
            'schema' => 'webman-aot-compilation-sources-v2',
            'manifestSha256' => hash('sha256', $adapted),
            'inputSources' => $inputSources, 'inputIgnore' => $ignore,
            'inputPhpSources' => $inputPhpSources,
            'sources' => $sources, 'diagnostics' => $diagnostics,
            'candidateSets' => $candidateSets,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        if (is_link($receiptFile)
            || file_put_contents($receiptFile, $receipt, LOCK_EX) !== strlen($receipt)) {
            throw new ConfigurationException('unable to record compiler source selection');
        }
        return $adapted === $project ? null : hash('sha256', $adapted);
    }

    /** @return list<string> */
    private function section(string $project, string $name): array
    {
        $lines = explode("\n", $project);
        $range = $this->sectionRange($lines, $name);
        if ($range === null) {
            return [];
        }
        $match = [
            '',
            substr($lines[$range[0]], strlen($name) + 1),
            implode("\n", array_slice($lines, $range[0] + 1, $range[1] - $range[0] - 1)),
        ];
        $inline = trim($match[1]);
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
            $match[2] = implode("\n", array_map(static fn(string $item): string => '  - ' . trim($item), $items));
        }
        $entries = [];
        foreach (explode("\n", $match[2]) as $line) {
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
        $lines = explode("\n", $project);
        $range = $this->sectionRange($lines, $name);
        if ($range === null) {
            return rtrim($project) . "\n\n" . rtrim($block, "\n") . "\n";
        }
        array_splice($lines, $range[0], $range[1] - $range[0], explode("\n", rtrim($block, "\n") . "\n"));
        return implode("\n", $lines);
    }

    /** @param list<string> $lines @return array{int,int}|null */
    private function sectionRange(array $lines, string $name): ?array
    {
        $start = null;
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, $name . ':')) {
                if ($start !== null) {
                    throw new ConfigurationException("duplicate TypePHP project {$name} section");
                }
                $start = $index;
            }
        }
        if ($start === null) {
            return null;
        }
        $end = $start + 1;
        while (isset($lines[$end])
            && (trim($lines[$end]) === '' || $lines[$end][0] === ' ' || $lines[$end][0] === "\t")) {
            ++$end;
        }
        return [$start, $end];
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
