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
        $sourcePath = $mirror . '/' . self::SOURCE;
        if (!file_exists($sourcePath) && !is_link($sourcePath)) {
            return null;
        }
        if (!is_file($sourcePath) || is_link($sourcePath) || str_replace(DIRECTORY_SEPARATOR, '/', realpath($sourcePath) ?: '') !== str_replace(DIRECTORY_SEPARATOR, '/', $sourcePath)) {
            throw new ConfigurationException('Monolog WebProcessor source is unsafe');
        }
        if (($policy['path'] ?? null) !== self::SOURCE
            || ($policy['rule'] ?? null) !== 'monolog.web-processor-global-reference.v1'
        ) {
            throw new ConfigurationException('Monolog WebProcessor rule drifted');
        }
        $source = file_get_contents($sourcePath);
        if (!is_string($source)) {
            throw new ConfigurationException('Monolog WebProcessor source is unreadable');
        }
        $aliases = (new \WebmanAotBuilder\Project\GeneratedProjectCoveragePlanner())->sourceAliases($mirror);
        if (isset($aliases[self::SOURCE])) {
            return null;
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
            ))->transform($source, 'source-verified');
            token_get_all($adapted, TOKEN_PARSE);
        } catch (\ParseError|SourceShapeMismatch $exception) {
            fwrite(STDERR, "[adapt] skipped Monolog WebProcessor: {$exception->getMessage()}; original compiler input retained\n");
            return null;
        }
        if (file_get_contents($sourcePath) !== $source) {
            throw new ConfigurationException('Monolog WebProcessor source changed during preparation');
        }
        $digest = hash('sha256', $adapted);
        if (file_put_contents($sourcePath, $adapted) === false) {
            throw new ConfigurationException('Monolog WebProcessor adaptation cannot be written');
        }
        return $digest;
    }
}
