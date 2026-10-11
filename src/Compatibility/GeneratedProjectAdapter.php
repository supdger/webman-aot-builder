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
        $workerPath = 'vendor/workerman/workerman/src/Worker.php';
        $shadowRelative = '.typephp/build/workerman-worker.php';
        $mapping = null;
        foreach ($generatedMappings as $entry) {
            if (($entry['path'] ?? null) === $workerPath && ($entry['shadow'] ?? null) === $shadowRelative) {
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
            } catch (ConfigurationException $exception) {
                fwrite(STDERR, "[adapt] skipped {$rule->id()}: {$exception->getMessage()}\n");
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
