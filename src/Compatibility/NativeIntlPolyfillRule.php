<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class NativeIntlPolyfillRule
{
    /**
     * @param array<string,mixed> $policy
     * @return array{shadowSha256:string,projectSha256:string}
     */
    public function apply(string $mirrorDirectory, array $policy): array
    {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror) || is_link($mirrorDirectory)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
            || ($policy['rule'] ?? null) !== 'symfony.native-intl-polyfill.v1'
            || !is_array($policy['packages'] ?? null)
            || !is_array($policy['shadows'] ?? null)
        ) {
            throw new ConfigurationException('native intl polyfill adaptation policy is invalid');
        }

        $lock = json_decode((string) file_get_contents($mirror . '/composer.lock'), true);
        if (!is_array($lock)) {
            throw new ConfigurationException('native intl polyfill Composer lock is invalid');
        }
        $installed = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                if (isset($installed[$package['name']])) {
                    throw new ConfigurationException('native intl polyfill Composer lock contains duplicate package: ' . $package['name']);
                }
                $installed[$package['name']] = $package;
            }
        }

        $projectFile = $mirror . '/project.linux.yml';
        $project = $this->read($projectFile, 'TypePHP project');
        $sections = explode("\nignore:\n", $project);
        if (count($sections) !== 2) {
            throw new ConfigurationException('native intl TypePHP project structure drifted');
        }
        foreach (['grapheme', 'idn', 'normalizer'] as $name) {
            $packageName = "symfony/polyfill-intl-{$name}";
            $packagePolicy = $policy['packages'][$packageName] ?? null;
            $shadowPolicy = $policy['shadows'][$name] ?? null;
            $package = $installed[$packageName] ?? null;
            $bootstrap = "vendor/symfony/polyfill-intl-{$name}/bootstrap80.php";
            $shadow = ".typephp/build/symfony-{$name}-functions.php";
            if (!is_array($packagePolicy) || !is_array($shadowPolicy)
                || !is_array($package)
                || !is_string($package['version'] ?? null)
                || $package['version'] === ''
            ) {
                throw new ConfigurationException("native intl polyfill package drifted: {$name}");
            }
            if (($packagePolicy['bootstrapSha256'] ?? null)
                    !== hash('sha256', $this->read($mirror . '/' . $bootstrap, $bootstrap))
                || ($shadowPolicy['sourceSha256'] ?? null)
                    !== hash('sha256', $this->read($mirror . '/' . $shadow, $shadow))
            ) {
                throw new ConfigurationException("native intl polyfill source drifted: {$name}");
            }
            $shadowCount = substr_count($sections[0] . "\n", "\n  - {$shadow}\n");
            $bootstrapCount = substr_count($sections[0] . "\n", "\n  - {$bootstrap}\n");
            $ignoredCount = substr_count("\n" . $sections[1], "\n  - {$bootstrap}\n");
            if ($shadowCount !== 1 || $bootstrapCount !== 0 || $ignoredCount !== 1) {
                throw new ConfigurationException(
                    "native intl polyfill project entries drifted: {$name}"
                    . " (source={$shadowCount}, bootstrap={$bootstrapCount}, ignored={$ignoredCount})"
                );
            }
        }

        $shadowPath = $mirror . '/.typephp/build/symfony-grapheme-functions.php';
        $shadow = $this->read($shadowPath, 'grapheme shadow');
        $retained = [];
        foreach (['grapheme_levenshtein', 'grapheme_strrev'] as $function) {
            $count = preg_match_all(
                '/^    function ' . $function . '\([^\\n]*$/m',
                $shadow,
                $matches
            );
            if ($count !== 1) {
                throw new ConfigurationException("native intl polyfill function drifted: {$function}");
            }
            $retained[] = $matches[0][0];
        }
        $adapted = "<?php\n\nuse Symfony\\Polyfill\\Intl\\Grapheme as p;\n\n"
            . implode("\n\n", $retained) . "\n";
        if (($policy['adaptedGraphemeSha256'] ?? null) !== hash('sha256', $adapted)) {
            throw new ConfigurationException('native intl grapheme shadow output drifted');
        }
        foreach (['idn', 'normalizer'] as $name) {
            $source = ".typephp/build/symfony-{$name}-functions.php";
            $line = "  - {$source}\n";
            if (substr_count($project, $line) !== 1) {
                throw new ConfigurationException("native intl polyfill source drifted: {$name}");
            }
            $project = str_replace($line, '', $project);
        }
        if (file_put_contents($shadowPath, $adapted, LOCK_EX) === false
            || file_put_contents($projectFile, $project, LOCK_EX) === false
        ) {
            throw new ConfigurationException('unable to write native intl AOT adaptation');
        }
        return [
            'shadowSha256' => hash('sha256', $adapted),
            'projectSha256' => hash('sha256', $project),
        ];
    }

    private function read(string $path, string $label): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new ConfigurationException("native intl polyfill source is missing or unsafe: {$label}");
        }
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new ConfigurationException("native intl polyfill source is unreadable: {$label}");
        }
        return $source;
    }
}
