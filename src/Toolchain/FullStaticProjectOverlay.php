<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\SourceTreeSnapshot;

final class FullStaticProjectOverlay
{
    private const BASE_KEYS = [
        'name',
        'sources',
        'ignore',
        'output',
        'mode',
        'optimize',
        'job',
        'debug',
    ];

    public function apply(string $projectFile, string $sysroot, ?string $profile = null, ?string $sdk = null): void
    {
        if (!is_file($projectFile) || is_link($projectFile)) {
            throw new ConfigurationException('generated TypePHP project file is missing or unsafe');
        }
        $source = file_get_contents($projectFile);
        if (!is_string($source) || str_contains($source, "\r")) {
            throw new ConfigurationException('generated TypePHP project file is unreadable or not LF-normalized');
        }
        $keys = [];
        preg_match_all('/^([a-z][a-z-]*):(?:[ \t]|$)/m', $source, $matches);
        foreach ($matches[1] as $key) {
            if (isset($keys[$key])) {
                throw new ConfigurationException("duplicate TypePHP project key: {$key}");
            }
            $keys[$key] = true;
        }
        if (array_keys($keys) !== self::BASE_KEYS
            || !str_contains($source, "\nsources:\n  - main.php\n")
            || !str_contains($source, "\nignore:\n  - ")
            || preg_match('/^output: build\/[A-Za-z0-9._-]+$/m', $source) !== 1
            || !str_contains($source, "\nmode: bin\noptimize: 2\n")
            || preg_match('/\njob: [1-9][0-9]*\ndebug: false\n$/D', $source) !== 1
        ) {
            throw new ConfigurationException('generated TypePHP project structure drifted');
        }
        if ($profile === 'saiadmin') {
            $source = $this->ignoreCrontabExample($source, dirname($projectFile));
        }
        $source = $this->ignoreOptionalDoctrineBridge($source, dirname($projectFile));
        $target = realpath($sysroot);
        if (!is_string($target) || !is_dir($target) || is_link($sysroot)) {
            throw new ConfigurationException('locked musl sysroot is missing or unsafe');
        }
        $target = str_replace('\\', '/', $target);
        if (preg_match('/["\r\n\x00-\x1f]/', $target) === 1) {
            throw new ConfigurationException('locked musl sysroot path cannot be quoted safely');
        }
        if ($sdk === null) {
            throw new ConfigurationException('full-static overlay requires the selected SDK PHP ABI');
        }
        $layout = (new StaticTargetLayout())->sysroot($target);
        $phpVersion = (new StaticTargetLayout())->phpVersion($sdk);
        $gcc = $layout['gcc'];
        $cxx = $layout['cxx'];
        $targetInclude = $layout['targetInclude'];
        $suffix = <<<YAML
build-dir: build
php-version: "{$phpVersion}"
target-platform: x86_64-unknown-linux-musl
reproducible-source-prefix: /usr/src/webman-aot-builder
cxx-std: c++17
cxx-flags: >-
  --sysroot="{$target}"
  -isystem "{$cxx}"
  -isystem "{$targetInclude}"
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
    }

    private function ignoreCrontabExample(string $source, string $project): string
    {
        $relative = 'vendor/workerman/crontab/example/test.php';
        if (!file_exists($project . '/' . $relative) && !is_link($project . '/' . $relative)) {
            return $source;
        }
        $tokens = $this->tokens($this->read($project, $relative), $relative);
        $text = implode('', array_column($tokens, 1));
        $imports = [];
        $index = 1;
        while (($tokens[$index][0] ?? null) === T_USE) {
            $import = '';
            while (($tokens[++$index][1] ?? null) !== ';' && isset($tokens[$index])) {
                $import .= $tokens[$index][1];
            }
            $imports[] = ltrim($import, '\\');
            $index++;
            // The official example places its autoload between its two imports.
            if (($tokens[$index][0] ?? null) === T_REQUIRE) {
                $require = array_slice($tokens, $index, 5);
                if (array_column($require, 1) !== ['require', '__DIR__', '.', "'/../vendor/autoload.php'", ';']
                    && array_column($require, 1) !== ['require', '__DIR__', '.', '"/../vendor/autoload.php"', ';']) {
                    throw new ConfigurationException("Crontab example autoload structure is unsupported: {$relative}");
                }
                $index += 5;
            }
        }
        sort($imports, SORT_STRING);
        if ($imports !== ['Workerman\\Crontab\\Crontab', 'Workerman\\Worker']
            || preg_match('/^(\$[A-Za-z_][A-Za-z0-9_]*)=new(?:\\\\?Workerman\\\\)?Worker\(\);/',
                implode('', array_column(array_slice($tokens, $index), 1)), $match) !== 1) {
            throw new ConfigurationException("Crontab example Worker entry structure is unsupported: {$relative}");
        }
        $variable = $match[1];
        $tail = substr($text, strlen(implode('', array_column(array_slice($tokens, 0, $index), 1))) + strlen($match[0]));
        if (!str_starts_with($tail, $variable . '->onWorkerStart=function(')
            || !str_ends_with($tail, '};Worker::runAll();')) {
            throw new ConfigurationException("Crontab example startup structure is unsupported: {$relative}");
        }
        // The callback assignment must end at its own closing brace, without executing an RHS suffix.
        $callback = $this->tokens('<?php ' . $tail, $relative);
        $parentheses = 0;
        $depth = 0;
        $body = false;
        foreach ($callback as $index => [$kind, $value]) {
            if (!$body) {
                if ($value === '(') {
                    $parentheses++;
                } elseif ($value === ')') {
                    $parentheses--;
                } elseif ($value === '{' && $parentheses === 0) {
                    $body = true;
                    $depth = 1;
                }
                continue;
            }
            if ($value === '{' || in_array($kind, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif ($value === '}') {
                $depth--;
            }
            if ($depth === 0) {
                if (implode('', array_column(array_slice($callback, $index + 1), 1)) !== ';Worker::runAll();') {
                    throw new ConfigurationException("Crontab example contains additional startup execution: {$relative}");
                }
                break;
            }
        }
        $this->assertUnreferenced($project, [$relative], false);
        return $this->exclude($source, [$relative]);
    }

    private function ignoreOptionalDoctrineBridge(string $source, string $project): string
    {
        $lock = json_decode($this->read($project, 'composer.lock'), true);
        if (!is_array($lock) || !is_array($lock['packages'] ?? null)) {
            throw new ConfigurationException('optional Carbon Doctrine bridge requires a valid composer.lock');
        }
        $bridge = false;
        foreach (array_merge($lock['packages'], $lock['packages-dev'] ?? []) as $package) {
            if (($package['name'] ?? null) === 'doctrine/dbal') {
                return $source;
            }
            if (($package['name'] ?? null) === 'carbonphp/carbon-doctrine-types') {
                if ($bridge) {
                    throw new ConfigurationException('duplicate optional Carbon Doctrine bridge package');
                }
                $bridge = true;
            }
        }
        if (!$bridge) {
            return $source;
        }
        $prefix = 'vendor/carbonphp/carbon-doctrine-types/src/Carbon/Doctrine/';
        $directory = $project . '/' . $prefix;
        if (!is_dir($directory) || is_link($directory)) {
            throw new ConfigurationException('optional Carbon Doctrine bridge source is missing or unsafe');
        }
        $excluded = [];
        $declarations = [];
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink() || !$entry->isFile()) {
                throw new ConfigurationException('optional Carbon Doctrine bridge source structure is unsafe');
            }
            if ($entry->getExtension() !== 'php') {
                continue;
            }
            $relative = $prefix . $entry->getFilename();
            $tokens = $this->tokens($this->read($project, $relative), $relative);
            $namespace = false;
            for ($index = 1, $count = count($tokens); $index < $count; $index++) {
                [$kind, $value] = $tokens[$index];
                if (in_array($kind, [T_NAMESPACE, T_USE, T_DECLARE], true)) {
                    $header = '';
                    while (isset($tokens[++$index]) && $tokens[$index][1] !== ';') {
                        $header .= $tokens[$index][1];
                    }
                    if ($kind === T_NAMESPACE) {
                        if ($namespace || $header !== 'Carbon\\Doctrine') {
                            throw new ConfigurationException("optional Carbon Doctrine bridge namespace is unsupported: {$relative}");
                        }
                        $namespace = true;
                    } elseif ($kind === T_DECLARE && $header !== '(strict_types=1)') {
                        throw new ConfigurationException("optional Carbon Doctrine bridge declaration is unsupported: {$relative}");
                    }
                    continue;
                }
                if (in_array($kind, [T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                    continue;
                }
                if (!$namespace || !in_array($kind, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                    || ($tokens[$index + 1][0] ?? null) !== T_STRING) {
                    throw new ConfigurationException("optional Carbon Doctrine bridge has executable top-level PHP: {$relative}");
                }
                $name = $tokens[++$index][1];
                if (isset($declarations[$name])) {
                    throw new ConfigurationException("optional Carbon Doctrine bridge has duplicate declaration: {$name}");
                }
                $declarations[$name] = true;
                while (isset($tokens[++$index]) && $tokens[$index][1] !== '{') {
                }
                $depth = 1;
                while ($depth > 0 && isset($tokens[++$index])) {
                    [$kind, $value] = $tokens[$index];
                    if ($value === '{' || in_array($kind, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                        $depth++;
                    } elseif ($value === '}') {
                        $depth--;
                    }
                }
            }
            $excluded[] = $relative;
        }
        if ($declarations === []) {
            throw new ConfigurationException('optional Carbon Doctrine bridge has no declarations');
        }
        sort($excluded, SORT_STRING);
        $this->assertUnreferenced($project, $excluded, true);
        return $this->exclude($source, $excluded);
    }

    /** Exclusion is permitted only for unused declarations or a standalone package example. */
    private function assertUnreferenced(string $project, array $excluded, bool $doctrine): void
    {
        $paths = (new SourceTreeSnapshot($project))->captureWithFiles()['digests'];
        foreach (array_keys($paths) as $relative) {
            if (in_array($relative, $excluded, true)) {
                continue;
            }
            if (basename($relative) === 'composer.json') {
                $composer = json_decode($this->read($project, $relative), true);
                foreach (['autoload', 'autoload-dev'] as $section) {
                    foreach (['files', 'classmap'] as $kind) {
                        foreach ($composer[$section][$kind] ?? [] as $path) {
                            if (!is_string($path)) {
                                throw new ConfigurationException("unsupported Composer autoload path: {$relative}");
                            }
                            $base = dirname($project . '/' . $relative);
                            $resolved = realpath($base . '/' . $path);
                            foreach ($excluded as $candidate) {
                                $file = $project . '/' . $candidate;
                                if (($resolved !== false && ($file === $resolved || str_starts_with($file, rtrim($resolved, '/') . '/')))
                                    || fnmatch($base . '/' . $path, $file)) {
                                    throw new ConfigurationException("excluded PHP is required by Composer {$kind}: {$relative}: {$candidate}");
                                }
                            }
                        }
                    }
                }
            }
            if (pathinfo($relative, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }
            $contents = $this->read($project, $relative);
            $tokens = token_get_all($contents);
            if (in_array($relative, ['vendor/composer/autoload_classmap.php', 'vendor/composer/autoload_psr4.php',
                'vendor/composer/autoload_static.php', 'vendor/composer/autoload_namespaces.php'], true)) {
                $tokens = $this->registryReferences($contents, $relative);
            }
            foreach ($tokens as $token) {
                if (!is_array($token) || in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                    continue;
                }
                $value = strtolower(str_replace('\\\\', '\\', $token[1]));
                if (($doctrine && (str_contains($value, 'carbon\\doctrine')
                        || str_contains($value, 'carbon-doctrine-types/') || str_contains($value, 'carbon/doctrine/')))
                    || (!$doctrine && preg_match('~(?:crontab[/\\\\])?example[/\\\\]test\.php~i', $value) === 1)) {
                    throw new ConfigurationException("excluded PHP has an external project reference: {$relative}");
                }
            }
        }
    }

    /** Only a whole Composer registration file may omit its inert class mapping data. */
    private function registryReferences(string $source, string $relative): array
    {
        $original = token_get_all($source);
        $tokens = $this->tokens($source, $relative);
        if (!str_ends_with($relative, '/autoload_static.php')) {
            $index = 1;
            foreach ([['$vendorDir', '=', 'dirname', '(', '__DIR__', ')', ';'],
                ['$baseDir', '=', 'dirname', '(', '$vendorDir', ')', ';']] as $definition) {
                if (array_column(array_slice($tokens, $index, count($definition)), 1) === $definition) {
                    $index += count($definition);
                }
            }
            if (($tokens[$index][0] ?? null) !== T_RETURN) {
                return $original;
            }
            $end = $this->literalArrayEnd($tokens, $index + 1, false);
            return $end !== null && ($tokens[$end + 1][1] ?? null) === ';' && $end + 2 === count($tokens)
                ? [] : $original;
        }
        if (array_column(array_slice($tokens, 1, 4), 1) !== ['namespace', 'Composer\\Autoload', ';', 'class']
            || ($tokens[5][0] ?? null) !== T_STRING
            || preg_match('/^ComposerStaticInit[A-Za-z0-9_]+$/D', $tokens[5][1]) !== 1
            || ($tokens[6][1] ?? null) !== '{') {
            return $original;
        }
        $class = $tokens[5][1];
        $index = 7;
        $properties = [];
        $retained = [];
        $mappingProperties = ['classMap', 'prefixLengthsPsr4', 'prefixDirsPsr4', 'fallbackDirsPsr4', 'prefixesPsr0', 'fallbackDirsPsr0'];
        while (($tokens[$index][0] ?? null) === T_PUBLIC && ($tokens[$index + 1][0] ?? null) === T_STATIC
            && ($tokens[$index + 2][0] ?? null) === T_VARIABLE) {
            $name = substr($tokens[$index + 2][1], 1);
            if (isset($properties[$name]) || !in_array($name, [...$mappingProperties, 'files'], true)
                || ($tokens[$index + 3][1] ?? null) !== '=') {
                return $original;
            }
            $end = $this->literalArrayEnd($tokens, $index + 4, true);
            if ($end === null || ($tokens[$end + 1][1] ?? null) !== ';') {
                return $original;
            }
            $properties[$name] = true;
            if ($name === 'files') {
                foreach (array_slice($tokens, $index + 4, $end - $index - 3) as [$kind, $value]) {
                    $retained[] = is_int($kind) ? [$kind, $value] : $value;
                }
            }
            $index = $end + 2;
        }
        $method = implode('', array_column(array_slice($tokens, $index), 1));
        if (preg_match('/^publicstaticfunctiongetInitializer\(ClassLoader(\$[A-Za-z_][A-Za-z0-9_]*)\)\{return\\\\Closure::bind\(function\(\)use\(\1\)\{/',
            $method, $match) !== 1) {
            return $original;
        }
        $loader = preg_quote($match[1], '~');
        $remaining = substr($method, strlen($match[0]));
        $transfers = [];
        $assignment = '~^' . $loader . '->([A-Za-z_][A-Za-z0-9_]*)=' . preg_quote($class, '~') . '::\$\1;~';
        while (preg_match($assignment, $remaining, $match) === 1) {
            $name = $match[1];
            if (!in_array($name, $mappingProperties, true) || !isset($properties[$name]) || isset($transfers[$name])) {
                return $original;
            }
            $transfers[$name] = true;
            $remaining = substr($remaining, strlen($match[0]));
        }
        unset($properties['files']);
        ksort($properties, SORT_STRING);
        ksort($transfers, SORT_STRING);
        if ($remaining !== '},null,ClassLoader::class);}}' || $properties !== $transfers) {
            return $original;
        }
        return $retained;
    }

    private function literalArrayEnd(array $tokens, int $start, bool $static): ?int
    {
        if (($tokens[$start][0] ?? null) !== T_ARRAY && ($tokens[$start][1] ?? null) !== '[') {
            return null;
        }
        $stack = [];
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            [$kind, $value] = $tokens[$index];
            if ($value === '(') {
                if (($tokens[$index - 1][0] ?? null) !== T_ARRAY) {
                    return null;
                }
                $stack[] = ')';
            } elseif ($value === '[') {
                if ($index !== $start && !in_array($tokens[$index - 1][1], ['=>', ',', '(', '['], true)) {
                    return null;
                }
                $stack[] = ']';
            } elseif ($value === ')' || $value === ']') {
                if (array_pop($stack) !== $value) {
                    return null;
                }
                if ($stack === []) {
                    return $index;
                }
            } elseif (in_array($kind, [T_ARRAY, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_DIR, T_DOUBLE_ARROW], true)
                || in_array($value, ['.', ','], true)
                || (!$static && $kind === T_VARIABLE && in_array($value, ['$vendorDir', '$baseDir'], true))) {
                continue;
            } else {
                return null;
            }
        }
        return null;
    }

    private function exclude(string $source, array $paths): string
    {
        if (substr_count($source, "\noutput:") !== 1) {
            throw new ConfigurationException('optional source exclusion project structure drifted');
        }
        $ignore = explode("\noutput:", explode("\nignore:", $source, 2)[1], 2)[0];
        $entries = [];
        foreach ($paths as $relative) {
            if (!str_contains($ignore, "\n  - {$relative}\n")) {
                $entries[] = '  - ' . $relative;
            }
        }
        return $entries === [] ? $source : str_replace("\noutput:", implode("\n", $entries) . "\n\noutput:", $source);
    }

    private function read(string $project, string $relative): string
    {
        $file = $project . '/' . $relative;
        $real = realpath($file);
        if (!is_string($real) || !str_starts_with(str_replace(DIRECTORY_SEPARATOR, '/', $real), rtrim(str_replace(DIRECTORY_SEPARATOR, '/', (string) realpath($project)), '/') . '/')
            || !is_file($file) || is_link($file)) {
            throw new ConfigurationException("optional source is missing or unsafe: {$relative}");
        }
        $source = file_get_contents($file);
        if (!is_string($source)) {
            throw new ConfigurationException("optional source is unreadable: {$relative}");
        }
        return $source;
    }

    /** @return list<array{int|string,string}> */
    private function tokens(string $source, string $relative): array
    {
        try {
            $raw = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $error) {
            throw new ConfigurationException("optional source is invalid PHP: {$relative}", previous: $error);
        }
        $tokens = [];
        foreach ($raw as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = is_array($token) ? [$token[0], $token[1]] : [$token, $token];
        }
        return $tokens;
    }
}
