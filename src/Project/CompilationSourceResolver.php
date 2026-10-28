<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class CompilationSourceResolver
{
    private const NATIVE_EXTENSIONS = ['cpp', 'cxx', 'cc', 'c', 's', 'S', 'm', 'mm'];

    private array $phpFiles = [];
    private array $inventoryFiles = [];
    private array $diagnostics = [];
    private array $variables = [];
    private array $tokens = [];
    private int $position = 0;
    private array $classMap = [];
    private array $psr4 = [];
    private array $psr0 = [];
    private bool $authoritative = false;
    private array $autoloadSources = [];

    public function __construct(private readonly string $projectDirectory)
    {
    }

    public static function isNativeSourcePath(string $path): bool
    {
        return in_array(pathinfo($path, PATHINFO_EXTENSION), self::NATIVE_EXTENSIONS, true);
    }

    /**
     * @param list<array{path:string,category:string,owner:string}> $inventory
     * @param list<string> $explicitSources
     * @return array{sources:list<string>,autoloadSources:list<string>,diagnostics:list<array{file:string,line:int,expression:string,candidates:list<string>}>}
     */
    public function resolve(array $inventory, array $explicitSources = []): array
    {
        $this->phpFiles = $this->inventoryFiles = $this->diagnostics = $this->autoloadSources = [];
        foreach ($inventory as $file) {
            $this->inventoryFiles[$file['path']] = true;
            if ($file['category'] === ProjectDiscovery::BUSINESS_PHP
                || strtolower(pathinfo($file['path'], PATHINFO_EXTENSION)) === 'php') {
                $this->phpFiles[$file['path']] = true;
            }
        }
        $manifest = $this->projectDirectory . '/composer.json';
        $allPhp = !is_file($manifest) && $explicitSources === [];
        $this->psr4 = $this->psr0 = $this->classMap = [];
        $this->authoritative = false;
        $eager = [];
        $vendor = 'vendor';
        try {
            if (is_file($manifest)) {
                $composer = json_decode($this->read($manifest), true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($composer)) {
                    throw new ConfigurationException('Composer manifest is not an object');
                }
                $vendor = $composer['config']['vendor-dir'] ?? 'vendor';
                if (!is_string($vendor) || $this->relative($this->projectDirectory . '/' . $vendor) === null) {
                    throw new ConfigurationException('Composer vendor directory is outside the project');
                }
                $registry = $this->projectDirectory . '/' . $vendor . '/composer';
                $this->psr4 = $this->metadata($registry . '/autoload_psr4.php');
                $this->psr0 = $this->metadata($registry . '/autoload_namespaces.php');
                $this->classMap = $this->metadata($registry . '/autoload_classmap.php');
                $eager = is_file($registry . '/autoload_files.php')
                    ? $this->metadata($registry . '/autoload_files.php') : [];
                foreach ([$this->psr4, $this->psr0] as $map) {
                    foreach ($map as $prefix => $directories) {
                        if (!is_string($prefix) || !is_array($directories)) {
                            throw new ConfigurationException('Unsupported Composer namespace registry');
                        }
                        foreach ($directories as $directory) {
                            if (!is_string($directory)) {
                                throw new ConfigurationException('Unsupported Composer namespace directory');
                            }
                            if (is_dir($directory) && realpath($directory) !== realpath($this->projectDirectory)
                                && $this->relative($directory) === null) {
                                throw new ConfigurationException('Composer namespace directory is outside the project');
                            }
                        }
                    }
                }
                foreach ($this->classMap as $class => $file) {
                    if (!is_string($class) || !is_string($file)
                        || ($path = $this->relative($file)) === null || !$this->includeSource($path)) {
                        throw new ConfigurationException('Composer classmap points outside the readable source inventory');
                    }
                }
                $realLoader = $registry . '/autoload_real.php';
                $this->authoritative = false;
                if (is_file($realLoader)) {
                    $tokens = $this->tokenize($this->read($realLoader));
                    $depth = 0;
                    foreach ($tokens as $i => $token) {
                        if ($token === '{') {
                            ++$depth;
                        } elseif ($token === '}') {
                            --$depth;
                        }
                        if (is_array($token) && $token[0] === T_OBJECT_OPERATOR
                            && ($tokens[$i + 1][1] ?? null) === 'setClassMapAuthoritative') {
                            $argument = $tokens[$i + 3] ?? null;
                            if (($tokens[$i + 2] ?? null) !== '(' || !is_array($argument)
                                || $argument[0] !== T_STRING || !in_array(strtolower($argument[1]), ['true', 'false'], true)
                                || ($tokens[$i + 4] ?? null) !== ')' || $depth !== 2
                                || !in_array($tokens[$i - 2] ?? null, [';', '}'], true)) {
                                throw new ConfigurationException('Unresolved Composer authoritative classmap setting');
                            }
                            $this->authoritative = strtolower($argument[1]) === 'true';
                        }
                        if (is_array($token) && $token[0] === T_OBJECT_OPERATOR
                            && ($tokens[$i + 1][1] ?? null) === 'setUseIncludePath'
                            && strtolower($tokens[$i + 3][1] ?? '') !== 'false') {
                            throw new ConfigurationException('Composer include_path is an open source boundary');
                        }
                    }
                }
            }
        } catch (\Throwable $exception) {
            $this->diagnostic('composer.json', 1, 'Composer source metadata: ' . $exception->getMessage());
            return $this->result($this->phpFiles);
        }

        $selected = $allPhp ? $this->phpFiles : [];
        foreach ($explicitSources as $source) {
            if (isset($this->inventoryFiles[$source])
                && (!self::isNativeSourcePath($source) || isset($this->phpFiles[$source]))
                && $this->includeSource($source)) {
                $selected[$source] = true;
            }
            foreach ($this->phpFiles as $path => $_) {
                if ($source === '.' || $path === $source || str_starts_with($path, rtrim($source, '/') . '/')
                    || (str_contains($source, '*') && fnmatch($source, $path))) {
                    $selected[$path] = true;
                }
            }
        }
        if ($explicitSources === [] && isset($this->phpFiles['main.php'])) {
            $selected['main.php'] = true;
        }
        foreach ($eager as $file) {
            if (is_string($file) && ($path = $this->relative($file)) !== null
                && $this->includeSource($path)) {
                $selected[$path] = true;
            } else {
                $this->diagnostic($vendor . '/composer/autoload_files.php', 1, 'autoload.files points outside the readable source inventory');
                $selected += $this->phpFiles;
            }
        }
        foreach ($this->classMap as $file) {
            $selected[$this->relative($file)] = true;
            $this->autoloadSources[$this->relative($file)] = true;
        }
        foreach ($this->phpFiles as $file => $_) {
            foreach ($this->declarations($this->read($this->projectDirectory . '/' . $file)) as $class) {
                $winner = $this->findFile($class);
                if ($winner !== null && $winner === $file) {
                    $selected[$file] = true;
                    $this->autoloadSources[$file] = true;
                }
            }
        }

        $visited = [];
        while (($pending = array_diff_key($selected, $visited)) !== []) {
            foreach ($pending as $file => $_) {
                $visited[$file] = true;
                $source = $this->read($this->projectDirectory . '/' . $file);
                foreach ($this->includes($source, $file) as $include) {
                    if ($include['paths'] === null) {
                        $this->diagnostic($file, $include['line'], $include['expression']);
                        // These are known PHP candidates, not proof that arbitrary
                        // filenames or evaluated code form a closed source set.
                        $selected += $this->phpFiles;
                        continue;
                    }
                    foreach ($include['paths'] as $path) {
                        if ($this->includeSource($path)) {
                            $selected[$path] = true;
                        } else {
                            $this->diagnostic($file, $include['line'], $include['expression']);
                            $selected += $this->phpFiles;
                        }
                    }
                }
            }
        }
        return $this->result($selected);
    }

    private function includeSource(string $path): bool
    {
        if (!isset($this->inventoryFiles[$path])) {
            return false;
        }
        $this->read($this->projectDirectory . '/' . $path);
        $this->phpFiles[$path] = true;
        return true;
    }

    private function result(array $selected): array
    {
        $sources = array_keys($selected);
        sort($sources, SORT_STRING);
        $autoloadSources = array_keys($this->autoloadSources);
        sort($autoloadSources, SORT_STRING);
        return ['sources' => $sources, 'autoloadSources' => $autoloadSources, 'diagnostics' => $this->diagnostics];
    }

    private function diagnostic(string $file, int $line, string $expression): void
    {
        $this->diagnostics[] = [
            'file' => $file, 'line' => $line, 'expression' => $expression,
            'candidates' => array_keys($this->phpFiles),
        ];
    }

    private function findFile(string $class): ?string
    {
        if (isset($this->classMap[$class])) {
            return is_string($this->classMap[$class]) ? $this->relative($this->classMap[$class]) : null;
        }
        if ($this->authoritative) {
            return null;
        }
        $logical = str_replace('\\', '/', $class) . '.php';
        $prefixes = array_filter(array_keys($this->psr4), static fn(string $prefix): bool =>
            $prefix !== '' && str_starts_with($class, $prefix));
        usort($prefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($prefixes as $prefix) {
            foreach ((array) $this->psr4[$prefix] as $directory) {
                if (($file = $this->existing((string) $directory . '/' . substr($logical, strlen($prefix)))) !== null) {
                    return $file;
                }
            }
        }
        foreach ((array) ($this->psr4[''] ?? []) as $directory) {
            if (($file = $this->existing((string) $directory . '/' . $logical)) !== null) {
                return $file;
            }
        }
        $separator = strrpos($class, '\\');
        $logical = $separator === false
            ? str_replace('_', '/', $class) . '.php'
            : str_replace('\\', '/', substr($class, 0, $separator + 1))
                . str_replace('_', '/', substr($class, $separator + 1)) . '.php';
        foreach ($this->psr0 as $prefix => $directories) {
            if ($prefix !== '' && str_starts_with($class, $prefix)) {
                foreach ((array) $directories as $directory) {
                    if (($file = $this->existing((string) $directory . '/' . $logical)) !== null) {
                        return $file;
                    }
                }
            }
        }
        foreach ((array) ($this->psr0[''] ?? []) as $directory) {
            if (($file = $this->existing((string) $directory . '/' . $logical)) !== null) {
                return $file;
            }
        }
        return null;
    }

    private function existing(string $path): ?string
    {
        $relative = is_file($path) ? $this->relative($path) : null;
        return $relative !== null && isset($this->inventoryFiles[$relative]) ? $relative : null;
    }

    private function relative(string $path): ?string
    {
        $root = realpath($this->projectDirectory);
        $resolved = realpath($path);
        if ($root === false || $resolved === false
            || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $root) . '/')) {
            return null;
        }
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        $lexical = (str_starts_with(str_replace('\\', '/', $path), '/') ? '/' : '') . implode('/', $parts);
        $prefix = str_replace('\\', '/', $root) . '/';
        if (!str_starts_with($lexical, $prefix)) {
            return null;
        }
        // Linux Composer lookup is case-sensitive even when the build host is not.
        return substr($lexical, strlen($prefix));
    }

    /** Read the data expressions emitted by Composer without requiring PHP. */
    private function metadata(string $path): array
    {
        $this->tokens = $this->tokenize($this->read($path));
        $this->position = 0;
        $this->variables = ['__DIR__' => dirname($path)];
        while ($this->position < count($this->tokens)) {
            $token = $this->tokens[$this->position++];
            if (is_array($token) && $token[0] === T_VARIABLE) {
                $this->expect('=');
                $this->variables[$token[1]] = $this->expression();
                $this->expect(';');
            } elseif (is_array($token) && $token[0] === T_RETURN) {
                $result = $this->expression();
                $this->expect(';');
                if (!is_array($result) || $this->position !== count($this->tokens)) {
                    throw new ConfigurationException("Unsupported Composer metadata at {$path}");
                }
                return $result;
            } else {
                throw new ConfigurationException("Unsupported Composer metadata statement at {$path}");
            }
        }
        throw new ConfigurationException("Composer metadata has no data return: {$path}");
    }

    private function expression(): mixed
    {
        $value = $this->primary();
        while (($this->tokens[$this->position] ?? null) === '.') {
            ++$this->position;
            $right = $this->primary();
            if (!is_string($value) || !is_string($right)) {
                throw new ConfigurationException('Unsupported Composer path expression');
            }
            $value .= $right;
        }
        return $value;
    }

    private function primary(): mixed
    {
        $token = $this->tokens[$this->position++] ?? null;
        if ($token === '(') {
            $value = $this->expression();
            $this->expect(')');
            return $value;
        }
        if ($token === '[' || (is_array($token) && $token[0] === T_ARRAY)) {
            $end = $token === '[' ? ']' : ')';
            if ($end === ')') {
                $this->expect('(');
            }
            $values = [];
            while (($this->tokens[$this->position] ?? null) !== $end) {
                $value = $this->expression();
                $next = $this->tokens[$this->position] ?? null;
                if (is_array($next) && $next[0] === T_DOUBLE_ARROW) {
                    ++$this->position;
                    if (!is_string($value) && !is_int($value)) {
                        throw new ConfigurationException('Unsupported Composer array key');
                    }
                    $values[$value] = $this->expression();
                } else {
                    $values[] = $value;
                }
                if (($this->tokens[$this->position] ?? null) === ',') {
                    ++$this->position;
                } elseif (($this->tokens[$this->position] ?? null) !== $end) {
                    throw new ConfigurationException('Unsupported Composer array expression');
                }
            }
            ++$this->position;
            return $values;
        }
        if (is_array($token)) {
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                return $token[1][0] === "'"
                    ? str_replace(["\\\\", "\\'"], ["\\", "'"], substr($token[1], 1, -1))
                    : stripcslashes(substr($token[1], 1, -1));
            }
            if ($token[0] === T_LNUMBER) {
                return (int) $token[1];
            }
            if ($token[0] === T_DIR) {
                return $this->variables['__DIR__'];
            }
            if ($token[0] === T_FILE && isset($this->variables['__FILE__'])) {
                return $this->variables['__FILE__'];
            }
            if ($token[0] === T_VARIABLE && array_key_exists($token[1], $this->variables)) {
                return $this->variables[$token[1]];
            }
            if ($token[0] === T_STRING && $token[1] === 'dirname') {
                $this->expect('(');
                $path = $this->expression();
                $levels = 1;
                if (($this->tokens[$this->position] ?? null) === ',') {
                    ++$this->position;
                    $levels = $this->expression();
                }
                $this->expect(')');
                if (is_string($path) && is_int($levels) && $levels > 0) {
                    return dirname($path, $levels);
                }
            }
        }
        throw new ConfigurationException('Unsupported Composer data expression');
    }

    private function expect(string $token): void
    {
        if (($this->tokens[$this->position++] ?? null) !== $token) {
            throw new ConfigurationException('Unsupported Composer data syntax');
        }
    }

    private function tokenize(string $source): array
    {
        return array_values(array_filter(token_get_all($source), static fn($token): bool =>
            !is_array($token) || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    }

    private function declarations(string $source): array
    {
        $tokens = $this->tokenize($source);
        $namespace = '';
        $classes = [];
        $depth = 0;
        $namespaceDepth = null;
        foreach ($tokens as $i => $token) {
            if ($token === '{') {
                ++$depth;
            } elseif ($token === '}') {
                if ($namespaceDepth === $depth) {
                    $namespace = '';
                    $namespaceDepth = null;
                }
                --$depth;
            } elseif (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== ';' && $tokens[$j] !== '{'; ++$j) {
                    $namespace .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }
                $namespaceDepth = ($tokens[$j] ?? null) === '{' ? $depth + 1 : null;
            } elseif (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $next = $tokens[$i + 1] ?? null;
                $previous = $tokens[$i - 1] ?? null;
                if (is_array($next) && $next[0] === T_STRING
                    && !(is_array($previous) && $previous[0] === T_DOUBLE_COLON)) {
                    $classes[] = ($namespace === '' ? '' : $namespace . '\\') . $next[1];
                }
            }
        }
        return $classes;
    }

    private function includes(string $source, string $file): array
    {
        $tokens = $this->tokenize($source);
        $includes = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || !in_array($token[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL], true)) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            if ($previous === '&' || (is_array($previous) && $previous[1] === '&')) {
                $previous = $tokens[$i - 2] ?? null;
            }
            if (is_array($previous) && in_array($previous[0], [
                T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
            ], true)) {
                continue;
            }
            $expression = [];
            $depth = 0;
            for ($j = $i + 1; isset($tokens[$j]); ++$j) {
                if (($tokens[$j] === ';' || $tokens[$j] === ',') && $depth === 0) {
                    break;
                }
                if ($tokens[$j] === '(' || $tokens[$j] === '[') {
                    ++$depth;
                } elseif ($tokens[$j] === ')' || $tokens[$j] === ']') {
                    if ($depth === 0) {
                        break;
                    }
                    --$depth;
                }
                $expression[] = $tokens[$j];
            }
            $text = implode('', array_map(static fn($part): string => is_array($part) ? $part[1] : $part, $expression));
            $this->tokens = $expression;
            $this->position = 0;
            $this->variables = [
                '__DIR__' => dirname($this->projectDirectory . '/' . $file),
                '__FILE__' => $this->projectDirectory . '/' . $file,
            ];
            $path = null;
            try {
                $value = $token[0] === T_EVAL ? null : $this->expression();
                if ($this->position === count($this->tokens) && is_string($value)
                    && str_starts_with($value, '/')) {
                    $path = $this->relative($value);
                }
            } catch (ConfigurationException) {
                // An unproven runtime filename is an open source boundary.
            }
            $includes[] = [
                'line' => $token[2],
                'expression' => $token[0] === T_EVAL ? 'eval' . $text : $text,
                'paths' => $path === null ? null : [$path],
            ];
        }
        return $includes;
    }

    private function read(string $path): string
    {
        $source = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($source)) {
            throw new ConfigurationException("Source metadata missing or unsafe: {$path}");
        }
        return $source;
    }
}
