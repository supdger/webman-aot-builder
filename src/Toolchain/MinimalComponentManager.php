<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Cli\UnavailableException;
use WebmanAotBuilder\Platform\UserDirectoryLayout;

final class MinimalComponentManager
{
    /**
     * @param array{archive:string,sha256:string,manifestSha256:string,toolchainLockSha256:string,url:string} $component
     */
    public function __construct(
        private readonly UserDirectoryLayout $layout,
        private readonly string $host,
        private readonly array $component,
        private readonly ToolchainPreparer $preparer,
        private readonly Downloader $downloader
    ) {
    }

    /**
     * @param \Closure(string):void|null $progress
     * @param \Closure(int,?int):void|null $downloadProgress
     */
    public function ensure(
        ?string $bundledArchive = null,
        ?\Closure $progress = null,
        ?\Closure $downloadProgress = null
    ): string {
        $toolchains = $this->layout->path('toolchains');
        foreach (['candidates', 'versions', 'downloads'] as $directory) {
            $path = $toolchains . '/' . $directory;
            if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path))) {
                throw new ConfigurationException("private toolchain {$directory} directory is unsafe");
            }
        }
        $lock = fopen($toolchains . '/repair.lock', 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            throw new ConfigurationException('cannot acquire private toolchain preparation lock');
        }
        try {
            $active = (new ToolchainLocator($this->layout))->activeGeneration($this->host);
            if ($active !== null) {
                if (is_file($active . '/minimal-component.json')) {
                    try {
                        (new MinimalComponent())->verifyGeneration(
                            $active,
                            $this->host,
                            $this->component['manifestSha256'],
                            $this->component['toolchainLockSha256']
                        );
                        $this->preparer->assertReady($active);
                        $progress?->__invoke('Existing minimal toolchain verified; no download needed.');
                        return $active;
                    } catch (\Throwable) {
                        $progress?->__invoke('Existing minimal toolchain is damaged; preparing a fresh generation.');
                    }
                } else {
                    $progress?->__invoke(
                        'An older full toolchain is installed; preparing this version of the minimal component.'
                    );
                }
            }
            $archive = $bundledArchive ?? $toolchains . '/downloads/' . $this->component['archive'];
            if ($bundledArchive === null && !$this->validArchive($archive)) {
                $partial = $archive . '.partial';
                $progress?->__invoke(
                    is_file($partial)
                        ? 'Resuming the locked minimal toolchain component download...'
                        : 'Downloading the locked minimal toolchain component...'
                );
                $this->downloader->download(
                    $this->component['url'],
                    $partial,
                    static function (int $bytes, ?int $total = null) use ($downloadProgress): void {
                        $downloadProgress?->__invoke($bytes, $total);
                    },
                    static function (string $message) use ($progress): void {
                        $progress?->__invoke($message);
                    }
                );
                if (!$this->validArchive($partial)) {
                    if (is_file($partial) && !unlink($partial)) {
                        throw new ConfigurationException('cannot discard corrupt component download');
                    }
                    throw new UnavailableException('downloaded minimal component failed SHA-256 verification');
                }
                if (is_file($archive) && !unlink($archive)) {
                    throw new ConfigurationException('cannot replace damaged component cache');
                }
                if (!rename($partial, $archive)) {
                    throw new ConfigurationException('cannot store verified minimal component download');
                }
            }
            $candidate = $toolchains . '/candidates/minimal-' . bin2hex(random_bytes(8));
            try {
                (new MinimalComponent())->extract(
                    $archive,
                    $this->component['sha256'],
                    $candidate,
                    $this->host,
                    $this->component['toolchainLockSha256'],
                    $progress
                );
                $this->preparer->assertReady($candidate);
                $destination = $toolchains . '/versions/'
                    . gmdate('YmdHis') . '-minimal-' . bin2hex(random_bytes(4));
                if (!rename($candidate, $destination)) {
                    throw new \RuntimeException('cannot activate verified minimal toolchain');
                }
                try {
                    $this->preparer->assertReady($destination);
                } catch (\Throwable $exception) {
                    if (!rename($destination, $candidate)) {
                        throw new \RuntimeException(
                            'minimal toolchain activation failed and rollback was unsuccessful',
                            previous: $exception
                        );
                    }
                    throw $exception;
                }
                $progress?->__invoke('Minimal toolchain activated and ready.');
                return $destination;
            } catch (\Throwable $exception) {
                $this->removeCandidate($candidate);
                throw $exception;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array{generation:string,manifestSha256:string,toolchainLockSha256:string} $previous */
    public function reuse(array $previous, string $manifest, string $replacements, ?\Closure $progress = null): string
    {
        $toolchains = $this->layout->path('toolchains');
        foreach (['candidates', 'versions'] as $directory) {
            $path = $toolchains . '/' . $directory;
            if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path))) {
                throw new ConfigurationException('private toolchain upgrade directory is unsafe');
            }
        }
        $lock = fopen($toolchains . '/repair.lock', 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            throw new ConfigurationException('cannot acquire private toolchain preparation lock');
        }
        $candidate = $toolchains . '/candidates/minimal-' . bin2hex(random_bytes(8));
        try {
            (new MinimalComponent())->reuse(
                $previous['generation'], $previous['manifestSha256'], $previous['toolchainLockSha256'],
                $manifest, $replacements, $candidate, $this->host,
                $this->component['manifestSha256'], $this->component['toolchainLockSha256'], $progress
            );
            $this->preparer->assertReady($candidate);
            $destination = $toolchains . '/versions/' . gmdate('YmdHis') . '-minimal-' . bin2hex(random_bytes(4));
            if (!rename($candidate, $destination)) {
                throw new ConfigurationException('cannot store verified reused toolchain');
            }
            try { $this->preparer->assertReady($destination); }
            catch (\Throwable $error) {
                if (!rename($destination, $candidate)) {
                    throw new ConfigurationException('reused toolchain rollback failed', previous: $error);
                }
                throw $error;
            }
            $progress?->__invoke('Existing component files and bundled replacements verified; no component download needed.');
            return $destination;
        } catch (\Throwable $error) {
            $this->removeCandidate($candidate);
            throw $error;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function validArchive(string $archive): bool
    {
        $actual = is_file($archive) && !is_link($archive)
            ? hash_file('sha256', $archive)
            : false;
        return is_string($actual) && hash_equals($this->component['sha256'], $actual);
    }

    private function removeCandidate(string $candidate): void
    {
        if (!is_dir($candidate) || is_link($candidate)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($candidate, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($candidate);
    }
}
