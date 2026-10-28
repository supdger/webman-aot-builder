<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;

/** Actual bytes, including headers/configuration, rather than version labels. */
final class CompileInputFingerprint
{
    /** @param array<string,string> $tools @param array<string,string> $policy */
    public function digest(array $tools, array $policy, ?\Closure $progress = null): string
    {
        $context = hash_init('sha256');
        hash_update($context, 'webman-aot-compile-input-v1');
        ksort($policy, SORT_STRING);
        hash_update($context, json_encode($policy, JSON_THROW_ON_ERROR));
        $tools['compilerRuntime'] = dirname(dirname($tools['compiler']));
        $tools['phpRuntime'] = dirname($tools['php']);
        $digests = [];
        foreach (['compilerRuntime', 'phpRuntime', 'php', 'compiler', 'objcopy', 'typephp', 'phpx', 'sysroot', 'phprc'] as $name) {
            $progress?->__invoke($name);
            $path = realpath($tools[$name]);
            if (!is_string($path)) {
                throw new ConfigurationException("missing compiler fingerprint input: {$name}");
            }
            $files = [];
            if (is_file($path)) {
                $files['.'] = $path;
            } else {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $entry) {
                    if ($entry->isLink() && $entry->isDir()) {
                        throw new ConfigurationException("compiler fingerprint refuses linked directory: {$name}");
                    }
                    if ($entry->isFile()) {
                        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($path) + 1));
                        $files[$relative] = $entry->getPathname();
                    }
                }
            }
            ksort($files, SORT_STRING);
            foreach ($files as $relative => $file) {
                $actual = realpath($file);
                $digest = is_string($actual) ? ($digests[$actual] ?? hash_file('sha256', $actual)) : false;
                if (is_string($actual) && is_string($digest)) { $digests[$actual] = $digest; }
                if (!is_string($digest)) {
                    throw new ConfigurationException("cannot hash compiler input: {$name}/{$relative}");
                }
                hash_update($context, $name . "\0" . $relative . "\0" . $digest . "\n");
            }
        }
        return hash_final($context);
    }
}
