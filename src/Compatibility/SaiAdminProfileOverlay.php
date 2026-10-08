<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class SaiAdminProfileOverlay
{
    public function prepare(
        string $sourceFile,
        string $expectedSourceSha256,
        string $projectLockFile,
        string $privateCache,
        array $policy
    ): string {
        $lock = json_decode(
            (string) file_get_contents($projectLockFile),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $version = null;
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'nesbot/carbon') {
                $version = $package['version'] ?? null;
                break;
            }
        }
        $baseVersion = $policy['baseVersion'] ?? null;
        if (!is_string($baseVersion) || !is_string($version) || $version === '') {
            throw new ConfigurationException('Carbon profile requires a locked version and base policy');
        }
        $carbonRoot = dirname($projectLockFile) . '/vendor/nesbot/carbon/src/Carbon';
        $period = $this->readCarbonSource($carbonRoot . '/CarbonPeriod.php');
        $interval = $this->readCarbonSource($carbonRoot . '/CarbonInterval.php');
        (new SaiAdminCarbonPeriodRule())->replacements($period, $interval);
        (new SaiAdminCarbonIntervalRule())->transform($interval);
        $source = file_get_contents($sourceFile);
        if (!is_string($source)
            || !hash_equals($expectedSourceSha256, hash('sha256', $source))
        ) {
            throw new ConfigurationException('locked SaiAdmin profile source drifted');
        }
        $before = <<<'PHP'
    private const SUPPORTED_EXACT_VERSIONS = [
        'topthink/think-orm' => ['v3.0.34'],
        'nesbot/carbon' => ['3.13.2'],
    ];
PHP;
        $after = "    private const MINIMUM_VERSIONS = [\n"
            . "        'topthink/think-orm' => '3.0.34',\n"
            . "        'nesbot/carbon' => " . var_export($baseVersion, true) . ",\n    ];";
        $guard = <<<'PHP'
            if (!in_array($version, $versions, true)) {
                throw new \RuntimeException(
                    "Unsupported {$package} version {$version}; supported: " . implode(', ', $versions) . '.',
                );
            }
PHP;
        $minimumGuard = <<<'PHP'
            if (preg_match('/^v?([0-9]+\.[0-9]+\.[0-9]+(?:\.[0-9]+)?(?:-[0-9A-Za-z.-]+)?)(?:\+[0-9A-Za-z.-]+)?$/D', $version, $release) !== 1) {
                if ($package !== 'nesbot/carbon') {
                    throw new \RuntimeException("Cannot establish {$package} minimum {$minimum} from version {$version}.");
                }
            } elseif (version_compare($release[1], $minimum, '<')) {
                throw new \RuntimeException("The saiadmin profile requires {$package} >= {$minimum}; installed {$version}.");
            }
PHP;
        if (substr_count($source, $before) !== 1 || substr_count($source, $guard) !== 1
            || substr_count($source, 'foreach (self::SUPPORTED_EXACT_VERSIONS as $package => $versions)') !== 1
            || substr_count($source, 'array_keys(self::SUPPORTED_EXACT_VERSIONS)') !== 1
        ) {
            throw new ConfigurationException('locked dependency version gate structure drifted');
        }
        $shadow = str_replace([$before, $guard,
            'foreach (self::SUPPORTED_EXACT_VERSIONS as $package => $versions)',
            'array_keys(self::SUPPORTED_EXACT_VERSIONS)'], [$after, $minimumGuard,
            'foreach (self::MINIMUM_VERSIONS as $package => $minimum)',
            'array_keys(self::MINIMUM_VERSIONS)'], $source);
        if (str_contains($shadow, 'SUPPORTED_EXACT_VERSIONS') || substr_count($shadow, $minimumGuard) !== 1) {
            throw new ConfigurationException('dependency minimum version overlay postcondition failed');
        }
        $directory = $privateCache . '/saiadmin-profile-overlays';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ConfigurationException('cannot create private SaiAdmin profile overlay directory');
        }
        $target = $directory . '/dependencies-' . hash('sha256', $shadow) . '.php';
        if (is_file($target)) {
            if (hash_file('sha256', $target) !== hash('sha256', $shadow)) {
                throw new ConfigurationException('private SaiAdmin profile overlay drifted');
            }
        } elseif (file_put_contents($target, $shadow, LOCK_EX) !== strlen($shadow)) {
            throw new ConfigurationException('cannot write private SaiAdmin profile overlay');
        }
        return $target;
    }

    private function readCarbonSource(string $path): string
    {
        $source = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($source)) {
            throw new ConfigurationException('Carbon compatibility source is missing: ' . $path);
        }
        return $source;
    }
}
