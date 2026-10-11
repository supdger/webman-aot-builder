<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class RuntimeLauncherWriter
{
    /**
     * @return array{start:string,stop:string}
     */
    public function write(string $candidateDirectory): array
    {
        $candidate = realpath($candidateDirectory);
        if (!is_string($candidate)
            || $candidate === DIRECTORY_SEPARATOR
            || !is_dir($candidate)
            || is_link($candidateDirectory)
        ) {
            throw new ConfigurationException('runtime launchers require a concrete candidate directory');
        }
        $scripts = [
            'start.sh' => <<<'SHELL'
#!/bin/sh
set -eu
ROOT=$(CDPATH= cd "$(dirname "$0")" && pwd -P)
cd "$ROOT"
if [ ! -x ./server ]; then
    echo "Incomplete Webman AOT Builder distribution" >&2
    exit 1
fi
if [ "$#" -eq 0 ]; then
    exec ./server start
fi
if [ "$#" -eq 1 ] && [ "$1" = "--daemon" ]; then
    exec ./server start -d
fi
echo "Usage: ./start.sh [--daemon]" >&2
exit 2
SHELL,
            'stop.sh' => <<<'SHELL'
#!/bin/sh
set -eu
ROOT=$(CDPATH= cd "$(dirname "$0")" && pwd -P)
cd "$ROOT"
if [ "$#" -ne 0 ] || [ ! -x ./server ]; then
    echo "Usage: ./stop.sh" >&2
    exit 2
fi
exec ./server stop
SHELL,
        ];
        $written = [];
        foreach ($scripts as $name => $contents) {
            $path = $candidate . '/' . $name;
            if (file_exists($path) || is_link($path)
                || file_put_contents($path, $contents . "\n") === false
                || !chmod($path, 0755)
            ) {
                throw new ConfigurationException("runtime launcher cannot be written safely: {$name}");
            }
            $written[$name === 'start.sh' ? 'start' : 'stop'] = $path;
        }
        return $written;
    }
}
