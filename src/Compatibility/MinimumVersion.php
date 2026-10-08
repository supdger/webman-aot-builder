<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class MinimumVersion
{
    public function __construct(
        private readonly string $minimum
    ) {
        if ($this->normalizedRelease($minimum) === null) {
            throw new ConfigurationException('invalid compatibility rule minimum version');
        }
    }

    public function assertSupported(
        string $version,
        string $ruleId,
        string $dependency,
        string $sourcePath,
        int $hits
    ): void {
        if (($release = $this->normalizedRelease($version)) === null) {
            throw new ConfigurationException(
                "compatibility rule {$ruleId}: cannot establish {$dependency} minimum {$this->minimum} "
                . "from version {$version} in {$sourcePath}"
            );
        }
        if (version_compare($release, $this->normalizedRelease($this->minimum), '<')) {
            throw new ConfigurationException(
                "compatibility rule {$ruleId}: unsupported {$dependency} version {$version} "
                . "in {$sourcePath}, found {$hits} hits; minimum {$this->minimum}"
            );
        }
    }

    private function normalizedRelease(string $version): ?string
    {
        return preg_match('/^v?([0-9]+\.[0-9]+\.[0-9]+(?:\.[0-9]+)?(?:-[0-9A-Za-z.-]+)?)(?:\+[0-9A-Za-z.-]+)?$/D', $version, $match) === 1
            ? $match[1] : null;
    }
}
