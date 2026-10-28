<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

final class SdkArchiveGuard
{
    private const LIBPHP_SHA256 = 'edef07bd8e532334e02061481b4bbbd70210cd8505fe90d6bec155ea04133003';
    private const LIBPHPX_SHA256 = 'afb819aab33837f8a7e8a69e0fb5a6a16029ee6fb4ca28ed79ef5b41fb6fac74';
    private const STRIPPED_LIBPHP_SHA256 = '182909b33512b88e22ebc67d79650f67d48003b8c8b5bbfb64a7cf7481806e15';
    private const STRIPPED_LIBPHPX_SHA256 = '6060d1aae72b2c3f5b34e3401419e1629ece33e946218e503095f15ecb4c72ae';
    private const ALLOWED_DUPLICATE = '__get_connection';
    private const ALLOWED_DUPLICATE_COUNT = 2;

    /**
     * @return array{sdkVariant: string, libphpSha256: string, libphpxSha256: string, duplicateSymbol: string, definitions: int}
     */
    public function inspect(string $sdkDirectory, string $llvmNm): array
    {
        $libphp = rtrim($sdkDirectory, '/\\') . '/lib/libphp.a';
        $libphpx = rtrim($sdkDirectory, '/\\') . '/lib/libphpx.a';

        $libphpHash = is_file($libphp) && !is_link($libphp) ? hash_file('sha256', $libphp) : false;
        $libphpxHash = is_file($libphpx) && !is_link($libphpx) ? hash_file('sha256', $libphpx) : false;
        $pairs = [
            'locked-original' => ['libphp.a' => self::LIBPHP_SHA256, 'libphpx.a' => self::LIBPHPX_SHA256],
            'locked-debug-stripped' => ['libphp.a' => self::STRIPPED_LIBPHP_SHA256, 'libphpx.a' => self::STRIPPED_LIBPHPX_SHA256],
        ];
        $lock = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/toolchain.lock.json'), true, flags: JSON_THROW_ON_ERROR);
        $patched = $lock['evidence']['patchedSdk'] ?? null;
        if ($patched !== null) {
            if (!is_array($patched)) { throw new \RuntimeException('patched SDK approval is malformed'); }
            $this->assertDerivation($sdkDirectory, $patched);
            $pairs = [
                'locked-derived-original' => $patched['librariesBeforeStripping'] ?? [],
                'locked-derived-stripped' => $patched['libraries'] ?? [],
            ];
        }
        $variant = null;
        foreach ($pairs as $name => $pair) {
            foreach (['libphp.a', 'libphpx.a'] as $library) {
                if (!is_string($pair[$library] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $pair[$library]) !== 1) {
                    throw new \RuntimeException('approved SDK archive pair is malformed');
                }
            }
            if ($libphpHash === $pair['libphp.a'] && $libphpxHash === $pair['libphpx.a']) { $variant = $name; break; }
        }
        if ($variant === null) { throw new \RuntimeException('SDK archives differ from an approved original/stripped pair'); }
        $definitions = $this->countSymbolDefinitions($llvmNm, $libphp, self::ALLOWED_DUPLICATE);
        if ($definitions !== self::ALLOWED_DUPLICATE_COUNT) {
            throw new \RuntimeException(
                sprintf(
                    'locked SDK must contain exactly %d %s definitions, found %d',
                    self::ALLOWED_DUPLICATE_COUNT,
                    self::ALLOWED_DUPLICATE,
                    $definitions
                )
            );
        }

        return [
            'sdkVariant' => $variant,
            'libphpSha256' => $libphpHash,
            'libphpxSha256' => $libphpxHash,
            'duplicateSymbol' => self::ALLOWED_DUPLICATE,
            'definitions' => $definitions,
        ];
    }

    /** @param array<string,mixed> $policy */
    public function assertDerivation(string $sdk, array $policy): void
    {
        $file = rtrim($sdk, '/\\') . '/builder-derivation.json';
        $digest = $policy['derivationSha256'] ?? null;
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || !is_file($file) || is_link($file) || hash_file('sha256', $file) !== $digest) {
            throw new \RuntimeException('patched SDK derivation differs from the approved input');
        }
        $derivation = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($derivation) || ($derivation['schema'] ?? null) !== 'webman-aot-builder-derived-sdk-v1'
            || ($derivation['sdkSha256'] ?? null) !== ($policy['sdkSha256'] ?? null)
            || !is_array($derivation['headers'] ?? null) || $derivation['headers'] === []) {
            throw new \RuntimeException('patched SDK derivation shape differs');
        }
        $headersDigest = $derivation['allHeadersSha256'] ?? null;
        if (!is_string($headersDigest) || preg_match('/^[a-f0-9]{64}$/D', $headersDigest) !== 1
            || $this->headerTreeDigest(rtrim($sdk, '/\\') . '/include') !== $headersDigest) {
            throw new \RuntimeException('patched SDK complete header tree drifted');
        }
        $publicHeaders = [];
        $headerRoot = rtrim($sdk, '/\\') . '/include/phpx';
        if (!is_dir($headerRoot) || is_link($headerRoot)) { throw new \RuntimeException('patched SDK public headers are missing'); }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($headerRoot, \FilesystemIterator::SKIP_DOTS)) as $header) {
            if ($header->isLink()) { throw new \RuntimeException('patched SDK public header is a link'); }
            if ($header->isFile()) { $publicHeaders[str_replace('\\', '/', substr($header->getPathname(), strlen(rtrim($sdk, '/\\')) + 1))] = hash_file('sha256', $header->getPathname()); }
        }
        ksort($publicHeaders, SORT_STRING);
        $expectedHeaders = $derivation['headers']; ksort($expectedHeaders, SORT_STRING);
        if ($publicHeaders !== $expectedHeaders) { throw new \RuntimeException('patched SDK public header tree drifted'); }
        foreach ($derivation['headers'] as $relative => $sha256) {
            if (!is_string($relative) || preg_match('~^include/phpx/[A-Za-z0-9._/-]+$~D', $relative) !== 1
                || in_array('..', explode('/', $relative), true)
                || !is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
                throw new \RuntimeException('patched SDK header manifest is invalid');
            }
            $header = rtrim($sdk, '/\\') . '/' . $relative;
            if (!is_file($header) || is_link($header) || hash_file('sha256', $header) !== $sha256) {
                throw new \RuntimeException('patched SDK public header drifted: ' . $relative);
            }
        }
    }

    private function headerTreeDigest(string $root): string
    {
        $resolvedRoot = !is_link($root) ? realpath($root) : false;
        if (!is_string($resolvedRoot) || !is_dir($resolvedRoot)) { throw new \RuntimeException('patched SDK headers are missing'); }
        $root = $resolvedRoot;
        $entries = [];
        $visit = static function (string $directory, string $prefix) use (&$visit, &$entries, $root): void {
            foreach (new \DirectoryIterator($directory) as $entry) {
                if ($entry->isDot()) { continue; }
                $path = $prefix . $entry->getFilename();
                if ($entry->isLink()) {
                    $target = readlink($entry->getPathname());
                    $resolved = realpath($entry->getPathname());
                    if (!is_string($target) || str_starts_with($target, '/') || !is_string($resolved)
                        || !str_starts_with(str_replace(DIRECTORY_SEPARATOR, '/', $resolved), str_replace(DIRECTORY_SEPARATOR, '/', $root) . '/')) {
                        throw new \RuntimeException('patched SDK header link is unsafe: ' . $path);
                    }
                    $entries[$path] = ['type' => 'link', 'target' => $target];
                } elseif ($entry->isDir()) { $visit($entry->getPathname(), $path . '/'); }
                elseif ($entry->isFile()) { $entries[$path] = ['type' => 'file', 'sha256' => hash_file('sha256', $entry->getPathname())]; }
                else { throw new \RuntimeException('patched SDK header is a special file'); }
            }
        };
        $visit($root, '');
        ksort($entries, SORT_STRING);
        return hash('sha256', json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function countSymbolDefinitions(string $llvmNm, string $archive, string $symbol): int
    {
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            [$llvmNm, '--defined-only', '--just-symbol-name', $archive],
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', $nullDevice, 'a'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('unable to start llvm-nm');
        }

        $count = 0;
        while (($line = fgets($pipes[1])) !== false) {
            if (trim($line) === $symbol) {
                $count++;
            }
        }
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException("llvm-nm failed with exit code {$exitCode}");
        }
        return $count;
    }
}
