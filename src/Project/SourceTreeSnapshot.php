<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class SourceTreeSnapshot
{
    private const EXCLUDED_PATHS = [
        '.git',
        '.typephp/build',
        '.webman-aot-builder/build',
        '.webman-aot-builder/cache',
        '.webman-aot-builder/runs',
        '.webman-aot-builder/releases',
        '.webman-aot-builder/build.lock',
        '.webman-aot-builder/workspace.json',
    ];

    public function __construct(private readonly string $projectDirectory)
    {
    }

    /**
     * @return array{sha256:string,files:int}
     */
    public function capture(): array
    {
        $snapshot = $this->captureWithFiles();

        return ['sha256' => $snapshot['sha256'], 'files' => $snapshot['files']];
    }

    /**
     * @return array{sha256:string,files:int,digests:array<string,string>,identities:array<string,string>,links:array<string,string>,cycles:array<string,string>}
     */
    public function captureWithFiles(): array
    {
        $inputs = RuntimeDataPaths::sourceFiles($this->projectDirectory, self::EXCLUDED_PATHS);
        $files = [];
        $identities = [];
        $unreadable = [];
        foreach ($inputs['files'] as $relative => $input) {
            $digest = @hash_file('sha256', $input['absolute']);
            if (!is_string($digest)) {
                if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'php') {
                    throw new ConfigurationException("unable to hash project PHP source: {$relative}");
                }
                $unreadable[] = $relative;
                continue;
            }
            $files[$relative] = $digest;
            $identities[$relative] = $input['identity'];
        }
        RuntimeDataPaths::reportUnreadableData($this->projectDirectory, $unreadable);
        ksort($files, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($files as $path => $digest) {
            hash_update($context, $path . "\0" . $digest . "\n");
        }

        foreach ($inputs['links'] as $path => $identity) {
            hash_update($context, "link\0" . $path . "\0" . $identity . "\n");
        }
        foreach ($inputs['cycles'] as $path => $ancestor) {
            hash_update($context, "cycle\0" . $path . "\0" . $ancestor . "\n");
        }

        return [
            'sha256' => hash_final($context),
            'files' => count($files),
            'digests' => $files,
            'identities' => $identities,
            'links' => $inputs['links'],
            'cycles' => $inputs['cycles'],
        ];
    }

}
