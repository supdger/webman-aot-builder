<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

/** The four framework template adapters are the only PHP allowed to render at runtime. */
final class WebmanViewAdapterPolicy
{
    public function validate(string $source, string $name): void
    {
        if (!in_array($name, ['Blade', 'Raw', 'ThinkPHP', 'Twig'], true)) {
            throw new ConfigurationException("unregistered Webman runtime view adapter: {$name}");
        }
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $exception) {
            throw new ConfigurationException("Webman runtime view adapter is invalid PHP: {$name}", previous: $exception);
        }
        $tokens = array_values(array_filter($tokens, static fn($token): bool => !is_array($token)
            || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $text = static fn($token): string => is_array($token) ? $token[1] : $token;
        $namespace = false;
        $viewImport = false;
        $body = null;
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && $token[0] === T_OPEN_TAG) {
                continue;
            }
            if (is_array($token) && in_array($token[0], [T_NAMESPACE, T_USE, T_DECLARE], true)) {
                $declaration = '';
                $kind = $token[0];
                while (++$index < $count && $tokens[$index] !== ';') {
                    $declaration .= $text($tokens[$index]);
                }
                if ($kind === T_NAMESPACE) {
                    if ($namespace || $declaration !== 'support\\view') {
                        throw new ConfigurationException("Webman runtime view namespace drifted: {$name}");
                    }
                    $namespace = true;
                } elseif ($kind === T_USE && $declaration === 'Webman\\View') {
                    $viewImport = true;
                } elseif ($kind === T_DECLARE && $declaration !== '(strict_types=1)') {
                    throw new ConfigurationException("Webman runtime view declaration is unsupported: {$name}");
                }
                continue;
            }
            if (is_array($token) && $token[0] === T_FINAL) {
                continue;
            }
            if (is_array($token) && $token[0] === T_CLASS && $body === null) {
                $header = '';
                while (++$index < $count && $tokens[$index] !== '{') {
                    $header .= $text($tokens[$index]);
                }
                if (!$namespace || !in_array($header, [
                    $name . 'implements\\Webman\\View',
                    $viewImport ? $name . 'implementsView' : '',
                ], true)) {
                    throw new ConfigurationException("Webman runtime view class contract drifted: {$name}");
                }
                $body = [];
                $depth = 1;
                while (++$index < $count && $depth > 0) {
                    $entry = $tokens[$index];
                    if ($entry === '{' || (is_array($entry) && in_array($entry[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                        $depth++;
                    } elseif ($entry === '}') {
                        $depth--;
                    }
                    if ($depth > 0) {
                        $body[] = $entry;
                    }
                }
                $index--;
                continue;
            }
            throw new ConfigurationException("Webman runtime view contains executable top-level PHP: {$name}");
        }
        if (!is_array($body)) {
            throw new ConfigurationException("Webman runtime view class is missing: {$name}");
        }
        $methods = [];
        $includes = [];
        for ($index = 0, $count = count($body); $index < $count; $index++) {
            $entry = $body[$index];
            if (!is_array($entry)) {
                continue;
            }
            if (in_array($entry[0], [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                throw new ConfigurationException("Webman runtime view has unsupported dynamic execution: {$name}");
            }
            if ($entry[0] !== T_FUNCTION) {
                continue;
            }
            $methodName = $body[$index + 1] ?? null;
            if (!is_array($methodName) || $methodName[0] !== T_STRING) {
                throw new ConfigurationException("Webman runtime view has unsupported function declaration: {$name}");
            }
            $methodName = $methodName[1];
            $modifiers = [];
            $modifierIndex = $index - 1;
            while ($modifierIndex >= 0 && is_array($body[$modifierIndex])
                && in_array($body[$modifierIndex][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL], true)
            ) {
                $modifiers[] = $body[$modifierIndex--][0];
            }
            if (in_array($methodName, ['assign', 'render'], true)
                && (!in_array(T_STATIC, $modifiers, true)
                    || in_array(T_PRIVATE, $modifiers, true)
                    || in_array(T_PROTECTED, $modifiers, true))
            ) {
                throw new ConfigurationException("Webman runtime view method visibility drifted: {$name}::{$methodName}");
            }
            $header = '';
            $method = [];
            while (++$index < $count && $body[$index] !== '{') {
                $header .= $text($body[$index]);
            }
            $depth = 1;
            while (++$index < $count && $depth > 0) {
                $current = $body[$index];
                if ($current === '{' || (is_array($current) && in_array($current[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($current === '}') {
                    $depth--;
                }
                if (is_array($current) && in_array($current[0], [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                    if ($name !== 'Raw' || $methodName !== 'render' || $current[0] !== T_INCLUDE
                        || $text($body[$index + 1] ?? '') !== '$__template_path__'
                        || ($body[$index + 2] ?? null) !== ';'
                    ) {
                        throw new ConfigurationException("Webman runtime view has unsupported dynamic execution: {$name}::{$methodName}");
                    }
                    $includes[] = $methodName;
                }
                $method[] = $current;
            }
            $methods[$methodName] = ['header' => $header, 'body' => $method];
            $index--;
        }
        foreach (['assign' => ':void', 'render' => ':string'] as $methodName => $returnType) {
            if (!isset($methods[$methodName])
                || !str_ends_with($methods[$methodName]['header'], $returnType)
            ) {
                throw new ConfigurationException("Webman runtime view method contract drifted: {$name}::{$methodName}");
            }
        }
        $render = $methods['render']['body'];
        if ($name === 'Raw') {
            $template = '$__template_path__=$template[0]===\'/\'?base_path()."$template.$viewSuffix":($app===\'\'?"$baseViewPath/view/$template.$viewSuffix":"$baseViewPath/$app/view/$template.$viewSuffix");';
            $pathUses = array_filter($render, static fn($token): bool => is_array($token)
                && $token[0] === T_VARIABLE && $token[1] === '$__template_path__');
            if (count($includes) !== 1 || count($pathUses) !== 2 || !$this->contains($render, $template)
                || !$this->contains($render, 'ob_start();') || !$this->contains($render, 'return ob_get_clean();')
            ) {
                throw new ConfigurationException('Webman Raw runtime template include contract drifted');
            }
        } else {
            $engine = match ($name) {
                'Blade' => 'new BladeView(',
                'ThinkPHP' => 'new Template(',
                'Twig' => 'new Environment(new FilesystemLoader(',
            };
            $call = $name === 'ThinkPHP' ? '->fetch(' : '->render(';
            if (!$this->contains($render, $engine) || !$this->contains($render, $call)) {
                throw new ConfigurationException("Webman runtime view engine delegation drifted: {$name}");
            }
        }
    }

    /** @param list<array|string> $tokens */
    private function contains(array $tokens, string $contract): bool
    {
        $expected = array_values(array_filter(token_get_all('<?php ' . $contract), static fn($token): bool => !is_array($token)
            || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        for ($index = 0; $index <= count($tokens) - count($expected); $index++) {
            foreach ($expected as $offset => $token) {
                $actual = $tokens[$index + $offset];
                if (is_array($token)) {
                    if (!is_array($actual) || $actual[0] !== $token[0] || $actual[1] !== $token[1]) {
                        continue 2;
                    }
                } elseif ($actual !== $token) {
                    continue 2;
                }
            }
            return true;
        }
        return false;
    }
}
