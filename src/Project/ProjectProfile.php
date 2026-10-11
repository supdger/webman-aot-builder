<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

final class ProjectProfile
{
    public const PHP = 'php';
    public const WEBMAN = 'webman';
    public const SAIADMIN = 'saiadmin';

    /**
     * @param array<string, string> $packages
     * @param list<string> $evidence
     */
    public function __construct(
        private readonly string $name,
        private readonly array $packages,
        private readonly array $evidence
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    public function packages(): array
    {
        return $this->packages;
    }

    /**
     * @return list<string>
     */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /**
     * @return array{name:string,packages:array<string,string>,evidence:list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'packages' => $this->packages,
            'evidence' => $this->evidence,
        ];
    }
}
