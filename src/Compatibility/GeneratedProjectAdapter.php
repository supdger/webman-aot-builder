<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class GeneratedProjectAdapter
{
    /** @param array<string,mixed> $lock @return array<string,string> */
    public function apply(string $mirrorDirectory, array $lock): array
    {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror) || is_link($mirrorDirectory)
            || !str_ends_with(str_replace('\\', '/', $mirror), '/.webman-aot-builder/build/project')
        ) {
            throw new ConfigurationException('generated adaptation requires an isolated project mirror');
        }
        $shadowFile = $mirror . '/.typephp/build/workerman-worker.php';
        if (!is_file($shadowFile)) {
            return [];
        }
        if (is_link($shadowFile)) {
            throw new ConfigurationException('Workerman generated shadow is unsafe');
        }
        $shadow = file_get_contents($shadowFile);
        if (!is_string($shadow)) {
            throw new ConfigurationException('Workerman generated shadow cannot be read');
        }
        $adapted = $shadow;
        foreach ([
            '$file = __DIR__ . "/../../$unique_prefix.pid";'
                => '$file = getcwd() . "/vendor/workerman/$unique_prefix.pid";',
            "array_map(round(...), sys_getloadavg(), [2, 2, 2])"
                => "array_map(round(...), call_user_func('sys_getloadavg'), [2, 2, 2])",
        ] as $before => $after) {
            if (substr_count($adapted, $before) === 1 && str_contains($adapted, 'class Worker')) {
                $adapted = str_replace($before, $after, $adapted);
            } elseif (str_contains($adapted, $before)) {
                fwrite(STDERR, "[adapt] skipped Workerman generated rule: ambiguous source shape\n");
            }
        }
        if ($adapted !== $shadow && file_put_contents($shadowFile, $adapted, LOCK_EX) !== strlen($adapted)) {
            throw new ConfigurationException('unable to write Workerman generated adaptation');
        }
        return ['workerShadowSha256' => hash('sha256', $adapted)];
    }
}
