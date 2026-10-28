<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;

final class FullStaticProjectOverlay
{
    public function apply(string $projectFile, string $sysroot): void
    {
        if (!is_file($projectFile) || is_link($projectFile)) {
            throw new ConfigurationException('generated TypePHP project file is missing or unsafe');
        }
        $source = file_get_contents($projectFile);
        if (!is_string($source) || str_contains($source, "\r")) {
            throw new ConfigurationException('generated TypePHP project file is unreadable or not LF-normalized');
        }
        $beforeSha256 = hash('sha256', $source);
        $keys = [];
        preg_match_all('/^([a-z][a-z-]*):(?:[ \t]|$)/m', $source, $matches);
        foreach ($matches[1] as $key) {
            if (isset($keys[$key])) {
                throw new ConfigurationException("duplicate TypePHP project key: {$key}");
            }
            $keys[$key] = true;
        }
        if (!preg_match('/^sources:[ \t]*(?:\n|$)/m', $source)
            || preg_match('/^output: build\/[A-Za-z0-9._-]+$/m', $source) !== 1
            || preg_match('/^mode: bin$/m', $source) !== 1
        ) {
            throw new ConfigurationException('TypePHP binary project requires sources and a build output');
        }
        // The full-static target is owned by this builder. Preserve other valid
        // TypePHP options while replacing target fields from a user manifest.
        $targetKeys = [
            'build-dir', 'php-version', 'target-platform', 'reproducible-source-prefix',
            'cxx-std', 'cxx-flags', 'c-flags', 'asm-flags', 'ld-flags',
            'native-runtime-declarations',
        ];
        $lines = [];
        $skip = false;
        foreach (explode("\n", $source) as $line) {
            if (preg_match('/^([a-z][a-z-]*):/', $line, $key) === 1) {
                $skip = in_array($key[1], $targetKeys, true);
            }
            if (!$skip) {
                $lines[] = $line;
            }
        }
        $source = rtrim(implode("\n", $lines)) . "\n";
        $target = realpath($sysroot);
        if (!is_string($target) || !is_dir($target) || is_link($sysroot)) {
            throw new ConfigurationException('locked musl sysroot is missing or unsafe');
        }
        $target = str_replace('\\', '/', $target);
        if (preg_match('/["\r\n\x00-\x1f]/', $target) === 1) {
            throw new ConfigurationException('locked musl sysroot path cannot be quoted safely');
        }
        foreach ([
            '/usr/include/c++/12.2.1/vector',
            '/usr/include/c++/12.2.1/x86_64-alpine-linux-musl/bits/c++config.h',
            '/usr/lib/gcc/x86_64-alpine-linux-musl/12.2.1/crtbegin.o',
            '/usr/lib/libstdc++.a',
        ] as $required) {
            if (!is_file($target . $required)) {
                throw new ConfigurationException("locked musl sysroot is incomplete: {$required}");
            }
        }

        $gcc = $target . '/usr/lib/gcc/x86_64-alpine-linux-musl/12.2.1';
        $suffix = <<<YAML
build-dir: build
php-version: "8.4"
target-platform: x86_64-unknown-linux-musl
reproducible-source-prefix: /usr/src/webman-aot-builder
native-runtime-declarations: true
cxx-std: c++17
cxx-flags: >-
  --sysroot="{$target}"
  -isystem "{$target}/usr/include/c++/12.2.1"
  -isystem "{$target}/usr/include/c++/12.2.1/x86_64-alpine-linux-musl"
c-flags: --sysroot="{$target}"
asm-flags: --sysroot="{$target}"
ld-flags: >-
  --sysroot="{$target}"
  -fuse-ld=lld
  -Wl,--allow-multiple-definition
  -Wl,--strip-debug
  -B"{$gcc}"
  -L"{$target}/usr/lib"
  -L"{$gcc}"
YAML;
        $suffix = str_replace(["\r\n", "\r"], "\n", $suffix);
        if (file_put_contents($projectFile, $source . $suffix . "\n", LOCK_EX) === false) {
            throw new ConfigurationException('unable to write full-static TypePHP project overlay');
        }
        $receiptFile = dirname($projectFile) . '/.typephp/build/compilation-sources.json';
        if (is_file($receiptFile) && !is_link($receiptFile)) {
            $receipt = json_decode((string) file_get_contents($receiptFile), true);
            if (is_array($receipt) && ($receipt['schema'] ?? null) === 'webman-aot-compilation-sources-v2'
                && ($receipt['manifestSha256'] ?? null) === $beforeSha256) {
                $receipt['manifestSha256'] = hash('sha256', $source . $suffix . "\n");
                $bytes = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
                if (file_put_contents($receiptFile, $bytes, LOCK_EX) !== strlen($bytes)) {
                    throw new ConfigurationException('unable to bind source selection to the full-static manifest');
                }
            }
        }
    }

}
