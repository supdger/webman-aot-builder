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
        if (!is_string($version) || $version === '') {
            throw new ConfigurationException('Carbon profile requires an installed package declaration');
        }
        $this->assertThinkOrmCollection(dirname($projectLockFile));
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
        $after = <<<'PHP'
    private const REQUIRED_PACKAGES = [
        'topthink/think-orm',
        'nesbot/carbon',
    ];
PHP;
        $guard = <<<'PHP'
            if (!in_array($version, $versions, true)) {
                throw new \RuntimeException(
                    "Unsupported {$package} version {$version}; supported: " . implode(', ', $versions) . '.',
                );
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
            'array_keys(self::SUPPORTED_EXACT_VERSIONS)'], [$after, '',
            'foreach (self::REQUIRED_PACKAGES as $package)',
            'self::REQUIRED_PACKAGES'], $source);
        if (str_contains($shadow, 'SUPPORTED_EXACT_VERSIONS')
            || substr_count($shadow, 'foreach (self::REQUIRED_PACKAGES as $package)') !== 1
        ) {
            throw new ConfigurationException('dependency source capability overlay postcondition failed');
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

    private function assertThinkOrmCollection(string $project): void
    {
        $path = $project . '/vendor/topthink/think-orm/src/model/Collection.php';
        $source = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($source)) {
            throw new ConfigurationException('ThinkORM model collection source is missing or unsafe');
        }
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $exception) {
            throw new ConfigurationException('ThinkORM model collection source is invalid PHP', previous: $exception);
        }
        $tokens = array_values(array_filter($tokens, static fn(array|string $token): bool =>
            !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $namespace = false;
        $parent = null;
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) { continue; }
            if ($token[0] === T_NAMESPACE) {
                $namespace = ($tokens[$index + 1][1] ?? null) === 'think\\model'
                    && ($tokens[$index + 2] ?? null) === ';';
            }
            if ($token[0] === T_USE && ltrim($tokens[$index + 1][1] ?? '', '\\') === 'think\\Collection') {
                $parent = ($tokens[$index + 2][0] ?? null) === T_AS
                    ? ($tokens[$index + 3][1] ?? '')
                    : 'Collection';
            }
            if ($namespace && $token[0] === T_CLASS
                && ($tokens[$index + 1][1] ?? null) === 'Collection'
                && ($tokens[$index + 2][0] ?? null) === T_EXTENDS
                && in_array($tokens[$index + 3][1] ?? null, [$parent, '\\think\\Collection'], true)
            ) { return; }
        }
        throw new ConfigurationException('ThinkORM model collection requires think\\model\\Collection extending think\\Collection');
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
