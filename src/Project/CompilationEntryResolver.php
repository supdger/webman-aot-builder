<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class CompilationEntryResolver
{
    public function __construct(private readonly string $projectDirectory)
    {
    }

    public function resolve(?string $explicitEntry = null, ?string $projectManifest = null): ?string
    {
        if ($explicitEntry !== null) {
            return $this->path($explicitEntry);
        }
        $configured = [];
        foreach (explode("\n", $projectManifest ?? '') as $line) {
            if (preg_match('/^entry-source:[ \t]*(.*)$/D', $line, $match) === 1) {
                $value = trim($match[1]);
                if (str_starts_with($value, '"')) {
                    try {
                        $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                    } catch (\JsonException $exception) {
                        throw new ConfigurationException('invalid quoted entry-source: ' . $exception->getMessage());
                    }
                } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                    $value = str_replace("''", "'", substr($value, 1, -1));
                }
                if (!is_string($value) || $value === '') {
                    throw new ConfigurationException('entry-source requires a readable PHP script path');
                }
                $configured[] = $value;
            }
        }
        if (count($configured) > 1) {
            throw new ConfigurationException('duplicate project entry-source field');
        }
        if ($configured !== []) {
            return $this->path($configured[0]);
        }
        $startScript = $this->projectDirectory . '/start.php';
        if (is_file($startScript) && !is_link($startScript)) {
            return $this->path('start.php');
        }
        $composerFile = $this->projectDirectory . '/composer.json';
        if (!is_file($composerFile)) {
            return null;
        }
        try {
            $composer = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException('invalid Composer entry configuration: ' . $exception->getMessage());
        }
        if (!is_array($composer)) {
            throw new ConfigurationException('Composer entry configuration must be an object');
        }
        $bins = $composer['bin'] ?? [];
        if (is_string($bins)) {
            $bins = [$bins];
        }
        if (!is_array($bins) || !array_is_list($bins)) {
            throw new ConfigurationException('Composer bin must be a script path or a list of script paths');
        }
        $candidates = [];
        foreach ($bins as $bin) {
            if (!is_string($bin)) {
                throw new ConfigurationException('Composer bin contains an invalid script path');
            }
            $path = $this->path($bin);
            $source = file_get_contents($this->projectDirectory . '/' . $path);
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_OPEN_TAG) {
                    $candidates[$path] = true;
                    break;
                }
            }
        }
        if (count($candidates) > 1) {
            throw new ConfigurationException('Multiple Composer PHP entry scripts: '
                . implode(', ', array_keys($candidates))
                . '; choose one with webman-aot build --entry=<script>');
        }
        return array_key_first($candidates);
    }

    private function path(string $entry): string
    {
        $entry = str_replace('\\', '/', $entry);
        if ($entry === '' || preg_match('/[\x00-\x1f\x7f]/', $entry) === 1
            || in_array('..', explode('/', $entry), true)) {
            throw new ConfigurationException('entry-source requires a safe project script path');
        }
        $root = realpath($this->projectDirectory);
        $absolute = preg_match('~^(?:/|[A-Za-z]:/)~', $entry) === 1
            ? $entry : $this->projectDirectory . '/' . $entry;
        $resolved = realpath($absolute);
        if (!is_string($root) || !is_string($resolved) || !is_file($absolute)
            || !is_readable($absolute)
            || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $root) . '/')) {
            throw new ConfigurationException("entry-source is not a readable project file: {$entry}");
        }
        $relative = substr(str_replace('\\', '/', $resolved), strlen(str_replace('\\', '/', $root)) + 1);
        for ($component = $absolute; $component !== dirname($component); $component = dirname($component)) {
            if (is_link($component)) {
                throw new ConfigurationException("entry-source refuses a symlink: {$entry}");
            }
            if ($component === $root) {
                break;
            }
        }
        return $relative;
    }
}
