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
        if (!is_dir($this->projectDirectory) || is_link($this->projectDirectory)) {
            throw new ConfigurationException('project profile requires a real project directory');
        }

        return new ProjectProfile(ProjectProfile::PHP, [], []);
    }
}
