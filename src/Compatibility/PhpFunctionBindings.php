<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

final class PhpFunctionBindings
{
    private array $functions = [];
    private array $constants = [];

    /** @param array<string,string> $sources */
    public function __construct(array $sources)
    {
        foreach ($sources as $source) {
            $tokens = token_get_all($source);
            $namespace = '';
            $namespaceDepth = null;
            $depth = 0;
            $classes = [];
            $classPending = false;
            foreach ($tokens as $index => $token) {
                $id = is_array($token) ? $token[0] : null;
                if ($id === T_NAMESPACE) {
                    $namespace = '';
                    for ($next = $index + 1; isset($tokens[$next]); ++$next) {
                        if ($tokens[$next] === ';' || $tokens[$next] === '{') {
                            $namespaceDepth = $tokens[$next] === '{' ? $depth + 1 : null;
                            break;
                        }
                        if (is_array($tokens[$next]) && in_array($tokens[$next][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                            $namespace .= $tokens[$next][1];
                        }
                    }
                } elseif (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    $previous = $this->significant($tokens, $index - 1, -1);
                    if (($tokens[$previous] ?? null) !== '::'
                        && (!is_array($tokens[$previous] ?? null) || $tokens[$previous][0] !== T_DOUBLE_COLON)
                    ) {
                        $classPending = true;
                    }
                } elseif ($id === T_CONST && $classes === []) {
                    $previous = $this->significant($tokens, $index - 1, -1);
                    if (!is_array($tokens[$previous] ?? null) || $tokens[$previous][0] !== T_USE) {
                        for ($next = $index + 1; isset($tokens[$next]) && $tokens[$next] !== ';'; ++$next) {
                            if (is_array($tokens[$next]) && $tokens[$next][0] === T_STRING
                                && ($tokens[$this->significant($tokens, $next + 1, 1)] ?? null) === '='
                            ) {
                                $this->constants[strtolower(($namespace === '' ? '' : $namespace . '\\') . $tokens[$next][1])] = true;
                            }
                        }
                    }
                } elseif ($id === T_FUNCTION && ($classes === [] || end($classes) !== $depth)) {
                    $next = $this->significant($tokens, $index + 1, 1);
                    if (($tokens[$next] ?? null) === '&'
                        || (is_array($tokens[$next] ?? null) && in_array($tokens[$next][0], [T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true))
                    ) {
                        $next = $this->significant($tokens, $next + 1, 1);
                    }
                    if (is_array($tokens[$next] ?? null) && $tokens[$next][0] === T_STRING) {
                        $this->functions[strtolower(($namespace === '' ? '' : $namespace . '\\') . $tokens[$next][1])] = true;
                    }
                }
                if ($token === '{' || in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    ++$depth;
                    if ($classPending) {
                        $classes[] = $depth;
                        $classPending = false;
                    }
                } elseif ($token === '}') {
                    if ($classes !== [] && end($classes) === $depth) {
                        array_pop($classes);
                    }
                    if ($namespaceDepth === $depth) {
                        $namespace = '';
                        $namespaceDepth = null;
                    }
                    --$depth;
                }
            }
        }
    }

    /** All function calls in this selector must resolve to PHP builtins. */
    public function builtinCalls(string $condition, string $namespace, array $imports, bool $unresolvedImports, array $constantImports = [], bool $unresolvedConstants = false): bool
    {
        $tokens = token_get_all('<?php ' . $condition);
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }
            $next = $this->significant($tokens, $index + 1, 1);
            $qualified = str_starts_with($token[1], '\\');
            $name = strtolower(ltrim($token[1], '\\'));
            if (in_array($name, ['php_version', 'php_version_id'], true)
                && (isset($this->constants[$name])
                    || (!$qualified && ($unresolvedConstants || isset($constantImports[$name])
                        || ($namespace !== '' && isset($this->constants[strtolower($namespace . '\\' . $name)])))))
            ) {
                return false;
            }
            if (($tokens[$next] ?? null) !== '(') {
                continue;
            }
            if (!in_array($name, ['class_exists', 'interface_exists', 'extension_loaded', 'phpversion', 'version_compare'], true)
                || isset($this->functions[$name])
            ) {
                return false;
            }
            if (!$qualified && ($unresolvedImports || isset($imports[$name])
                    || ($namespace !== '' && isset($this->functions[strtolower($namespace . '\\' . $name)])))
            ) {
                return false;
            }
        }
        return true;
    }

    private function significant(array $tokens, int $index, int $step): int
    {
        while (is_array($tokens[$index] ?? null) && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $index += $step;
        }
        return $index;
    }
}
