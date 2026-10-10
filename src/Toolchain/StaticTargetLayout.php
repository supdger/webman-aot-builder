<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;

final class StaticTargetLayout
{
    /** @return array{gcc:string,cxx:string,targetInclude:string} */
    public function sysroot(string $directory): array
    {
        $root = realpath($directory);
        $root = is_string($root) ? str_replace(DIRECTORY_SEPARATOR, '/', $root) : $root;
        if (!is_string($root) || is_link($directory) || !is_file($root . '/usr/lib/libstdc++.a')) {
            throw new ConfigurationException('selected musl sysroot is missing or lacks the C++ runtime');
        }
        $layouts = [];
        foreach (glob($root . '/usr/lib/gcc/x86_64-alpine-linux-musl/*', GLOB_ONLYDIR) ?: [] as $gcc) {
            $cxx = $root . '/usr/include/c++/' . basename($gcc);
            $include = $cxx . '/x86_64-alpine-linux-musl';
            foreach ([$gcc . '/crtbegin.o', $gcc . '/libgcc.a', $cxx . '/vector', $include . '/bits/c++config.h'] as $file) {
                if (!is_file($file) || is_link($file) || !str_starts_with(str_replace(DIRECTORY_SEPARATOR, '/', (string) realpath($file)), $root . '/')) {
                    continue 2;
                }
            }
            if (is_link($gcc) || is_link($cxx) || is_link($include)) {
                throw new ConfigurationException('selected sysroot compiler layout is unsafe');
            }
            if (preg_match('/["\r\n\x00-\x1f]/', $gcc . $cxx . $include) === 1) {
                throw new ConfigurationException('selected sysroot compiler paths cannot be quoted safely');
            }
            $layouts[] = ['gcc' => str_replace(DIRECTORY_SEPARATOR, '/', $gcc), 'cxx' => str_replace(DIRECTORY_SEPARATOR, '/', $cxx), 'targetInclude' => str_replace(DIRECTORY_SEPARATOR, '/', $include)];
        }
        if (count($layouts) !== 1) {
            throw new ConfigurationException('selected sysroot requires one complete matching GCC/header ABI layout');
        }
        return $layouts[0];
    }

    public function phpVersion(string $sdk): string
    {
        $root = realpath($sdk);
        $root = is_string($root) ? str_replace(DIRECTORY_SEPARATOR, '/', $root) : $root;
        $header = $sdk . '/include/php/main/php_version.h';
        if (!is_string($root) || is_link($sdk) || !is_file($header) || is_link($header)
            || !str_starts_with(str_replace(DIRECTORY_SEPARATOR, '/', (string) realpath($header)), $root . '/')) {
            throw new ConfigurationException('selected SDK PHP ABI header is missing or unsafe');
        }
        $source = file_get_contents($header);
        $parts = [];
        foreach (['MAJOR', 'MINOR'] as $part) {
            if (!is_string($source) || preg_match_all('/^#define[ \t]+PHP_' . $part . '_VERSION[ \t]+([0-9]+)[ \t]*$/m', $source, $matches) !== 1) {
                throw new ConfigurationException('selected SDK PHP ABI header is ambiguous or incomplete');
            }
            $parts[] = (string) (int) $matches[1][0];
        }
        $version = implode('.', $parts);
        $manifestFile = $sdk . '/manifest.json';
        $manifest = is_file($manifestFile) && !is_link($manifestFile)
            ? json_decode((string) file_get_contents($manifestFile), true) : null;
        if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 'typephp-php-runtime-layer-v1'
            || ($manifest['target'] ?? null) !== 'linux-x64' || ($manifest['zts'] ?? null) !== true
            || !is_string($manifest['php_version'] ?? null)
            || !str_starts_with($manifest['php_version'], $version . '.')) {
            throw new ConfigurationException('selected SDK target/ZTS manifest does not match its PHP ABI header');
        }
        return $version;
    }
    /** @return array{phpVersionId:int,redisVersion:string,intlEnabled:bool,nativeLocaleIsRightToLeft:bool,nativeGraphemeLevenshtein:bool,deepcloneEnabled:bool} */
    public function runtimeCapabilities(string $sdk): array
    {
        $version = $this->phpVersion($sdk);
        $root = str_replace(DIRECTORY_SEPARATOR, '/', (string) realpath($sdk));
        $source = (string) file_get_contents($root . '/include/php/main/php_version.h');
        if (preg_match_all('/^#define[ \t]+PHP_VERSION_ID[ \t]+([0-9]+)[ \t]*$/m', $source, $ids) !== 1
            || preg_match_all('/^#define[ \t]+PHP_RELEASE_VERSION[ \t]+([0-9]+)[ \t]*$/m', $source, $releases) !== 1) {
            throw new ConfigurationException('selected SDK PHP runtime capability macros are incomplete');
        }
        [$major, $minor] = array_map('intval', explode('.', $version));
        $id = (int) $ids[1][0];
        if ($id !== $major * 10000 + $minor * 100 + (int) $releases[1][0]) {
            throw new ConfigurationException('selected SDK PHP runtime capability macros disagree');
        }
        $redisHeader = $root . '/include/php/ext/redis/php_redis.h';
        if (!is_file($redisHeader) || is_link($redisHeader)
            || !str_starts_with(str_replace(DIRECTORY_SEPARATOR, '/', (string) realpath($redisHeader)), $root . '/')) {
            throw new ConfigurationException('selected SDK Redis runtime capability header is missing or unsafe');
        }
        $redis = (string) file_get_contents($redisHeader);
        if (preg_match_all('/^#define[ \t]+PHP_REDIS_VERSION[ \t]+"([0-9]+\.[0-9]+\.[0-9]+(?:[a-zA-Z0-9.+-]*))"[ \t]*$/m', $redis, $matches) !== 1) {
            throw new ConfigurationException('selected SDK Redis runtime capability macro is ambiguous or incomplete');
        }
        return ['phpVersionId' => $id, 'redisVersion' => $matches[1][0], 'deepcloneEnabled' => $this->enabledExtension($root, 'deepclone')] + $this->intlCapabilities($root) + $this->standardCapabilities($root) + $this->namespacedFunctionExports($root);
    }

    private function namespacedFunctionExports(string $root): array
    {
        $functions = [];
        $evidence = [];
        $known = true;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/include/php', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '_arginfo.h')) { continue; }
            if ($file->isLink()) { throw new ConfigurationException('selected SDK function header is unsafe'); }
            $bytes = file_get_contents($file->getPathname());
            if (!is_string($bytes)) { throw new ConfigurationException('selected SDK function header cannot be read'); }
            $evidence[substr($file->getPathname(), strlen($root) + 1)] = hash('sha256', $bytes);
            $header = (string) preg_replace('~/\*.*?\*/|//[^\r\n]*~s', '', $bytes);
            preg_match_all('/(?:static\s+)?const\s+zend_function_entry\s+([A-Za-z0-9_]+)\s*\[\s*\]\s*=\s*\{(.*?)\};/s', $header, $tables, PREG_SET_ORDER);
            foreach ($tables as $table) {
                if (str_ends_with($table[1], '_methods')) { continue; }
                $depth = 0;
                $ended = false;
                foreach (preg_split('/\R/', trim($table[2])) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '') { continue; }
                    if (preg_match('/^#\s*(if|ifdef|ifndef)\b/', $line)) { ++$depth; continue; }
                    if (preg_match('/^#\s*endif\b/', $line)) { if (--$depth < 0) { $known = false; } continue; }
                    if (preg_match('/^#\s*(else|elif)\b/', $line)) { continue; }
                    if ($line === 'ZEND_FE_END' || $line === 'PHP_FE_END') { if ($ended || $depth !== 0) { $known = false; } $ended = true; continue; }
                    if ($ended) { $known = false; continue; }
                    if (preg_match('/^ZEND_RAW_FENTRY\(ZEND_NS_NAME\("([A-Za-z0-9_\\\\]+)",\s*"([A-Za-z0-9_]+)"\)/', $line, $match)) {
                        $functions[strtolower(str_replace('\\\\', '\\', $match[1]) . '\\' . $match[2])] = true;
                    } elseif (preg_match('/^ZEND_RAW_FENTRY\("([A-Za-z0-9_]+)"\s*,/', $line)
                        || preg_match('/^(?:ZEND|PHP)_(?:FE|FALIAS|NAMED_FE|DEP_FE|DEP_FALIAS)\([A-Za-z0-9_]+\s*,/', $line)) {
                        continue;
                    } else { $known = false; }
                }
                if (!$ended || $depth !== 0) { $known = false; }
            }
        }
        ksort($functions, SORT_STRING);
        ksort($evidence, SORT_STRING);
        return ['sdkNamespacedFunctionExportsKnown' => $known && $evidence !== [], 'sdkNamespacedFunctionExports' => array_keys($functions),
            'sdkFunctionExportsSha256' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR))];
    }

    /** @return array{intlEnabled:bool,nativeLocaleIsRightToLeft:bool,nativeGraphemeLevenshtein:bool} */
    private function intlCapabilities(string $root): array
    {
        $enabled = $this->enabledExtension($root, 'intl');
        $native = ['locale_is_right_to_left' => false, 'grapheme_levenshtein' => false, 'grapheme_strrev' => false];
        if ($enabled) {
            $header = $this->runtimeHeader($root, '/include/php/ext/intl/php_intl_arginfo.h');
            $header = (string) preg_replace('~/\*.*?\*/|//[^\r\n]*~s', '', $header);
            if (preg_match_all('/static\s+const\s+zend_function_entry\s+ext_functions\s*\[\s*\]\s*=\s*\{(.*?)\};/s', $header, $tables) !== 1) {
                throw new ConfigurationException('selected SDK Intl function registration table is incomplete or ambiguous');
            }
            $depth = 0;
            $ended = false;
            $registered = [];
            foreach (preg_split('/\R/', trim($tables[1][0])) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                if ($ended) {
                    throw new ConfigurationException('selected SDK Intl function registration table continues after its terminator');
                }
                if (preg_match('/^#\s*(?:if|ifdef|ifndef)\b.+$/', $line) === 1) { $depth++; continue; }
                if (preg_match('/^#\s*(?:else|elif)\b.*$/', $line) === 1 && $depth > 0) { continue; }
                if (preg_match('/^#\s*endif\s*$/', $line) === 1 && $depth > 0) { $depth--; continue; }
                if ($line === 'ZEND_FE_END' && $depth === 0) { $ended = true; continue; }
                if (preg_match('/^ZEND_FE\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*,\s*[A-Za-z_][A-Za-z0-9_]*\s*\)$/', $line, $entry) !== 1
                    && preg_match('/^ZEND_FALIAS\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*,\s*[A-Za-z_][A-Za-z0-9_]*\s*,\s*[A-Za-z_][A-Za-z0-9_]*\s*\)$/', $line, $entry) !== 1
                    && preg_match('/^ZEND_RAW_FENTRY\(\s*"([A-Za-z_][A-Za-z0-9_]*)"\s*,\s*[A-Za-z_][A-Za-z0-9_]*\s*,\s*[A-Za-z_][A-Za-z0-9_]*\s*,\s*[A-Za-z_0-9 |]+\s*,\s*NULL\s*,\s*NULL\s*\)$/', $line, $entry) !== 1) {
                    throw new ConfigurationException('selected SDK Intl function registration capability is unknown');
                }
                $name = $entry[1];
                if (isset($registered[$name])) {
                    throw new ConfigurationException('selected SDK Intl function registration is repeated');
                }
                $registered[$name] = true;
                if (array_key_exists($name, $native)) {
                    if ($depth !== 0) {
                        throw new ConfigurationException('selected SDK Intl function registration depends on an unknown condition');
                    }
                    $native[$name] = true;
                }
            }
            if (!$ended || $depth !== 0 || $registered === []) {
                throw new ConfigurationException('selected SDK Intl function registration table is incomplete');
            }
        }
        return ['intlEnabled' => $enabled, 'nativeLocaleIsRightToLeft' => $native['locale_is_right_to_left'],
            'nativeGraphemeLevenshtein' => $native['grapheme_levenshtein'], 'nativeGraphemeStrrev' => $native['grapheme_strrev']];
    }


    private function standardCapabilities(string $root): array
    {
        $header = $this->runtimeHeader($root, '/include/php/ext/standard/basic_functions_arginfo.h');
        $header = (string) preg_replace('~/\*.*?\*/|//[^\r\n]*~s', '', $header);
        if (preg_match_all('/static\s+const\s+zend_function_entry\s+ext_functions\s*\[\s*\]\s*=\s*\{(.*?)\};/s', $header, $functions) !== 1
            || preg_match_all('/static\s+void\s+register_basic_functions_symbols\s*\(int module_number\)\s*\{(.*?)\n\}/s', $header, $constants) !== 1
            || !str_contains($functions[1][0], 'ZEND_FE_END')
        ) { throw new ConfigurationException('selected SDK standard registration tables are incomplete'); }
        $result = ['nativeClamp' => false, 'nativeArrayFilterUseValue' => false];
        foreach ([$functions[1][0], $constants[1][0]] as $index => $table) {
            $depth = 0;
            $ended = false;
            foreach (preg_split('/\R/', $table) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                if ($ended) { throw new ConfigurationException('selected SDK standard function registration continues after its terminator'); }
                if ($index === 0 && $line === 'ZEND_FE_END' && $depth === 0) { $ended = true; continue; }
                if (preg_match('/^#\s*(?:if|ifdef|ifndef)\b/', $line) === 1) { ++$depth; continue; }
                if (preg_match('/^#\s*endif\b/', $line) === 1) { if (--$depth < 0) { throw new ConfigurationException('selected SDK standard registration conditions are unbalanced'); } continue; }
                $target = $index === 0 ? 'clamp' : 'ARRAY_FILTER_USE_VALUE';
                if (preg_match('/\b' . $target . '\b/', $line) !== 1) { continue; }
                $pattern = $index === 0 ? '/^ZEND_FE\(\s*clamp\s*,\s*\w+\s*\)$/' : '/^REGISTER_LONG_CONSTANT\("ARRAY_FILTER_USE_VALUE",\s*[A-Za-z_0-9]+,\s*CONST_PERSISTENT\);$/';
                if ($depth !== 0 || preg_match($pattern, $line) !== 1) { throw new ConfigurationException('selected SDK standard capability depends on an unknown registration'); }
                $key = $index === 0 ? 'nativeClamp' : 'nativeArrayFilterUseValue';
                if ($result[$key]) { throw new ConfigurationException('selected SDK standard capability is repeated'); }
                $result[$key] = true;
            }
            if ($depth !== 0 || ($index === 0 && !$ended)) { throw new ConfigurationException('selected SDK standard registration conditions are unbalanced or incomplete'); }
        }
        return $result;
    }

    private function enabledExtension(string $root, string $extension): bool
    {
        $build = $this->runtimeHeader($root, '/include/php/main/build-defs.h');
        if (preg_match_all('/^#define[ \t]+CONFIGURE_COMMAND[ \t]+"([^"\r\n]*)"[ \t]*$/m', $build, $commands) !== 1) {
            throw new ConfigurationException('selected SDK configure capability record is ambiguous or missing');
        }
        $option = preg_quote($extension, '/');
        preg_match_all("/'(--(?:enable-" . $option . "(?:=[^']*)?|disable-" . $option . "(?:=[^']*)?))'/", $commands[1][0], $flags);
        if (count($flags[1]) > 1 || (count($flags[1]) === 1
            && !in_array($flags[1][0], ['--enable-' . $extension, '--enable-' . $extension . '=yes', '--enable-' . $extension . '=no', '--disable-' . $extension], true))) {
            throw new ConfigurationException('selected SDK ' . $extension . ' linkage capability is unknown');
        }
        if ($flags[1] === [] && !str_contains($commands[1][0], "'--disable-all'")) {
            throw new ConfigurationException('selected SDK ' . $extension . ' enablement capability is unknown');
        }
        return in_array($flags[1][0] ?? '', ['--enable-' . $extension, '--enable-' . $extension . '=yes'], true);
    }

    private function runtimeHeader(string $root, string $relative): string
    {
        $file = $root . $relative;
        if (!is_file($file) || is_link($file) || !str_starts_with(str_replace(DIRECTORY_SEPARATOR, '/', (string) realpath($file)), $root . '/')) {
            throw new ConfigurationException('selected SDK runtime capability header is missing or unsafe: ' . $relative);
        }
        return (string) file_get_contents($file);
    }
}
