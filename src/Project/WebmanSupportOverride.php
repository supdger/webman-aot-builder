<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

final class WebmanSupportOverride
{
    public static function canReplace(string $mirror, string $name): bool
    {
        if (!in_array($name, ['Request', 'Response'], true)) {
            return false;
        }
        $fallback = self::read($mirror, "vendor/workerman/webman-framework/src/support/{$name}.php");
        $override = self::read($mirror, "support/{$name}.php");
        if ($fallback === null || $override === null) {
            return false;
        }
        $tokens = self::tokens($fallback);
        if ($tokens === null || count($tokens) !== 10
            || !is_array($tokens[0]) || $tokens[0][0] !== T_OPEN_TAG
            || !is_array($tokens[1]) || $tokens[1][0] !== T_NAMESPACE
            || !is_array($tokens[2]) || strcasecmp($tokens[2][1], 'support') !== 0
            || $tokens[3] !== ';'
            || !is_array($tokens[4]) || $tokens[4][0] !== T_CLASS
            || !is_array($tokens[5]) || strcasecmp($tokens[5][1], $name) !== 0
            || !is_array($tokens[6]) || $tokens[6][0] !== T_EXTENDS
            || !is_array($tokens[7]) || strcasecmp($tokens[7][1], '\\Webman\\Http\\' . $name) !== 0
            || $tokens[8] !== '{' || $tokens[9] !== '}'
        ) {
            return false;
        }
        return self::declaresOverride($override, $name);
    }

    private static function declaresOverride(string $source, string $name): bool
    {
        $tokens = self::tokens($source);
        if ($tokens === null) {
            return false;
        }
        $namespace = '';
        $namespaceDepth = 0;
        $depth = 0;
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE && $depth === 0) {
                $namespace = '';
                for ($next = $index + 1; isset($tokens[$next]); ++$next) {
                    $part = $tokens[$next];
                    if ($part === ';' || $part === '{') {
                        $namespaceDepth = $part === '{' ? $depth + 1 : $depth;
                        break;
                    }
                    $namespace .= is_array($part) ? $part[1] : $part;
                }
            }
            if ($depth === $namespaceDepth && strcasecmp($namespace, 'support') === 0
                && is_array($token) && $token[0] === T_CLASS
                && is_array($tokens[$index + 1] ?? null)
                && strcasecmp($tokens[$index + 1][1], $name) === 0
                && is_array($tokens[$index + 2] ?? null) && $tokens[$index + 2][0] === T_EXTENDS
                && is_array($tokens[$index + 3] ?? null)
                && strcasecmp($tokens[$index + 3][1], '\\Webman\\Http\\' . $name) === 0
            ) {
                return true;
            }
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                ++$depth;
            } elseif ($token === '}') {
                --$depth;
                if ($depth < $namespaceDepth) {
                    $namespace = '';
                    $namespaceDepth = 0;
                }
            }
        }
        return false;
    }

    /** @return list<array|string>|null */
    private static function tokens(string $source): ?array
    {
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError) {
            return null;
        }
        return array_values(array_filter($tokens, static fn(mixed $token): bool =>
            !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    }

    private static function read(string $mirror, string $relative): ?string
    {
        $path = rtrim($mirror, '/\\');
        foreach (explode('/', $relative) as $component) {
            $path .= '/' . $component;
            if (is_link($path)) {
                return null;
            }
        }
        $source = is_file($path) ? file_get_contents($path) : false;
        return is_string($source) ? $source : null;
    }
}
