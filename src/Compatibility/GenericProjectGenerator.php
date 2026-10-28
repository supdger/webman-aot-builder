<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectDiscovery;
use WebmanAotBuilder\Project\CompilationEntryResolver;

final class GenericProjectGenerator
{
    /**
     * @return array{mappings:list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}>,mainFile:?string,entrypointMapping:?array}
     */
    public function generate(
        string $mirror,
        string $stubFile,
        string $stubSha256,
        string $outputName,
        array $dynamicPhp = [],
        ?string $targetPhpVersion = null,
        array $targetCapabilities = [],
        ?string $explicitEntry = null
    ): array {
        $resolved = realpath($mirror);
        if (!is_string($resolved) || $resolved !== $mirror || is_link($mirror)
            || !str_ends_with(str_replace('\\', '/', $mirror), '/.webman-aot-builder/build/project')
            || is_link($mirror . '/.typephp') || is_link($mirror . '/.typephp/build')
        ) {
            throw new ConfigurationException('PHP generation requires an isolated project mirror');
        }
        $build = $mirror . '/.typephp/build';
        if (!is_dir($build) && !mkdir($build, 0700, true) && !is_dir($build)) {
            throw new ConfigurationException('cannot create PHP generation directory');
        }
        $sourceFiles = (new ProjectDiscovery($mirror))->discover(false, explicitEntry: $explicitEntry)->files();
        $paths = array_column(array_filter(
            $sourceFiles,
            static fn(array $file): bool => $file['category'] === ProjectDiscovery::BUSINESS_PHP
        ), 'path');
        (new \WebmanAotBuilder\Project\PathLiteralSanitizer(dirname(dirname(dirname($resolved)))))
            ->sanitize($resolved, $paths);
        $manifestFile = is_file($mirror . '/project.linux.yml') ? $mirror . '/project.linux.yml' : $mirror . '/project.yml';
        $entry = (new CompilationEntryResolver($mirror))->resolve(
            $explicitEntry,
            is_file($manifestFile) ? $this->read($manifestFile) : null
        );
        $mainFile = $entry ?? (in_array('main.php', $paths, true) ? 'main.php' : null);
        $this->write($build . '/entrypoint-mapping.json', "null\n");
        $this->manifest($mirror, $paths, $outputName, $entry);
        // The mirror's build/ tree is owned by the TypePHP compiler: its cache
        // entries, generated sources and binary output are rewritten or pruned
        // during compilation, so they can never be stable recorded inputs.
        $this->write(
            $build . '/source-inputs.json',
            json_encode([
                'schema' => 'webman-aot-builder-source-inputs-v1',
                'paths' => array_values(array_filter(
                    array_column($sourceFiles, 'path'),
                    static fn(string $path): bool => explode('/', $path, 2)[0] !== 'build'
                )),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
        );
        return ['mappings' => [], 'mainFile' => $mainFile, 'entrypointMapping' => null];
    }

    /** @param list<string> $paths */
    private function manifest(string $mirror, array $paths, string $outputName, ?string $entry): void
    {
        $projectFile = $mirror . '/project.linux.yml';
        if (!is_file($projectFile) && is_file($mirror . '/project.yml')) {
            $this->write($projectFile, $this->read($mirror . '/project.yml'));
        }
        if (!is_file($projectFile)) {
            $this->write(
                $projectFile,
                "name: {$outputName}\nsources:\nignore:\n"
                    . "output: build/{$outputName}\nmode: bin\noptimize: 2\njob: 4\ndebug: false\n"
            );
        }
        $project = $this->read($projectFile);
        $project = str_replace("\r\n", "\n", $project);
        if ($entry !== null) {
            $encodedEntry = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (preg_match('/^entry-source:/m', $project) === 1) {
                $project = (string) preg_replace('/^entry-source:[^\n]*$/m', 'entry-source: ' . $encodedEntry, $project);
            } else {
                $project .= "\nentry-source: {$encodedEntry}\n";
            }
        }
        $project = (string) preg_replace('/^output:[^\r\n]*$/m', "output: build/{$outputName}", $project);
        $project = (string) preg_replace('/^mode:[^\r\n]*$/m', 'mode: bin', $project);
        if (preg_match('/^output:/m', $project) !== 1) {
            $project .= "\noutput: build/{$outputName}\n";
        }
        if (preg_match('/^mode:/m', $project) !== 1) {
            $project .= "\nmode: bin\n";
        }
        $this->write($projectFile, $project);
        (new PluginSourceCompletion())->apply($mirror, []);
    }

    private function read(string $path): string
    {
        $contents = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($contents)) {
            throw new ConfigurationException("PHP generator input missing or unsafe: {$path}");
        }
        return $contents;
    }

    private function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (is_link($path) || is_link($directory)
            || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))
            || file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)
        ) {
            throw new ConfigurationException("cannot write PHP generator output: {$path}");
        }
    }
}
