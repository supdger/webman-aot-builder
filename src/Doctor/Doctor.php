<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Doctor;

use WebmanAotBuilder\Toolchain\LockValidator;
use WebmanAotBuilder\Toolchain\HostComponentSelector;
use WebmanAotBuilder\Toolchain\NativeDownloader;
use WebmanAotBuilder\Toolchain\MinimalComponent;
use WebmanAotBuilder\Toolchain\ToolchainPreparer;

final class Doctor
{
    public const MINIMUM_FREE_BYTES = 10 * 1024 * 1024 * 1024;

    public function __construct(
        private readonly string $lockPath,
        private readonly string $artifactsDirectory,
        private readonly SystemProbe $system,
        private readonly int $minimumFreeBytes = self::MINIMUM_FREE_BYTES,
        private readonly ?ToolchainPreparer $preparer = null,
        private readonly ?string $generation = null,
        private readonly ?array $minimalComponent = null
    ) {
    }

    public function inspect(): DoctorReport
    {
        $checks = [];
        $host = $this->system->hostId();
        $supported = in_array($host, ['macos-arm64', 'windows-x86_64'], true);
        $checks[] = $this->check(
            'platform',
            $supported,
            $supported ? "supported build host {$host}" : "unsupported build host {$host}",
            ['host' => $host]
        );

        $freeBytes = $this->system->freeBytes($this->artifactsDirectory);
        $checks[] = $this->check(
            'disk',
            true,
            $freeBytes >= $this->minimumFreeBytes
                ? 'sufficient free space'
                : 'free space is below the recommendation; actual output writes determine build availability',
            [
                'freeBytes' => $freeBytes,
                'recommendedBytes' => $this->minimumFreeBytes,
                'advisory' => true,
            ]
        );

        $lock = $this->readLock($checks);
        if ($lock !== null) {
            if ($this->minimalComponent !== null) {
                $this->inspectMinimalComponent($host, $checks);
            } else {
                $this->inspectArtifacts($lock, $host, $checks);
                $this->inspectNetwork($lock, $host, $checks);
            }
        }
        $this->inspectPrepared($checks);

        return new DoctorReport($host, $checks);
    }

    /**
     * @param list<array{id:string,status:string,message:string,details:array<string,mixed>}> $checks
     */
    private function inspectMinimalComponent(string $host, array &$checks): void
    {
        if ($this->generation === null
            || !is_file($this->generation . '/minimal-component.json')
        ) {
            $checks[] = $this->check(
                'minimal-component',
                false,
                'minimal toolchain component is not installed; preparation is required'
            );
            return;
        }
        try {
            (new MinimalComponent())->verifyGeneration(
                $this->generation,
                $host,
                $this->minimalComponent['manifestSha256'],
                $this->minimalComponent['toolchainLockSha256']
            );
            $checks[] = $this->check(
                'minimal-component',
                true,
                'installed minimal toolchain files match the locked manifest'
            );
            $checks[] = $this->check(
                'network',
                true,
                'network is not required; the minimal toolchain is installed'
            );
        } catch (\Throwable $exception) {
            $checks[] = $this->check(
                'minimal-component',
                false,
                'installed minimal toolchain is incomplete or damaged',
                ['error' => $exception->getMessage()]
            );
        }
    }

    /**
     * @param list<array{id:string,status:string,message:string,details:array<string,mixed>}> $checks
     */
    private function inspectPrepared(array &$checks): void
    {
        if ($this->preparer === null) {
            return;
        }
        if ($this->generation === null) {
            $checks[] = $this->check(
                'prepared-toolchain',
                false,
                'private compiler tools are not prepared'
            );
            return;
        }
        try {
            $this->preparer->assertReady($this->generation);
            $checks[] = $this->check(
                'prepared-toolchain',
                true,
                'private compiler tools and static SDK are ready'
            );
        } catch (\Throwable $exception) {
            $checks[] = $this->check(
                'prepared-toolchain',
                false,
                'private compiler tools are incomplete',
                ['error' => $exception->getMessage()]
            );
        }
    }

    /**
     * @param list<array{id:string,status:string,message:string,details:array<string,mixed>}> $checks
     * @return array<string, mixed>|null
     */
    private function readLock(array &$checks): ?array
    {
        $contents = @file_get_contents($this->lockPath);
        if (!is_string($contents)) {
            $checks[] = $this->check(
                'toolchain-lock',
                false,
                'toolchain lock is missing',
                ['path' => basename($this->lockPath)]
            );
            return null;
        }
        try {
            $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $checks[] = $this->check(
                'toolchain-lock',
                false,
                'toolchain lock is not valid JSON',
                ['error' => $exception->getMessage()]
            );
            return null;
        }
        if (!is_array($lock)) {
            $checks[] = $this->check('toolchain-lock', false, 'toolchain lock root is invalid');
            return null;
        }
        $errors = (new LockValidator())->validate($lock);
        $checks[] = $this->check(
            'toolchain-lock',
            $errors === [],
            $errors === [] ? 'toolchain lock is valid' : 'toolchain lock validation failed',
            ['errors' => $errors]
        );

        return $errors === [] ? $lock : null;
    }

    /**
     * @param array<string, mixed> $lock
     * @param list<array{id:string,status:string,message:string,details:array<string,mixed>}> $checks
     */
    private function inspectNetwork(array $lock, string $host, array &$checks): void
    {
        $missingArtifact = false;
        foreach ($checks as $check) {
            if (str_starts_with($check['id'], 'component:')
                && $check['status'] !== 'ok'
            ) {
                $missingArtifact = true;
                break;
            }
        }
        if (!$missingArtifact) {
            $checks[] = $this->check(
                'network',
                true,
                'network is not required; locked build artifacts are cached and verified',
                ['checkedHosts' => [], 'unreachableHosts' => []]
            );
            return;
        }
        $urls = [];
        $components = (new HostComponentSelector())->select(
            is_array($lock['components'] ?? null) ? $lock['components'] : [],
            $host
        );
        foreach ($components as $component) {
            if (is_array($component) && is_string($component['sourceUrl'] ?? null)) {
                $url = NativeDownloader::sourceDownloadUrl($component['sourceUrl']);
                $host = parse_url($url, PHP_URL_HOST);
                if (is_string($host) && !isset($urls[$host])) {
                    $urls[$host] = $url;
                }
            }
        }
        ksort($urls, SORT_STRING);
        $unreachable = [];
        foreach ($urls as $host => $url) {
            if (!$this->system->canReach($url)) {
                $unreachable[] = $host;
            }
        }
        $checks[] = $this->check(
            'network-tcp',
            $unreachable === [],
            $unreachable === []
                ? 'source host TCP ports respond; archive redirects and downloads are not verified'
                : 'one or more source host TCP ports are unreachable',
            [
                'checkedHosts' => array_keys($urls),
                'unreachableHosts' => $unreachable,
            ]
        );
    }

    /**
     * @param array<string, mixed> $lock
     * @param list<array{id:string,status:string,message:string,details:array<string,mixed>}> $checks
     */
    private function inspectArtifacts(array $lock, string $host, array &$checks): void
    {
        $components = (new HostComponentSelector())->select(
            is_array($lock['components'] ?? null) ? $lock['components'] : [],
            $host
        );
        foreach ($components as $component) {
            $id = (string) $component['id'];
            $urlPath = parse_url((string) $component['sourceUrl'], PHP_URL_PATH);
            $filename = is_string($urlPath) ? basename($urlPath) : '';
            $path = $this->artifactsDirectory . '/' . $filename;
            if (!is_file($path)) {
                $checks[] = $this->check(
                    'component:' . $id,
                    false,
                    'locked component is missing',
                    [
                        'version' => (string) $component['version'],
                        'artifact' => $filename,
                        'expectedSha256' => (string) $component['sha256'],
                    ]
                );
                continue;
            }
            $actual = hash_file('sha256', $path);
            $valid = is_string($actual) && hash_equals((string) $component['sha256'], $actual);
            $checks[] = $this->check(
                'component:' . $id,
                $valid,
                $valid ? 'locked component digest matches' : 'locked component digest mismatch',
                [
                    'version' => (string) $component['version'],
                    'artifact' => $filename,
                    'expectedSha256' => (string) $component['sha256'],
                    'actualSha256' => is_string($actual) ? $actual : null,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $details
     * @return array{id:string,status:string,message:string,details:array<string,mixed>}
     */
    private function check(
        string $id,
        bool $healthy,
        string $message,
        array $details = []
    ): array {
        return [
            'id' => $id,
            'status' => $healthy ? 'ok' : 'error',
            'message' => $message,
            'details' => $details,
        ];
    }
}
