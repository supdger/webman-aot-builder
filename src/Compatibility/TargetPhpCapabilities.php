<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class TargetPhpCapabilities
{
    /**
     * Read the already verified build SDK, never the host PHP's loaded modules.
     *
     * @return array{phpVersion:string,phpVersionId:int,extensionVersions:array<string,string>,modules:array<string,true>,evidence:array<string,string>}
     */
    public function read(string $sdkDirectory): array
    {
        $sdk = realpath($sdkDirectory);
        if (!is_string($sdk) || is_link($sdkDirectory)) {
            throw new ConfigurationException('target PHP SDK directory is missing or unsafe');
        }
        $manifestBytes = $this->file($sdk, 'sdk-manifest.json');
        $manifest = json_decode($manifestBytes, true, flags: JSON_THROW_ON_ERROR);
        $header = $this->file($sdk, 'include/php/main/php_version.h');
        if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 'typephp-phpx-sdk-v1'
            || !is_string($manifest['php_version'] ?? null)
            || preg_match('/^#define\s+PHP_VERSION\s+"([^"]+)"/m', $header, $version) !== 1
            || preg_match('/^#define\s+PHP_VERSION_ID\s+(\d+)/m', $header, $versionId) !== 1
            || $manifest['php_version'] !== $version[1]
        ) {
            throw new ConfigurationException('target PHP SDK version metadata is inconsistent');
        }
        $modules = [];
        $library = $this->path($sdk, 'lib/libphp.a');
        $stream = fopen($library, 'rb');
        if (!is_resource($stream)) {
            throw new ConfigurationException('target PHP SDK library cannot be read');
        }
        $digest = hash_init('sha256');
        $tail = '';
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1048576);
                if (!is_string($chunk)) {
                    throw new ConfigurationException('target PHP SDK library read failed');
                }
                hash_update($digest, $chunk);
                $symbols = $tail . $chunk;
                preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)_module_entry\x00/', $symbols, $matches);
                foreach ($matches[1] as $name) {
                    $modules[strtolower($name)] = true;
                }
                $tail = substr($symbols, -256);
            }
        } finally {
            fclose($stream);
        }
        $versions = [];
        $evidence = [
            'sdk-manifest.json' => hash('sha256', $manifestBytes),
            'include/php/main/php_version.h' => hash('sha256', $header),
            'lib/libphp.a' => hash_final($digest),
        ];
        $registrationComplete = false;
        $registeredSymbols = null;
        if (is_file($sdk . '/include/php/Zend/zend_modules.h') && is_file($sdk . '/include/php/main/build-defs.h')) {
            $moduleHeader = $this->file($sdk, 'include/php/Zend/zend_modules.h');
            $buildDefinitions = $this->file($sdk, 'include/php/main/build-defs.h');
            $registeredSymbols = (new TargetPhpModuleTable())->read($library, $moduleHeader);
            if ($registeredSymbols !== null) {
                $modules = array_fill_keys(array_map('strtolower', array_values($registeredSymbols)), true);
                $registrationComplete = str_contains($buildDefinitions, "'--disable-all'")
                    && str_contains($buildDefinitions, "'--enable-shared=no'")
                    && str_contains($buildDefinitions, "'--enable-static=yes'");
                $evidence['include/php/Zend/zend_modules.h'] = hash('sha256', $moduleHeader);
                $evidence['include/php/main/build-defs.h'] = hash('sha256', $buildDefinitions);
            }
        }
        $extensions = $sdk . '/include/php/ext';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($extensions, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) {
                throw new ConfigurationException('target PHP SDK extension metadata contains a symlink');
            }
            if (!$file->isFile() || $file->getExtension() !== 'h' || !str_starts_with($file->getFilename(), 'php_')) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($sdk) + 1));
            $source = $this->file($sdk, $relative);
            preg_match_all('/^#\s*define\s+PHP_([A-Z0-9_]+)_VERSION\s+"([^"\r\n]+)"/m', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $name = strtolower($match[1]);
                if (isset($modules[$name])) {
                    $versions[$name] = $match[2];
                    $evidence[$relative] = hash('sha256', $source);
                }
            }
        }
        return ['phpVersion' => $version[1], 'phpVersionId' => (int) $versionId[1],
            'extensionVersions' => $versions, 'modules' => $modules,
            'registeredSymbols' => $registeredSymbols, 'registrationComplete' => $registrationComplete,
            'evidence' => $evidence];
    }

    private function file(string $sdk, string $relative): string
    {
        $contents = file_get_contents($this->path($sdk, $relative));
        if (!is_string($contents)) {
            throw new ConfigurationException("target PHP SDK metadata cannot be read: {$relative}");
        }
        return $contents;
    }

    private function path(string $sdk, string $relative): string
    {
        $path = $sdk . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path) || is_link($path) || realpath($path) !== $path) {
            throw new ConfigurationException("target PHP SDK metadata missing or unsafe: {$relative}");
        }
        return $path;
    }
}
