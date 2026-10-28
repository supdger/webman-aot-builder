<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

final class DiscoveryResult
{
    /**
     * @param list<array{path:string,category:string,owner:string}> $files
     */
    public function __construct(
        private readonly array $files,
        private readonly ?array $compilerSources = null,
        private readonly array $sourceDiagnostics = []
    ) {
    }

    /**
     * @return list<array{path:string,category:string,owner:string}>
     */
    public function files(): array
    {
        return $this->files;
    }

    /** @return list<string> */
    public function compilerSources(): array
    {
        return $this->compilerSources ?? $this->paths(ProjectDiscovery::BUSINESS_PHP);
    }

    /** @return list<array{file:string,line:int,expression:string,candidates:list<string>}> */
    public function sourceDiagnostics(): array
    {
        return $this->sourceDiagnostics;
    }

    /**
     * @return list<string>
     */
    public function paths(string $category): array
    {
        $paths = [];
        foreach ($this->files as $file) {
            if ($file['category'] === $category) {
                $paths[] = $file['path'];
            }
        }

        return $paths;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->files as $file) {
            $counts[$file['category']] = ($counts[$file['category']] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * @return array{files:list<array{path:string,category:string,owner:string}>,counts:array<string,int>}
     */
    public function toArray(): array
    {
        return [
            'files' => $this->files,
            'counts' => $this->counts(),
            'compilerSources' => $this->compilerSources(),
            'sourceDiagnostics' => $this->sourceDiagnostics,
        ];
    }
}
