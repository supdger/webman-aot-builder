<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectMirror;
use WebmanAotBuilder\Project\ProjectDiscovery;

final class ConditionalDeclarationSelector
{
    /**
     * Resolve declaration-only guards from the compilation's own unconditional types.
     * Unknown runtime/SDK types and conditions remain original compiler inputs.
     *
     * @param list<array{path:string,category:string,owner:string}> $files
     * @param list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}> $existing
     * @return list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}>
     */
    public function apply(string $mirror, array $files, array $existing, array $targetCapabilities = []): array
    {
        $directory = ProjectMirror::generationSourceDirectory($mirror);
        if ($directory === null) { return []; }
        $relative = substr($directory, strlen($mirror) + 1);
        $sources = [];
        $types = [];
        $replaced = array_column($existing, 'shadow', 'path');
        foreach ($files as $file) {
            if ($file['category'] !== ProjectDiscovery::BUSINESS_PHP) {
                continue;
            }
            $path = $replaced[$file['path']] ?? $file['path'];
            $source = $this->read($mirror . '/' . $path);
            $sources[$file['path']] = $source;
            foreach ($this->declarations($source) as $name => $kind) {
                $types[strtolower($name)][$kind][$file['path']] = true;
            }
        }
        $bindings = new PhpFunctionBindings($sources);
        $mappings = [];
        foreach ($sources as $path => $source) {
            if (isset($replaced[$path]) || (!str_contains($source, '_exists')
                    && !str_contains($source, 'PHP_VERSION') && !str_contains($source, 'phpversion')
                    && !str_contains($source, 'extension_loaded'))) {
                continue;
            }
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_DIR, T_FILE, T_LINE, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) { continue 2; }
            }
            try {
                token_get_all($source, TOKEN_PARSE);
                $selected = $this->select($source, $path, $types, $targetCapabilities, $bindings);
                if ($selected === $source) { continue; }
                token_get_all($selected, TOKEN_PARSE);
            } catch (\ParseError) { continue; }
            $shadow = $relative . '/conditional-' . hash('sha256', $path) . '.php';
            if (is_link($mirror . '/' . $shadow)
                || file_put_contents($mirror . '/' . $shadow, $selected, LOCK_EX) !== strlen($selected)
            ) {
                throw new ConfigurationException("cannot write conditional declaration shadow: {$path}");
            }
            $mappings[] = ['path' => $path, 'shadow' => $shadow,
                'sourceSha256' => hash('sha256', $source), 'shadowSha256' => hash('sha256', $selected)];
            fwrite(STDERR, "[adapt] selected conditional declarations {$path}\n");
        }
        return $mappings;
    }

    private function select(string $source, string $path, array $types, array $targetCapabilities, PhpFunctionBindings $bindings): string
    {
        $tokens = token_get_all($source);
        $namespace = '';
        $aliases = [];
        $unresolvedImports = false;
        $functionImports = [];
        $unresolvedFunctionImports = false;
        $constantImports = [];
        $unresolvedConstantImports = false;
        $depth = 0;
        $output = '';
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            $id = is_array($token) ? $token[0] : null;
            $text = $this->text($token);
            if ($depth === 0 && $id === T_NAMESPACE) {
                $namespace = $this->namespaceAt($tokens, $index);
            } elseif ($depth === 0 && $id === T_USE) {
                $import = '';
                for ($next = $index + 1; $next < $count && $tokens[$next] !== ';'; ++$next) {
                    $import .= $this->text($tokens[$next]);
                }
                if (str_contains($import, '{')) {
                    $unresolvedFunctionImports = $unresolvedFunctionImports || preg_match('/\bfunction\b/', $import) === 1;
                    $unresolvedConstantImports = $unresolvedConstantImports || preg_match('/\bconst\b/', $import) === 1;
                }
                if (preg_match('/^\s*const\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*$/iD', $import, $match) === 1) {
                    $parts = explode('\\', $match[1]);
                    $constantImports[strtolower($match[2] ?? end($parts))] = true;
                } elseif (preg_match('/\bconst\b/', $import) === 1) {
                    $unresolvedConstantImports = true;
                } elseif (preg_match('/^\s*function\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*$/iD', $import, $match) === 1) {
                    $parts = explode('\\', $match[1]);
                    $functionImports[strtolower($match[2] ?? end($parts))] = true;
                } elseif (preg_match('/\bfunction\b/', $import) === 1) {
                    $unresolvedFunctionImports = true;
                } elseif (preg_match('/^\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*$/iD', $import, $match) === 1) {
                    $name = ltrim($match[1], '\\');
                    $parts = explode('\\', $name);
                    $aliases[strtolower($match[2] ?? end($parts))] = $name;
                } elseif (preg_match('/^\s*(?:function|const)\s+/', $import) !== 1) {
                    $unresolvedImports = true;
                }
            } elseif ($depth === 0 && $id === T_IF) {
                $conditional = $this->conditional($tokens, $index);
                if ($conditional !== null && !$bindings->builtinCalls($conditional['condition'], $namespace, $functionImports, $unresolvedFunctionImports, $constantImports, $unresolvedConstantImports)) {
                    $conditional = null;
                }
                if ($conditional !== null) {
                    $decision = $this->targetDecision($conditional['condition'], $targetCapabilities);
                    $trueTypes = $this->branchDeclarations($conditional['true']);
                    $falseTypes = $conditional['false'] === null ? [] : $this->branchDeclarations($conditional['false']);
                    if ($decision !== null && $trueTypes !== null && $falseTypes !== null
                        && ($conditional['false'] === null || $trueTypes === $falseTypes)
                    ) {
                        $output .= $decision ? $conditional['true'] : ($conditional['false'] ?? '');
                        $index = $conditional['next'] - 1;
                        continue;
                    }
                }
                if ($conditional !== null
                    && preg_match('/^\s*(!\s*)?\\\\?(interface_exists|class_exists)\s*\(\s*(?:([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::\s*class|([\'"])([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\4)\s*\)\s*$/iD', $conditional['condition'], $condition) === 1
                ) {
                    $literal = ($condition[3] ?? '') === '';
                    $name = $literal ? ($condition[5] ?? '') : $condition[3];
                    if (!$literal) {
                        $parts = explode('\\', $name);
                        $first = array_shift($parts);
                        if ($unresolvedImports && !str_starts_with($name, '\\')
                            && !isset($aliases[strtolower($first)])
                        ) {
                            $output .= $text;
                            continue;
                        }
                        $name = str_starts_with($name, '\\') ? ltrim($name, '\\')
                            : (($aliases[strtolower($first)] ?? ($namespace === '' ? $first : $namespace . '\\' . $first))
                                . ($parts === [] ? '' : '\\' . implode('\\', $parts)));
                    }
                    $kind = strtolower($condition[2]) === 'interface_exists' ? T_INTERFACE : T_CLASS;
                    $definitions = $types[strtolower($name)][$kind] ?? [];
                    unset($definitions[$path]);
                    $trueTypes = $this->branchDeclarations($conditional['true']);
                    $falseTypes = $conditional['false'] === null ? [] : $this->branchDeclarations($conditional['false']);
                    $declaredTypes = $trueTypes === null ? [] : array_map(
                        static fn(string $name): string => strtolower(($namespace === '' ? '' : $namespace . '\\') . $name),
                        array_keys($trueTypes)
                    );
                    if ($definitions !== [] && $trueTypes !== null && $falseTypes !== null
                        && ($conditional['false'] === null || $trueTypes === $falseTypes)
                        && !in_array(strtolower($name), $declaredTypes, true)
                    ) {
                        $output .= ($condition[1] ?? '') === '' ? $conditional['true'] : ($conditional['false'] ?? '');
                        $index = $conditional['next'] - 1;
                        continue;
                    }
                }
            }
            if ($token === '{' || ($id !== null && in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                ++$depth;
            } elseif ($token === '}') {
                --$depth;
            }
            $output .= $text;
        }
        return $output;
    }

    private function targetDecision(string $condition, array $capabilities): ?bool
    {
        if ($capabilities === []) {
            return null;
        }
        $condition = trim($condition);
        if (preg_match('/^\\\\?PHP_VERSION_ID\s*(>=|<=|===|!==|==|!=|>|<)\s*(\d+)$/D', $condition, $match) === 1
            && is_int($capabilities['phpVersionId'] ?? null)
        ) {
            return $this->compare($capabilities['phpVersionId'], (int) $match[2], $match[1]);
        }
        if (preg_match('/^(!\s*)?\\\\?extension_loaded\s*\(\s*([\'"])([A-Za-z0-9_]+)\2\s*\)$/D', $condition, $match) === 1
        ) {
            $name = strtolower($match[3]);
            if (($capabilities['registrationComplete'] ?? false) === true) {
                $loaded = isset($capabilities['modules'][$name]);
                return ($match[1] ?? '') === '' ? $loaded : !$loaded;
            }
            return isset($capabilities['extensionVersions'][$name]) ? ($match[1] ?? '') === '' : null;
        }
        if (preg_match('/^\\\\?version_compare\s*\(\s*(\\\\?PHP_VERSION|\\\\?phpversion\s*\(\s*([\'"])([A-Za-z0-9_]+)\2\s*\))\s*,\s*([\'"])([^\'"]+)\4\s*,\s*([\'"])(>=|<=|==|!=|>|<|ge|le|eq|ne|gt|lt)\6\s*\)$/D', $condition, $match) !== 1) {
            return null;
        }
        if (ltrim($match[1], '\\') === 'PHP_VERSION') {
            $version = $capabilities['phpVersion'] ?? null;
        } else {
            $extension = strtolower($match[3]);
            $version = $capabilities['extensionVersions'][$extension] ?? null;
            if ($version === null && ($capabilities['registrationComplete'] ?? false) === true
                && !isset($capabilities['modules'][$extension])
            ) {
                $version = '';
            }
        }
        return is_string($version) ? version_compare($version, $match[5], $match[7]) : null;
    }

    private function compare(int $left, int $right, string $operator): bool
    {
        return match ($operator) {
            '>=', 'ge' => $left >= $right,
            '<=', 'le' => $left <= $right,
            '==', '===', 'eq' => $left === $right,
            '!=', '!==', 'ne' => $left !== $right,
            '>', 'gt' => $left > $right,
            '<', 'lt' => $left < $right,
        };
    }

    /** @return array{condition:string,true:string,false:?string,next:int}|null */
    private function conditional(array $tokens, int $start): ?array
    {
        $index = $this->skip($tokens, $start + 1);
        if (($tokens[$index] ?? null) !== '(') {
            return null;
        }
        $condition = '';
        $depth = 1;
        for (++$index; isset($tokens[$index]); ++$index) {
            $token = $tokens[$index];
            if ($token === '(') {
                ++$depth;
            } elseif ($token === ')' && --$depth === 0) {
                ++$index;
                break;
            }
            $condition .= $this->text($token);
        }
        if ($depth !== 0) {
            return null;
        }
        $true = $this->block($tokens, $index);
        if ($true === null) {
            return null;
        }
        $index = $this->skip($tokens, $index);
        $false = null;
        if (is_array($tokens[$index] ?? null) && $tokens[$index][0] === T_ELSE) {
            ++$index;
            $false = $this->block($tokens, $index);
            if ($false === null) {
                return null;
            }
        } elseif (is_array($tokens[$index] ?? null) && $tokens[$index][0] === T_ELSEIF) {
            return null;
        }
        return ['condition' => $condition, 'true' => $true, 'false' => $false, 'next' => $index];
    }

    private function block(array $tokens, int &$index): ?string
    {
        $index = $this->skip($tokens, $index);
        if (($tokens[$index] ?? null) !== '{') {
            return null;
        }
        $body = '';
        $depth = 1;
        for (++$index; isset($tokens[$index]); ++$index) {
            $token = $tokens[$index];
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                ++$depth;
            } elseif ($token === '}' && --$depth === 0) {
                ++$index;
                return $body;
            }
            $body .= $this->text($token);
        }
        return null;
    }

    /** @return array<string,int>|null */
    public function branchDeclarations(string $body): ?array
    {
        $tokens = token_get_all('<?php ' . $body);
        $names = [];
        $depth = 0;
        $inHeader = false;
        foreach ($tokens as $index => $token) {
            $id = is_array($token) ? $token[0] : null;
            if ($depth === 0 && !$inHeader) {
                if (in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                    continue;
                }
                if (!in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    return null;
                }
                $next = $this->skip($tokens, $index + 1);
                if (!is_array($tokens[$next] ?? null) || $tokens[$next][0] !== T_STRING) {
                    return null;
                }
                $names[strtolower($tokens[$next][1])] = $id;
                $inHeader = true;
            }
            if ($token === '{' || ($id !== null && in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                ++$depth;
                $inHeader = false;
            } elseif ($token === '}') {
                --$depth;
            }
        }
        ksort($names, SORT_STRING);
        return $depth === 0 && !$inHeader && $names !== [] ? $names : null;
    }

    /** @return array<string,int> */
    public function declarations(string $source): array
    {
        $tokens = token_get_all($source);
        $namespace = '';
        $depth = 0;
        $types = [];
        foreach ($tokens as $index => $token) {
            $id = is_array($token) ? $token[0] : null;
            if ($depth === 0 && $id === T_NAMESPACE) {
                $namespace = $this->namespaceAt($tokens, $index);
            } elseif ($depth === 0 && in_array($id, [T_CLASS, T_INTERFACE], true)) {
                $next = $this->skip($tokens, $index + 1);
                if (is_array($tokens[$next] ?? null) && $tokens[$next][0] === T_STRING) {
                    $name = ($namespace === '' ? '' : $namespace . '\\') . $tokens[$next][1];
                    $types[$name] = $id;
                }
            }
            if ($token === '{' || ($id !== null && in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                ++$depth;
            } elseif ($token === '}') {
                --$depth;
            }
        }
        return $types;
    }

    private function namespaceAt(array $tokens, int $index): string
    {
        $name = '';
        for (++$index; isset($tokens[$index]) && !in_array($tokens[$index], [';', '{'], true); ++$index) {
            if (is_array($tokens[$index]) && in_array($tokens[$index][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $tokens[$index][1];
            }
        }
        return $name;
    }

    private function skip(array $tokens, int $index): int
    {
        while (is_array($tokens[$index] ?? null) && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            ++$index;
        }
        return $index;
    }

    private function text(array|string $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }

    private function read(string $path): string
    {
        $source = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($source)) {
            throw new ConfigurationException("conditional declaration input is missing or unsafe: {$path}");
        }
        return $source;
    }
}
