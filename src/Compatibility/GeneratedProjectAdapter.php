<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class GeneratedProjectAdapter
{
    /** @param array<string,mixed> $lock @return array<string,string> */
    public function apply(string $mirrorDirectory, array $lock, array $generatedMappings = []): array
    {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror) || is_link($mirrorDirectory)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
        ) {
            throw new ConfigurationException('generated adaptation requires an isolated project mirror');
        }
        $generatedDirectory = \WebmanAotBuilder\Project\ProjectMirror::generationSourceDirectory($mirror);
        if ($generatedDirectory === null) { throw new ConfigurationException('cannot resolve owned generated source directory'); }
        $generatedPrefix = str_replace(DIRECTORY_SEPARATOR, '/', substr($generatedDirectory, strlen($mirror) + 1)) . '/';
        $workerPath = 'vendor/workerman/workerman/src/Worker.php';
        $mapping = null;
        foreach ($generatedMappings as $entry) {
            if (($entry['path'] ?? null) === $workerPath && is_string($entry['shadow'] ?? null)
                && str_starts_with($entry['shadow'], $generatedPrefix)
                && preg_match('~^workerman-worker(?:-[1-9][0-9]*)?\.php$~D', substr($entry['shadow'], strlen($generatedPrefix))) === 1) {
                if ($mapping !== null) {
                    throw new ConfigurationException('Workerman generated shadow evidence is duplicated');
                }
                $mapping = $entry;
            }
        }
        if ($mapping === null) {
            return [];
        }
        $this->readGuardedFile($mirror . '/' . $workerPath, (string) ($mapping['sourceSha256'] ?? ''), 'Workerman source');
        $shadowRelative = $mapping['shadow'];
        $shadowFile = $mirror . '/' . $shadowRelative;
        $shadow = $this->readGuardedFile($shadowFile, (string) ($mapping['shadowSha256'] ?? ''), 'Workerman generated shadow');
        $adapted = $shadow;
        $required = ['workerman-worker-pid-runtime-path', 'workerman-worker-target-loadavg-call'];
        $found = [];
        foreach (WebmanWorkermanRules::knownRules() as $rule) {
            if (!in_array($rule->id(), $required, true)) {
                continue;
            }
            $found[] = $rule->id();
            try {
                $adapted = $rule->transform($adapted, 'source-validated');
            } catch (SourceShapeMismatch $exception) {
                fwrite(STDERR, "[adapt] skipped {$rule->id()}: {$exception->getMessage()}; original generated input retained\n");
                return ['workerShadowSha256' => hash('sha256', $shadow)];
            }
        }
        if ($found !== $required) {
            throw new ConfigurationException('Workerman generated compatibility rules are missing');
        }
        if ($adapted !== $shadow && file_put_contents($shadowFile, $adapted, LOCK_EX) !== strlen($adapted)) {
            throw new ConfigurationException('unable to write Workerman generated adaptation');
        }
        return ['workerShadowSha256' => hash('sha256', $adapted)];
    }
    private function readGuardedFile(string $path, string $expectedSha256, string $label): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1
            || !is_file($path) || is_link($path)
            || str_replace(DIRECTORY_SEPARATOR, '/', realpath($path) ?: '')
                !== str_replace(DIRECTORY_SEPARATOR, '/', $path)
        ) {
            throw new ConfigurationException("{$label} is missing or unsafe");
        }
        $source = file_get_contents($path);
        if (!is_string($source)
            || !hash_equals($expectedSha256, hash('sha256', $source))
        ) {
            throw new ConfigurationException("{$label} source digest drifted");
        }
        return $source;
    }
}
