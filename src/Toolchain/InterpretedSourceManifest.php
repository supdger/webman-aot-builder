<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;

/**
 * Maintain the interpreted-source tier of a generated TypePHP project.
 *
 * A listed file keeps its original PHP text inside the artifact and is executed
 * by the embedded Zend engine when it is included. The source is never
 * rewritten, so the deployed behaviour stays the project's own behaviour.
 */
final class InterpretedSourceManifest
{
    private const SECTION = 'interpreted-sources';

    /** @return list<string> */
    public function read(string $projectFile): array
    {
        $contents = $this->contents($projectFile);
        $entries = [];
        $inSection = false;
        foreach (explode("\n", $contents) as $line) {
            if ($line === self::SECTION . ':') {
                $inSection = true;
                continue;
            }
            if ($line !== '' && $line[0] !== ' ' && $line[0] !== "\t") {
                $inSection = false;
            }
            if ($inSection && str_starts_with($line, '  - ')) {
                $entries[] = trim(substr($line, 4));
            }
        }

        return $entries;
    }

    /**
     * Replace the interpreted tier with the given mirror-relative sources.
     *
     * @param list<string> $paths
     */
    public function write(string $projectFile, array $paths): void
    {
        $contents = $this->contents($projectFile);
        $sorted = array_values(array_unique($paths));
        sort($sorted, SORT_STRING);
        $block = self::SECTION . ":\n";
        foreach ($sorted as $path) {
            $block .= '  - ' . $this->encode($path) . "\n";
        }

        $lines = explode("\n", $contents);
        $start = null;
        foreach ($lines as $index => $line) {
            if ($line === self::SECTION . ':') {
                if ($start !== null) {
                    throw new ConfigurationException(
                        'generated project has a duplicate interpreted-sources section'
                    );
                }
                $start = $index;
            }
        }
        if ($start === null) {
            $updated = rtrim($contents, "\n") . "\n\n" . $block;
        } else {
            $end = $start + 1;
            while (isset($lines[$end])
                && ($lines[$end] === '' || $lines[$end][0] === ' ' || $lines[$end][0] === "\t")) {
                ++$end;
            }
            array_splice($lines, $start, $end - $start, explode("\n", $block));
            $updated = implode("\n", $lines);
        }
        if (!str_ends_with($updated, "\n")) {
            $updated .= "\n";
        }
        if (is_link($projectFile)
            || file_put_contents($projectFile, $updated, LOCK_EX) !== strlen($updated)) {
            throw new ConfigurationException('unable to record the interpreted source tier');
        }
    }

    private function encode(string $path): string
    {
        if ($path === ''
            || str_contains($path, "\0")
            || str_starts_with($path, '/')
            || $path !== str_replace('\\', '/', $path)
            || preg_match('/^[A-Za-z]:/', $path) === 1
            || in_array('..', explode('/', $path), true)
            || in_array('.', explode('/', $path), true)
        ) {
            throw new ConfigurationException("unsafe interpreted source path: {$path}");
        }

        return preg_match('~^[A-Za-z0-9_./-]+$~D', $path) === 1
            ? $path
            : json_encode($path, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function contents(string $projectFile): string
    {
        $contents = is_file($projectFile) && !is_link($projectFile)
            ? file_get_contents($projectFile)
            : false;
        if (!is_string($contents)) {
            throw new ConfigurationException('generated TypePHP project manifest is missing');
        }

        return str_replace("\r\n", "\n", $contents);
    }
}
