<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class MonologWebProcessorRule
{
    private const SOURCE = 'vendor/monolog/monolog/src/Monolog/Processor/WebProcessor.php';
    private const ORIGINAL = '$this->serverData = &$_SERVER;';
    private const REPLACEMENT = '$this->serverData = &$GLOBALS[\'_SERVER\'];';

    /**
     * @param array<string,mixed> $policy
     */
    public function apply(string $mirrorDirectory, array $policy): ?string
    {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror)
            || is_link($mirrorDirectory)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
        ) {
            throw new ConfigurationException('Monolog adaptation requires an isolated project mirror');
        }
        $lockFile = $mirror . '/composer.lock';
        $contents = is_file($lockFile) && !is_link($lockFile)
            ? file_get_contents($lockFile)
            : false;
        if (!is_string($contents)) {
            throw new ConfigurationException('Monolog adaptation requires composer.lock');
        }
        try {
            $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException('Monolog adaptation composer.lock is invalid', previous: $exception);
        }
        if (!is_array($composer)) {
            throw new ConfigurationException('Monolog adaptation composer.lock is invalid');
        }
        $matches = [];
        foreach (array_merge($composer['packages'] ?? [], $composer['packages-dev'] ?? []) as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'monolog/monolog') {
                $matches[] = $package;
            }
        }
        $sourcePath = $mirror . '/' . self::SOURCE;
        if ($matches === [] && !file_exists($sourcePath)) {
            return null;
        }
        if (count($matches) !== 1 || !is_file($sourcePath) || is_link($sourcePath)
            || ($policy['path'] ?? null) !== self::SOURCE
            || !is_string($matches[0]['version'] ?? null)
            || $matches[0]['version'] === ''
            || ($policy['rule'] ?? null) !== 'monolog.web-processor-global-reference.v1'
        ) {
            throw new ConfigurationException('Monolog WebProcessor package or rule drifted');
        }
        $source = file_get_contents($sourcePath);
        if (!is_string($source)) {
            throw new ConfigurationException('Monolog WebProcessor source is unreadable');
        }
        try {
            token_get_all($source, TOKEN_PARSE);
            $adapted = (new BoundedTextRule(
                'monolog.web-processor-global-reference.v1',
                'monolog/monolog',
                self::SOURCE,
                new MinimumVersion('1.0.0'),
                ['namespace Monolog\\Processor;', 'class WebProcessor', 'function __construct('],
                self::ORIGINAL,
                self::REPLACEMENT,
                1,
                [self::REPLACEMENT],
                ['__construct']
            ))->transform($source, $matches[0]['version']);
            token_get_all($adapted, TOKEN_PARSE);
        } catch (\ParseError $exception) {
            throw new ConfigurationException('Monolog WebProcessor source structure drifted', previous: $exception);
        }
        $digest = hash('sha256', $adapted);
        if (file_put_contents($sourcePath, $adapted) === false) {
            throw new ConfigurationException('Monolog WebProcessor adaptation cannot be written');
        }
        return $digest;
    }
}
