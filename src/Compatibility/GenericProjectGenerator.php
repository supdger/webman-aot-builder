<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectDiscovery;
use WebmanAotBuilder\Project\ProjectProfile;

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
        array $targetCapabilities = []
    ): array {
        $resolved = realpath($mirror);
        if (!is_string($resolved)
            || str_replace('\\', '/', $resolved) !== str_replace('\\', '/', $mirror)
            || is_link($mirror)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
            || is_link($mirror . '/.typephp') || is_link($mirror . '/.typephp/build')
        ) {
            throw new ConfigurationException('PHP generation requires an isolated project mirror');
        }
        $mirror = $resolved;
        $build = $mirror . '/.typephp/build';
        if (!is_dir($build) && !mkdir($build, 0700, true) && !is_dir($build)) {
            throw new ConfigurationException('cannot create PHP generation directory');
        }
        $profile = new ProjectProfile(ProjectProfile::PHP, [], []);
        $sourceFiles = (new ProjectDiscovery($mirror))->discover($profile, false)->files();
        $paths = array_column(array_filter(
            $sourceFiles,
            static fn(array $file): bool => $file['category'] === ProjectDiscovery::BUSINESS_PHP
        ), 'path');
        $mainFile = in_array('main.php', $paths, true) ? 'main.php' : ($paths[0] ?? null);
        $this->write($build . '/entrypoint-mapping.json', "null\n");
        $this->manifest($mirror, $outputName);
        $this->write(
            $build . '/source-inputs.json',
            json_encode([
                'schema' => 'webman-aot-builder-source-inputs-v1',
                'paths' => array_column($sourceFiles, 'path'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
        );
        return ['mappings' => [], 'mainFile' => $mainFile, 'entrypointMapping' => null];
    }

    private function manifest(string $mirror, string $outputName): void
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
        $project = (string) preg_replace('/^output:[^\r\n]*$/m', "output: build/{$outputName}", $project);
        $project = (string) preg_replace('/^mode:[^\r\n]*$/m', 'mode: bin', $project);
        if (preg_match('/^output:/m', $project) !== 1) {
            $project .= "\noutput: build/{$outputName}\n";
        }
        if (preg_match('/^mode:/m', $project) !== 1) {
            $project .= "\nmode: bin\n";
        }
        $this->write($projectFile, $project);
        (new PluginSourceCompletion())->apply($mirror, ProjectProfile::PHP, []);
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
