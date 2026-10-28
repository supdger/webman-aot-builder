<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

final class CoverageLedger
{
    public const COMPILED_DIRECT = 'compiled-direct';
    public const COMPILED_SHADOW = 'compiled-shadow';
    public const RUNTIME_APPROVED = 'runtime-approved';
    public const INSTALL_ONLY = 'install-only';
    public const SOURCE_NOT_SELECTED = 'source-not-selected';

    /**
     * @param list<array<string, string|null>> $files
     */
    public function __construct(private readonly array $files, private readonly array $sourceDiagnostics = [])
    {
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->files as $file) {
            $status = (string) $file['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * @return array{schema:string,counts:array<string,int>,files:list<array<string,string|null>>}
     */
    public function toArray(): array
    {
        return [
            'schema' => 'webman-aot-builder-coverage-ledger-v1',
            'counts' => $this->counts(),
            'files' => $this->files,
            'sourceDiagnostics' => $this->sourceDiagnostics,
        ];
    }
}
