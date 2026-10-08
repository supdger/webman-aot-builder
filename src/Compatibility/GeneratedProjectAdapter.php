<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class GeneratedProjectAdapter
{
    /**
     * @param array<string,mixed> $lock Verified upstream generator lock.
     * @return array{workerShadowSha256:string,installOnlySourceSha256:string}
     */
    public function apply(string $mirrorDirectory, array $lock): array
    {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror) || is_link($mirrorDirectory)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
        ) {
            throw new ConfigurationException('generated adaptation requires an isolated project mirror');
        }

        $workerPath = 'vendor/workerman/workerman/src/Worker.php';
        $workerMapping = $lock['mappings'][$workerPath] ?? null;
        $workerVersion = $lock['packages']['workerman/workerman']['version'] ?? null;
        $shadowRelative = '.typephp/build/workerman-worker.php';
        if (!is_array($workerMapping)
            || ($workerMapping['shadow'] ?? null) !== $shadowRelative
            || !is_string($workerVersion)
        ) {
            throw new ConfigurationException('Workerman generated shadow mapping is missing');
        }
        $shadowFile = $mirror . '/' . $shadowRelative;
        $shadow = $this->readGuardedFile(
            $shadowFile,
            (string) ($workerMapping['shadowSha256'] ?? ''),
            'Workerman generated shadow'
        );
        $requiredRuleIds = [
            'workerman-worker-pid-runtime-path',
            'workerman-worker-target-loadavg-call',
        ];
        $appliedRuleIds = [];
        $adaptedShadow = $shadow;
        foreach (WebmanWorkermanRules::knownRules() as $candidate) {
            if (in_array($candidate->id(), $requiredRuleIds, true)) {
                $adaptedShadow = $candidate->transform($adaptedShadow, $workerVersion);
                $appliedRuleIds[] = $candidate->id();
            }
        }
        if ($appliedRuleIds !== $requiredRuleIds) {
            throw new ConfigurationException('Workerman generated compatibility rules are missing');
        }
        $expectedAdaptedSha256 = $workerMapping['adaptedShadowSha256'] ?? null;
        if (!is_string($expectedAdaptedSha256)
            || preg_match('/^[a-f0-9]{64}$/D', $expectedAdaptedSha256) !== 1
            || !hash_equals($expectedAdaptedSha256, hash('sha256', $adaptedShadow))
        ) {
            throw new ConfigurationException(
                'Workerman generated shadow post-adaptation digest drifted'
            );
        }

        $installerPath = 'vendor/workerman/webman-framework/src/support/Plugin.php';
        $installerVersion = $lock['packages']['workerman/webman-framework']['version'] ?? null;
        $installerRule = $lock['installOnly'][$installerPath] ?? null;
        if (!is_string($installerVersion) || !is_array($installerRule)
            || ($installerRule['policy'] ?? null) !== 'webman.composer-installer.v1'
        ) {
            throw new ConfigurationException('Webman Composer installer policy is missing');
        }
        $installer = $this->readGuardedFile(
            $mirror . '/' . $installerPath,
            (string) ($installerRule['sourceSha256'] ?? ''),
            'Webman Composer installer'
        );
        $installerTokens = $this->significantTokens($installer);
        foreach ([
            'class Plugin',
            'public static function install($event)',
            'public static function update($event)',
            'public static function uninstall($event)',
            "require_once __DIR__ . '/helpers.php';",
        ] as $marker) {
            $markerTokens = $this->significantTokens('<?php ' . $marker);
            $found = false;
            for ($offset = 0; $offset <= count($installerTokens) - count($markerTokens); ++$offset) {
                if (array_slice($installerTokens, $offset, count($markerTokens)) === $markerTokens) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new ConfigurationException(
                    'Webman Composer installer source structure drifted'
                );
            }
        }

        $projectFile = $mirror . '/project.linux.yml';
        $project = is_file($projectFile) && !is_link($projectFile)
            ? file_get_contents($projectFile)
            : false;
        $anchor = "  - vendor/workerman/webman-framework/src/support/helpers.php\n";
        if (!is_string($project)
            || substr_count($project, $anchor) !== 1
            || substr_count($project, "\nignore:\n") !== 1
            || str_contains($project, "  - {$installerPath}\n")
            || !str_contains($project, "  - {$shadowRelative}\n")
        ) {
            throw new ConfigurationException('generated project installer exclusion shape drifted');
        }
        $adaptedProject = str_replace($anchor, $anchor . "  - {$installerPath}\n", $project);
        if (file_put_contents($shadowFile, $adaptedShadow) === false
            || file_put_contents($projectFile, $adaptedProject) === false
        ) {
            throw new ConfigurationException('unable to write generated AOT adaptations');
        }

        return [
            'workerShadowSha256' => hash('sha256', $adaptedShadow),
            'installOnlySourceSha256' => hash('sha256', $installer),
        ];
    }

    /** @return list<array{int|string,string}> */
    private function significantTokens(string $source): array
    {
        $result = [];
        foreach (token_get_all($source) as $token) {
            $id = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;
            if (!in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $result[] = [$id, $text];
            }
        }
        return $result;
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
