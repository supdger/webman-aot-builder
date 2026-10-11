<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class ProfileDetector
{
    public function __construct(private readonly string $projectDirectory)
    {
    }

    public function detect(?string $explicitProfile = null): ProjectProfile
    {
        if ($explicitProfile !== null
            && !in_array($explicitProfile, [ProjectProfile::PHP, ProjectProfile::WEBMAN, ProjectProfile::SAIADMIN], true)
        ) {
            throw new ConfigurationException("unknown project profile: {$explicitProfile}");
        }
        if (!is_dir($this->projectDirectory)) {
            throw new ConfigurationException('unable to read project source directory');
        }

        $packages = [];
        $evidence = [];
        $lockFile = rtrim($this->projectDirectory, '/\\') . '/composer.lock';
        if (is_file($lockFile) && is_readable($lockFile)) {
            $contents = @file_get_contents($lockFile);
            if (!is_string($contents)) {
                RuntimeDataPaths::reportUnreadableData($this->projectDirectory, ['composer.lock']);
            }
            $lock = is_string($contents) ? json_decode($contents, true) : null;
            if (is_array($lock)) {
                foreach (['packages', 'packages-dev'] as $section) {
                    if (!is_array($lock[$section] ?? null)) {
                        continue;
                    }
                    foreach ($lock[$section] as $package) {
                        if (is_array($package)
                            && is_string($package['name'] ?? null)
                            && is_string($package['version'] ?? null)
                        ) {
                            $packages[$package['name']] = $package['version'];
                        }
                    }
                }
                $evidence[] = 'composer.lock';
            }
        }
        if (is_file($lockFile) && !is_readable($lockFile)) {
            RuntimeDataPaths::reportUnreadableData($this->projectDirectory, ['composer.lock']);
        }
        $frameworkRoot = 'vendor/workerman/webman-framework/src/support/';
        $profile = ProjectProfile::PHP;
        foreach ([$frameworkRoot . 'bootstrap.php', $frameworkRoot . 'App.php'] as $capability) {
            $capabilityFile = rtrim($this->projectDirectory, '/\\') . '/' . $capability;
            if (is_file($capabilityFile)) {
                $profile = ProjectProfile::WEBMAN;
                $evidence[] = $capability;
            }
        }
        if ($profile === ProjectProfile::WEBMAN
            && is_file(rtrim($this->projectDirectory, '/\\') . '/support/bootstrap.php')
        ) {
            $evidence[] = 'support/bootstrap.php';
        }

        // Legacy profile options select the same source-driven PHP compilation path.
        return new ProjectProfile($profile, $packages, $evidence);
    }
}
