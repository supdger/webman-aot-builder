<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectDiscovery;

final class ConditionalFileLoader
{
    /** Select declaration-only fallback files from actual Composer autoload guards. */
    public function apply(string $mirror, array $files, array $existing): array
    {
        $sources = [];
        $loaders = $this->registeredLoaders($mirror);
        $types = [];
        $selector = new ConditionalDeclarationSelector();
        $replaced = array_column($existing, 'shadow', 'path');
        foreach ($files as $file) {
            if ($file['category'] === ProjectDiscovery::BUSINESS_PHP) {
                $source = $this->read($mirror . '/' . ($replaced[$file['path']] ?? $file['path']));
                $sources[$file['path']] = $source;
                foreach ($selector->declarations($source) as $name => $kind) {
                    $types[strtolower($name)][$kind][] = $file['path'];
                }
            }
        }
        $bindings = new PhpFunctionBindings($sources);
        $plans = [];
        $fallbackPaths = [];
        foreach ($loaders as $loader => $_) {
            if (!isset($sources[$loader]) || isset($replaced[$loader])) {
                continue;
            }
            $source = $this->withoutComments($sources[$loader]);
            $pattern = '~\G\s*if\s*\(\s*!\s*(\\\\?(?:class_exists|interface_exists))\s*\(\s*([\'"])([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\2\s*\)\s*\)\s*\{\s*require(?:_once)?\s+__DIR__\s*\.\s*([\'"])([^\'"]+)\4\s*;\s*\}~';
            $offset = 0;
            $guards = [];
            while (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $offset += strlen($match[0][0]);
                $name = str_replace('\\\\', '\\', $match[3][0]);
                $path = realpath($mirror . '/' . dirname($loader) . '/' . $match[5][0]);
                if (!$bindings->builtinCalls($match[1][0] . "('" . $match[3][0] . "')", '', [], false)
                    || !is_string($path) || !str_starts_with($path, $mirror . '/')
                ) {
                    $guards = [];
                    break;
                }
                $fallback = substr($path, strlen($mirror) + 1);
                $declarations = isset($sources[$fallback]) ? $this->pureDeclarations($sources[$fallback], $selector) : null;
                $kind = ltrim($match[1][0], '\\') === 'class_exists' ? T_CLASS : T_INTERFACE;
                if ($declarations !== [strtolower($name) => $kind]) {
                    $guards = [];
                    break;
                }
                $guards[] = ['name' => strtolower($name), 'kind' => $kind, 'fallback' => $fallback];
            }
            if ($guards !== [] && trim(substr($source, $offset)) === '') {
                $plans[$loader] = $guards;
                foreach ($guards as $guard) {
                    $fallbackPaths[$guard['fallback']] = true;
                }
            }
        }
        $mappings = [];
        $selected = $replaced;
        foreach ($plans as $loader => $guards) {
            $choices = [];
            foreach ($guards as $guard) {
                $definitions = array_values(array_unique($types[$guard['name']][$guard['kind']] ?? []));
                $primary = array_values(array_filter($definitions, static fn(string $path): bool => !isset($fallbackPaths[$path])));
                if (count($primary) === 1) {
                    $choices[] = ['fallback' => $guard['fallback'], 'active' => $primary[0]];
                } elseif ($primary === [] && $definitions === [$guard['fallback']]) {
                    $choices[] = ['fallback' => $guard['fallback'], 'active' => $guard['fallback']];
                } else {
                    $choices = [];
                    break;
                }
            }
            if ($choices === []) {
                continue;
            }
            foreach ($choices as $choice) {
                $active = $choice['active'];
                $fallback = $choice['fallback'];
                if ($active === $fallback) {
                    continue;
                }
                if (!isset($selected[$active])) {
                    $shadow = '.typephp/build/conditional-load-' . hash('sha256', $active) . '.php';
                    $this->write($mirror . '/' . $shadow, $sources[$active]);
                    $mappings[] = $this->mapping($mirror, $active, $shadow);
                    $selected[$active] = $shadow;
                }
                if (!isset($selected[$fallback])) {
                    $mappings[] = $this->mapping($mirror, $fallback, $selected[$active]);
                    $selected[$fallback] = $selected[$active];
                }
            }
            $shadow = '.typephp/build/conditional-loader-' . hash('sha256', $loader) . '.php';
            $this->write($mirror . '/' . $shadow, "<?php\n");
            $mappings[] = $this->mapping($mirror, $loader, $shadow);
            fwrite(STDERR, "[adapt] selected conditional declaration loader {$loader}\n");
        }
        return $mappings;
    }

    private function registeredLoaders(string $mirror): array
    {
        $loaders = [];
        if (is_file($mirror . '/composer.json')) {
            $manifest = json_decode($this->read($mirror . '/composer.json'), true);
            foreach (is_array($manifest['autoload']['files'] ?? null) ? $manifest['autoload']['files'] : [] as $path) {
                if (is_string($path)) {
                    $real = realpath($mirror . '/' . $path);
                    if (is_string($real) && str_starts_with($real, $mirror . '/')) {
                        $loaders[substr($real, strlen($mirror) + 1)] = true;
                    }
                }
            }
        }
        $registry = $mirror . '/vendor/composer/autoload_files.php';
        if (!is_file($registry)) {
            return $loaders;
        }
        $source = $this->withoutComments($this->read($registry));
        if (preg_match('/^\s*\$vendorDir\s*=\s*dirname\s*\(\s*__DIR__\s*\)\s*;\s*\$baseDir\s*=\s*dirname\s*\(\s*\$vendorDir\s*\)\s*;\s*return\s+array\s*\((.*)\)\s*;\s*$/sD', $source, $array) !== 1) {
            return $loaders;
        }
        $offset = 0;
        $registered = [];
        $entries = '~\G\s*([\'"])[^\'"]*\1\s*=>\s*\$(vendorDir|baseDir)\s*\.\s*([\'"])(/[^\'"]+)\3\s*,~';
        while (preg_match($entries, $array[1], $entry, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $offset += strlen($entry[0][0]);
            $real = realpath(($entry[2][0] === 'vendorDir' ? $mirror . '/vendor' : $mirror) . $entry[4][0]);
            if (is_string($real) && str_starts_with($real, $mirror . '/')) {
                $registered[substr($real, strlen($mirror) + 1)] = true;
            }
        }
        return trim(substr($array[1], $offset)) === '' ? $loaders + $registered : $loaders;
    }

    private function pureDeclarations(string $source, ConditionalDeclarationSelector $selector): ?array
    {
        $body = $this->withoutComments($source);
        $namespace = '';
        if (preg_match('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $body, $match) === 1) {
            $body = substr($body, strlen($match[0]));
        }
        if (preg_match('/^\s*namespace\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*;/', $body, $match) === 1) {
            $namespace = $match[1] . '\\';
            $body = substr($body, strlen($match[0]));
        }
        while (preg_match('/^\s*use\s+[^;{}]+;/', $body, $match) === 1) {
            $body = substr($body, strlen($match[0]));
        }
        $declared = $selector->branchDeclarations($body);
        if ($declared === null) {
            return null;
        }
        $qualified = [];
        foreach ($declared as $name => $kind) {
            $qualified[strtolower($namespace . $name)] = $kind;
        }
        return $qualified;
    }

    private function withoutComments(string $source): string
    {
        $result = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $result .= $token;
            } elseif (!in_array($token[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT], true)) {
                $result .= $token[1];
            }
        }
        return $result;
    }

    private function mapping(string $mirror, string $path, string $shadow): array
    {
        return ['path' => $path, 'shadow' => $shadow,
            'sourceSha256' => hash('sha256', $this->read($mirror . '/' . $path)),
            'shadowSha256' => hash('sha256', $this->read($mirror . '/' . $shadow))];
    }

    private function read(string $path): string
    {
        $contents = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($contents)) {
            throw new ConfigurationException('conditional loader source is missing or unsafe');
        }
        return $contents;
    }

    private function write(string $path, string $source): void
    {
        if (is_link($path) || file_put_contents($path, $source, LOCK_EX) !== strlen($source)) {
            throw new ConfigurationException('conditional loader shadow cannot be written');
        }
    }
}
