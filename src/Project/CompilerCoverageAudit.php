<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class CompilerCoverageAudit
{
    /**
     * @param array{source:string,sourceSha256:string,replacement:string,replacementSha256:string,policy:string}|null $entrypointMapping
     */
    public function __construct(private readonly ?array $entrypointMapping = null)
    {
    }

    /**
     * @return array{direct:int,shadow:int,interpreted:int}
     */
    public function audit(string $mirrorDirectory, CoverageLedger $ledger): array
    {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
            || is_link($mirrorDirectory)
        ) {
            throw new ConfigurationException('compiler coverage requires an isolated build mirror');
        }
        $manifest = $this->readCompilerLists($mirror . '/project.linux.yml');
        $counts = ['direct' => 0, 'shadow' => 0, 'interpreted' => 0];
        $problems = [];
        foreach ($ledger->files() as $entry) {
            if (($entry['category'] ?? null) === ProjectDiscovery::THIRD_PARTY_DYNAMIC_PHP) {
                $path = (string) $entry['path'];
                if (($entry['status'] ?? null) !== CoverageLedger::RUNTIME_APPROVED
                    || ($entry['policy'] ?? null) !== 'runtime.third-party-dynamic.v1'
                    || $this->digest($mirror, $path) !== ($entry['sourceSha256'] ?? null)
                    || !$this->covered($path, $manifest['ignore'])
                ) {
                    $problems[] = "registered third-party dynamic PHP is not covered: {$path}";
                }
                continue;
            }
            if (($entry['category'] ?? null) !== ProjectDiscovery::BUSINESS_PHP) {
                continue;
            }
            $path = (string) $entry['path'];
            if (!is_string($entry['sourceSha256'] ?? null)
                || $this->digest($mirror, $path) !== $entry['sourceSha256']
            ) {
                $problems[] = "business PHP source digest drifted: {$path}";
                continue;
            }
            // The compiler could not translate this source, so it ships as its
            // original text and the embedded Zend engine executes it. It is
            // covered by the artifact, but it is not compiled ahead of time.
            if (in_array($path, $manifest['interpreted'], true)) {
                $counts['interpreted']++;
                continue;
            }
            $status = $entry['status'] ?? null;
            $sourceIncluded = $this->covered($path, $manifest['sources']);
            $sourceIgnored = $this->covered($path, $manifest['ignore']);
            if ($status === CoverageLedger::SOURCE_NOT_SELECTED) {
                if ($sourceIncluded && !$sourceIgnored) {
                    $problems[] = "unselected PHP is present in compiler inputs: {$path}";
                }
                continue;
            }
            if ($status === CoverageLedger::COMPILED_DIRECT) {
                if (!$sourceIncluded || $sourceIgnored) {
                    $problems[] = "business PHP is not directly compiled: {$path}";
                    continue;
                }
                $counts['direct']++;
                continue;
            }
            if ($status !== CoverageLedger::COMPILED_SHADOW) {
                $problems[] = "business PHP bypasses AOT compilation: {$path}";
                continue;
            }
            $replacement = $entry['replacement'] ?? null;
            $expectedDigest = $entry['replacementSha256'] ?? null;
            $entrypointReplacement = $this->isLockedEntrypointReplacement(
                $entry,
                $replacement,
                $expectedDigest
            );
            $supportOverride = $this->isProjectSupportOverride(
                $entry,
                $replacement
            );
            if (!is_string($replacement)
                || (!str_starts_with($replacement, '.typephp/build/')
                    && !$entrypointReplacement
                    && !$supportOverride)
                || !$sourceIgnored
                || !$this->covered($replacement, $manifest['sources'])
                || $this->covered($replacement, $manifest['ignore'])
                || !is_string($expectedDigest)
                || $this->digest($mirror, $replacement) !== $expectedDigest
            ) {
                $problems[] = "business PHP AOT replacement is not compiled: {$path}";
                continue;
            }
            $counts['shadow']++;
        }
        if ($problems !== []) {
            throw new ConfigurationException(implode("\n", $problems));
        }
        return $counts;
    }

    /**
     * @param array<string,string|null> $entry
     */
    private function isLockedEntrypointReplacement(
        array $entry,
        mixed $replacement,
        mixed $replacementDigest
    ): bool {
        $mapping = $this->entrypointMapping;
        if ($mapping === null
            || !is_string($mapping['source'] ?? null)
            || !is_string($mapping['sourceSha256'] ?? null)
            || !is_string($mapping['replacement'] ?? null)
            || !is_string($mapping['replacementSha256'] ?? null)
            || !is_string($mapping['policy'] ?? null)
            || !is_array($mapping['sources'] ?? [])
        ) {
            return false;
        }
        $sources = $mapping['sources'] ?? [];
        $sources[$mapping['source']] = $mapping['sourceSha256'];
        $sourceDigest = $sources[$entry['path'] ?? ''] ?? null;
        return is_string($sourceDigest)
            && preg_match('/^[a-f0-9]{64}$/D', $sourceDigest) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $mapping['replacementSha256']) === 1
            && ($entry['policy'] ?? null) === $mapping['policy']
            && ($entry['sourceSha256'] ?? null) === $sourceDigest
            && $replacement === $mapping['replacement']
            && $replacementDigest === $mapping['replacementSha256'];
    }

    /** @param array<string,string|null> $entry */
    private function isProjectSupportOverride(array $entry, mixed $replacement): bool
    {
        foreach (['Request', 'Response'] as $name) {
            if (($entry['path'] ?? null)
                    === "vendor/workerman/webman-framework/src/support/{$name}.php"
                && ($entry['owner'] ?? null) === 'webman-core'
                && ($entry['policy'] ?? null) === 'webman.project-support-override.v1'
                && $replacement === "support/{$name}.php"
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{sources:list<string>,ignore:list<string>,interpreted:list<string>}
     */
    private function readCompilerLists(string $path): array
    {
        $contents = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($contents) || str_contains($contents, "\r")) {
            throw new ConfigurationException('compiler coverage manifest is missing or unsafe');
        }
        $result = ['sources' => [], 'ignore' => [], 'interpreted' => []];
        $section = null;
        $seenSections = [];
        foreach (explode("\n", $contents) as $line) {
            if ($line === 'sources:' || $line === 'ignore:' || $line === 'interpreted-sources:') {
                $section = $line === 'interpreted-sources:'
                    ? 'interpreted'
                    : substr($line, 0, -1);
                if (isset($seenSections[$section])) {
                    throw new ConfigurationException(
                        "compiler coverage has duplicate {$section} section"
                    );
                }
                $seenSections[$section] = true;
                continue;
            }
            if ($line !== '' && $line[0] !== ' ') {
                $section = null;
            }
            if ($section !== null && str_starts_with($line, '  - ')) {
                $entry = trim(substr($line, 4));
                if (str_starts_with($entry, '"')) {
                    $decoded = json_decode($entry, true);
                    if (!is_string($decoded)) {
                        throw new ConfigurationException("compiler coverage has invalid {$section} entry");
                    }
                    $entry = $decoded;
                } elseif (str_starts_with($entry, "'")) {
                    if (!str_ends_with($entry, "'")) {
                        throw new ConfigurationException("compiler coverage has invalid {$section} entry");
                    }
                    $entry = str_replace("''", "'", substr($entry, 1, -1));
                }
                while (str_starts_with($entry, './')) {
                    $entry = substr($entry, 2);
                }
                if ($entry === '.') {
                    $result[$section][] = '.';
                    continue;
                }
                if ($entry === ''
                    || $entry !== str_replace('\\', '/', $entry)
                    || str_starts_with($entry, '/')
                    || preg_match('/^[A-Za-z]:/', $entry) === 1
                    || in_array('..', explode('/', $entry), true)
                    || in_array('.', explode('/', $entry), true)
                ) {
                    throw new ConfigurationException(
                        "compiler coverage has unsafe {$section} entry"
                    );
                }
                $result[$section][] = rtrim($entry, '/');
            }
        }
        if (!isset($seenSections['sources'])
            || $result['sources'] === []
        ) {
            throw new ConfigurationException('compiler coverage manifest is incomplete');
        }
        return $result;
    }

    /**
     * @param list<string> $entries
     */
    private function covered(string $path, array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($entry === '.' || $path === $entry || str_starts_with($path, $entry . '/')) {
                return true;
            }
        }
        return false;
    }

    private function digest(string $mirror, string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, '\\')
            || preg_match('/^[A-Za-z]:/', $relative) === 1
            || in_array('.', explode('/', $relative), true)
            || in_array('', explode('/', $relative), true)
            || str_starts_with($relative, '/')
            || in_array('..', explode('/', $relative), true)
        ) {
            return null;
        }
        $componentPath = $mirror;
        foreach (explode('/', $relative) as $component) {
            $componentPath .= '/' . $component;
            if (is_link($componentPath)) {
                return null;
            }
        }
        $path = $mirror . '/' . $relative;
        $digest = is_file($path) && !is_link($path)
            ? hash_file('sha256', $path)
            : false;
        return is_string($digest) ? $digest : null;
    }
}
