<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class DistributionPublisher
{
    /** @var \Closure(string,string):bool */
    private readonly \Closure $move;

    /**
     * @param (\Closure(string,string):bool)|null $move
     */
    public function __construct(
        private readonly string $projectDirectory,
        ?\Closure $move = null
    ) {
        $this->move = $move ?? static fn (string $from, string $to): bool => rename($from, $to);
    }

    /**
     * The verifier must inspect the complete candidate before the existing
     * distribution is touched. A prior distribution is retained for rollback.
     *
     * @param \Closure(string):void $verify
     * @return array{path:string,previous:?string}
     */
    public function publish(string $candidateDirectory, \Closure $verify): array
    {
        $project = realpath($this->projectDirectory);
        $candidate = realpath($candidateDirectory);
        $build = is_string($candidate) ? dirname($candidate) : false;
        $workspace = is_string($build) ? ProjectWorkspace::rootFromBuild($build, $project) : null;
        if (!is_string($project) || !is_string($candidate) || !is_string($workspace)
            || is_link($candidateDirectory)
            || preg_match('/^dist-candidate-[a-f0-9]{16}$/D', basename($candidate)) !== 1
        ) { throw new ConfigurationException('distribution candidate is outside the owned build workspace'); }
        $this->assertMarked($candidate);
        $destination = dirname($workspace) === $project && is_writable($project) ? $project . '/dist-aot' : $workspace . '/dist-aot';
        if ((file_exists($destination) || is_link($destination)) && !$this->marked($destination)) {
            $destination = $workspace . '/dist-aot-' . bin2hex(random_bytes(8));
        }
        if (is_link($destination) || (file_exists($destination) && !is_dir($destination))) {
            throw new ConfigurationException('existing distribution path is unsafe');
        }
        if (is_dir($destination)) {
            $this->assertMarked($destination);
        }

        $verify($candidate);

        if (!is_dir($destination)) {
            if (!($this->move)($candidate, $destination)) {
                throw new ConfigurationException('unable to publish verified distribution');
            }
            return ['path' => $destination, 'previous' => null];
        }

        $releases = $workspace . '/releases';
        if (is_link($releases)
            || (!is_dir($releases) && !mkdir($releases, 0700))
        ) {
            throw new ConfigurationException('distribution rollback directory is unsafe');
        }
        $previous = $releases . '/previous-' . bin2hex(random_bytes(8));
        if (!($this->move)($destination, $previous)) {
            throw new ConfigurationException('unable to preserve previous distribution');
        }
        try {
            if (!($this->move)($candidate, $destination)) {
                throw new ConfigurationException('unable to publish verified distribution');
            }
        } catch (\Throwable $failure) {
            if (!($this->move)($previous, $destination)) {
                throw new ConfigurationException(
                    "distribution rollback requires recovery from {$previous}",
                    previous: $failure
                );
            }
            throw $failure;
        }

        return ['path' => $destination, 'previous' => $previous];
    }

    private function marked(string $directory): bool
    {
        if (is_link($directory) || !is_dir($directory)) { return false; }
        try { $this->assertMarked($directory); }
        catch (ConfigurationException) { return false; }
        $manifest = json_decode((string) file_get_contents($directory . '/manifest.json'), true);
        $files = $manifest['files'] ?? null;
        if (!is_array($files)) { return false; }
        foreach (['server', 'start.sh', 'stop.sh', 'coverage.json', 'resources.json'] as $required) {
            if (!isset($files[$required]) || !is_file($directory . '/' . $required) || is_link($directory . '/' . $required)) { return false; }
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
            if ($file->isLink()) { return false; }
            if (!$file->isFile() || $relative === 'manifest.json') { continue; }
            $digest = $files[$relative]['sha256'] ?? null;
            if (!is_string($digest) || hash_file('sha256', $file->getPathname()) !== $digest) { return false; }
        }
        return true;
    }

    private function assertMarked(string $directory): void
    {
        $path = $directory . '/manifest.json';
        $contents = is_file($path) && !is_link($path)
            ? file_get_contents($path)
            : false;
        if (!is_string($contents)) {
            throw new ConfigurationException('distribution manifest is missing or unsafe');
        }
        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $manifest = null;
        }
        if (!is_array($manifest)
            || ($manifest['schema'] ?? null) !== 'webman-aot-builder-distribution-v1'
        ) {
            throw new ConfigurationException('distribution manifest marker is invalid');
        }
    }
}
