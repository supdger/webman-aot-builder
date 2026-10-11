<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectDiscovery;
use WebmanAotBuilder\Project\ProjectProfile;

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
        $discovery = (new ProjectDiscovery($mirror))
            ->discover(new ProjectProfile(ProjectProfile::PHP, [], []), false);
        // The builder owns the input list; no PHP is ignored or replaced.
        $sources = [];
        $ignore = [];
        foreach ($discovery->files() as $file) {
            if ($file['category'] === ProjectDiscovery::BUSINESS_PHP) {
                $sources[] = $file['path'];
            }
        }
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

    /** @return list<string> */
    private function section(string $project, string $name): array
    {
        if (preg_match('/^' . $name . ':[ \t]*([^\n]*)\n((?:[ \t]+[^\n]*\n|\n)*)/m', $project, $match) !== 1) {
            return [];
        }
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
        $pattern = '/^' . $name . ':[ \t]*[^\n]*\n((?:[ \t]+[^\n]*\n|\n)*)/m';
        if (preg_match($pattern, $project) !== 1) {
            return rtrim($project) . "\n\n" . $block;
        }
        return (string) preg_replace_callback($pattern, static fn(): string => $block, $project, 1);
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
