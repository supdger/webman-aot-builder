<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class CaptchaFontResourcePolicy
{
    private const DEFAULT_SELECTION = <<<'PHP'
if ($font === null) {
    $font = $this->getFontPath(__DIR__ . '/Font/captcha'.$this->rand(0, 4).'.ttf');
}
PHP;

    private const PHAR_FONT_PATH = <<<'PHP'
static $fontPathMap = [];
if (!\class_exists(\Phar::class, false) || !\Phar::running()) {
    return $font;
}
$tmpPath = sys_get_temp_dir() ?: '/tmp';
if (function_exists('runtime_path')) {
    $tmpPath = runtime_path('tmp');
    if (!is_dir($tmpPath)) {
        mkdir($tmpPath, 0777, true);
    }
}
$filePath = "$tmpPath/" . basename($font);
clearstatcache();
if (!isset($fontPathMap[$font]) || !is_file($filePath)) {
    file_put_contents($filePath, file_get_contents($font));
    $fontPathMap[$font] = $filePath;
}
return $fontPathMap[$font];
PHP;

    /** @return array{minimum:int,maximum:int} */
    public function validate(string $source): array
    {
        $sourceTokens = $this->tokens($source);
        $imports = $this->functionImports($sourceTokens);
        $tokens = $this->classBody($sourceTokens);
        $methods = $this->methods($tokens);
        $build = $methods['build'] ?? null;
        if ($build === null) {
            throw new ConfigurationException('webman/captcha runtime font selection structure is unsupported');
        }
        foreach (['build', 'writephrase', 'buildagainstocr'] as $method) {
            if (isset($methods[$method])) {
                $this->assertFontParameter($methods[$method]['header'], $method);
                $this->assertNoSymbolInjection($methods[$method]['body'], $imports, $method);
            }
        }
        $selections = [];
        foreach ([self::DEFAULT_SELECTION, str_replace(["\$font = \$this->getFontPath(", "'.ttf');"], ["\$font = ", "'.ttf';"], self::DEFAULT_SELECTION)] as $contract) {
            $expected = $this->tokens('<?php ' . $contract);
            $depth = 0;
            foreach ($build['body'] as $index => $token) {
                if ($depth === 0) {
                    $range = $this->selectionAt($build['body'], $index, $expected);
                    if ($range !== null) {
                        $selections[] = ['index' => $index, 'length' => count($expected), 'range' => $range, 'helper' => str_contains($contract, 'getFontPath')];
                    }
                }
                if ($token['text'] === '{' || in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                } elseif ($token['text'] === '}') {
                    $depth--;
                }
            }
        }
        if (count($selections) !== 1) {
            throw new ConfigurationException('webman/captcha runtime font selection structure is unsupported');
        }
        $selection = $selections[0];
        $allowed = array_fill_keys(range($selection['index'], $selection['index'] + $selection['length'] - 1), true);
        $this->allowFontArguments($build['body'], ['writephrase' => 2], $allowed, $imports);
        $this->assertFontUses($build['body'], $allowed, 'build');

        $helperCalls = 0;
        $fontLiterals = 0;
        foreach ($tokens as $index => $token) {
            if ($token['text'] === 'getfontpath' && ($tokens[$index + 1]['text'] ?? null) === '('
                && ($tokens[$index - 1]['id'] ?? null) !== T_FUNCTION
            ) {
                $helperCalls++;
            }
            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && str_contains($token['text'], '/Font/')) {
                $fontLiterals++;
            }
        }
        if ($helperCalls !== ($selection['helper'] ? 1 : 0) || $fontLiterals !== 1) {
            throw new ConfigurationException('webman/captcha runtime font has an undeclared path reference');
        }
        if ($selection['helper']) {
            $helper = $methods['getfontpath'] ?? null;
            if ($helper === null
                || array_slice($helper['header'], 0, 3) !== $this->tokens('<?php ($font)', false)
                || ($helper['body'] !== $this->tokens('<?php return $font;')
                    && $helper['body'] !== $this->tokens('<?php ' . self::PHAR_FONT_PATH))
            ) {
                throw new ConfigurationException('webman/captcha runtime font path helper structure is unsupported');
            }
        }
        foreach (['writephrase' => ['imagettfbbox' => 2, 'imageftbbox' => 2, 'imagettftext' => 6, 'imagefttext' => 6], 'buildagainstocr' => ['build' => 2]] as $method => $calls) {
            if (isset($methods[$method])) {
                $allowed = [];
                $this->allowFontArguments($methods[$method]['body'], $calls, $allowed, $imports);
                $this->assertFontUses($methods[$method]['body'], $allowed, $method);
            }
        }
        foreach ($methods as $method => $definition) {
            if ($method === 'writephrase') {
                continue;
            }
            foreach ($definition['body'] as $index => $token) {
                if (($definition['body'][$index + 1]['text'] ?? null) === '('
                    && !in_array($definition['body'][$index - 1]['id'] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
                    && in_array($this->resolveFunction($token, $imports), ['imagettfbbox', 'imageftbbox', 'imagettftext', 'imagefttext'], true)
                ) {
                    throw new ConfigurationException("webman/captcha runtime font has an undeclared drawing source: {$method}");
                }
            }
        }

        [$minimum, $maximum] = $selection['range'];
        if ($maximum < $minimum || $maximum - $minimum > 1024) {
            throw new ConfigurationException('webman/captcha runtime font selection range is invalid');
        }
        return ['minimum' => $minimum, 'maximum' => $maximum];
    }

    /** @return list<array{id:int|string,text:string,fullyQualified:bool}> */
    private function tokens(string $source, bool $parse = true): array
    {
        try {
            $tokens = token_get_all($source, $parse ? TOKEN_PARSE : 0);
        } catch (\ParseError $exception) {
            throw new ConfigurationException('webman/captcha runtime font builder is invalid PHP', previous: $exception);
        }
        $result = [];
        foreach ($tokens as $token) {
            $id = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;
            $fullyQualified = $id === T_NAME_FULLY_QUALIFIED;
            if (in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                $id = T_STRING;
                $text = strtolower(ltrim($text, '\\'));
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING && !str_contains($text, '\\')) {
                $text = "'" . substr($text, 1, -1) . "'";
            }
            $result[] = ['id' => $id, 'text' => $text, 'fullyQualified' => $fullyQualified];
        }
        return $result;
    }

    /** @param array $tokens @return array */
    private function classBody(array $tokens): array
    {
        $body = null;
        $namespace = false;
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index]['id'] === T_NAMESPACE) {
                $namespace = strtolower($tokens[$index + 1]['text'] ?? '') === 'webman\\captcha'
                    && ($tokens[$index + 2]['text'] ?? null) === ';';
            }
            if ($tokens[$index]['id'] !== T_CLASS || ($tokens[$index + 1]['text'] ?? null) !== 'captchabuilder') {
                continue;
            }
            if (!$namespace || $body !== null || ($tokens[$index + 2]['id'] ?? null) !== T_IMPLEMENTS
                || ($tokens[$index + 3]['text'] ?? null) !== 'captchabuilderinterface'
                || ($tokens[$index + 4]['text'] ?? null) !== '{'
            ) {
                throw new ConfigurationException('webman/captcha runtime font builder class structure is unsupported');
            }
            $index += 4;
            $depth = 1;
            $body = [];
            while (++$index < $count && $depth > 0) {
                $token = $tokens[$index];
                if ($token['text'] === '{' || in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                } elseif ($token['text'] === '}') {
                    $depth--;
                }
                if ($depth > 0) {
                    $body[] = $token;
                }
            }
            $index--;
        }
        if ($body === null) {
            throw new ConfigurationException('webman/captcha runtime font builder class structure is unsupported');
        }
        return $body;
    }

    /** @param list<array{id:int|string,text:string,fullyQualified:bool}> $tokens @return array<string,array{header:array,body:array}> */
    private function methods(array $tokens): array
    {
        $methods = [];
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index]['id'] !== T_FUNCTION || ($tokens[$index + 1]['id'] ?? null) !== T_STRING) {
                continue;
            }
            $name = $tokens[++$index]['text'];
            $header = [];
            while (++$index < $count && $tokens[$index]['text'] !== '{') {
                $header[] = $tokens[$index];
            }
            $body = [];
            $depth = 1;
            while (++$index < $count && $depth > 0) {
                $token = $tokens[$index];
                if ($token['text'] === '{' || in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                } elseif ($token['text'] === '}') {
                    $depth--;
                }
                if ($depth > 0) {
                    $body[] = $token;
                }
            }
            if (isset($methods[$name])) {
                throw new ConfigurationException("webman/captcha runtime font method is duplicated: {$name}");
            }
            $methods[$name] = ['header' => $header, 'body' => $body];
            $index--;
        }
        return $methods;
    }

    /** @param array $tokens @param array $expected @return array{int,int}|null */
    private function selectionAt(array $tokens, int $index, array $expected): ?array
    {
        $numbers = [];
        foreach ($expected as $offset => $required) {
            $actual = $tokens[$index + $offset] ?? null;
            if (!is_array($actual) || $actual['id'] !== $required['id']) {
                return null;
            }
            if ($required['id'] === T_LNUMBER) {
                if (preg_match('/^(0|[1-9][0-9]{0,5})$/D', $actual['text']) !== 1) {
                    return null;
                }
                $numbers[] = (int) $actual['text'];
            } elseif ($actual !== $required) {
                return null;
            }
        }
        return count($numbers) === 2 ? $numbers : null;
    }

    /** @param array $tokens @return array<string,string> */
    private function functionImports(array $tokens): array
    {
        $imports = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_USE || ($tokens[$index + 1]['id'] ?? null) !== T_FUNCTION) {
                continue;
            }
            $cursor = $index + 2;
            do {
                $name = $tokens[$cursor] ?? null;
                if (!is_array($name) || !in_array($name['id'], [T_STRING, T_NAME_QUALIFIED], true)) {
                    throw new ConfigurationException('webman/captcha runtime font function import structure is unsupported');
                }
                $function = strtolower($name['text']);
                $parts = explode('\\', $function);
                $alias = end($parts);
                $cursor++;
                if (($tokens[$cursor]['id'] ?? null) === T_AS) {
                    $aliasToken = $tokens[++$cursor] ?? null;
                    if (!is_array($aliasToken) || $aliasToken['id'] !== T_STRING) {
                        throw new ConfigurationException('webman/captcha runtime font function import alias is unsupported');
                    }
                    $alias = $aliasToken['text'];
                    $cursor++;
                }
                if (isset($imports[$alias]) || !in_array($tokens[$cursor]['text'] ?? null, [',', ';'], true)) {
                    throw new ConfigurationException('webman/captcha runtime font function import structure is unsupported');
                }
                $imports[$alias] = $function;
            } while (($tokens[$cursor++]['text'] ?? null) === ',');
        }
        return $imports;
    }

    /** @param array $header */
    private function assertFontParameter(array $header, string $method): void
    {
        $parameters = [];
        $parameter = [];
        $depth = 0;
        foreach ($header as $token) {
            if ($token['text'] === '(') {
                if ($depth++ === 0) {
                    continue;
                }
            } elseif ($token['text'] === ')') {
                if (--$depth === 0) {
                    $parameters[] = $parameter;
                    break;
                }
            } elseif ($token['text'] === ',' && $depth === 1) {
                $parameters[] = $parameter;
                $parameter = [];
                continue;
            }
            $parameter[] = $token;
        }
        $font = $parameters[2] ?? [];
        $variable = null;
        foreach ($font as $index => $token) {
            if ($token['text'] === '&' || $token['id'] === T_ELLIPSIS) {
                throw new ConfigurationException("webman/captcha runtime font parameter must be passed by value: {$method}");
            }
            if ($token['id'] === T_VARIABLE) {
                if ($variable !== null || $token['text'] !== '$font') {
                    throw new ConfigurationException("webman/captcha runtime font parameter slot is unsupported: {$method}");
                }
                $variable = $index;
            }
        }
        $default = $variable !== null ? array_slice($font, $variable + 1) : null;
        $nullDefault = $this->tokens('<?php = null', false);
        if ($default === null
            || ($method !== 'writephrase' && $default !== $nullDefault)
            || ($method === 'writephrase' && $default !== [] && $default !== $nullDefault)
        ) {
            throw new ConfigurationException("webman/captcha runtime font parameter slot or default is unsupported: {$method}");
        }
    }

    /** @param array $tokens @param array<string,string> $imports */
    private function assertNoSymbolInjection(array $tokens, array $imports, string $method): void
    {
        foreach ($tokens as $index => $token) {
            if (in_array($token['id'], [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_DOLLAR_OPEN_CURLY_BRACES], true)
                || $token['id'] === '$'
            ) {
                throw new ConfigurationException("webman/captcha runtime font dynamic symbol source is unsupported: {$method}");
            }
            if (in_array($token['id'], [T_STRING, T_NAME_QUALIFIED], true)
                && ($tokens[$index + 1]['text'] ?? null) === '('
                && !in_array($tokens[$index - 1]['id'] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)
            ) {
                $function = $this->resolveFunction($token, $imports);
                if ($function === 'extract') {
                    throw new ConfigurationException("webman/captcha runtime font symbol table injection is unsupported: {$method}");
                }
            }
        }
    }

    /** @param array $token @param array<string,string> $imports */
    private function resolveFunction(array $token, array $imports): ?string
    {
        if (!in_array($token['id'], [T_STRING, T_NAME_QUALIFIED], true)) {
            return null;
        }
        $name = strtolower($token['text']);
        return ($token['fullyQualified'] ?? false) ? $name : ($imports[$name] ?? $name);
    }

    /** @param array $tokens @param array<string,int> $calls @param array<int,true> $allowed @param array<string,string> $imports */
    private function allowFontArguments(array $tokens, array $calls, array &$allowed, array $imports): void
    {
        $methodCalls = isset($calls['writephrase']) || isset($calls['build']);
        foreach ($tokens as $index => $token) {
            if (!in_array($token['id'], [T_STRING, T_NAME_QUALIFIED], true)
                || ($tokens[$index + 1]['text'] ?? null) !== '('
            ) {
                continue;
            }
            $previous = $tokens[$index - 1]['id'] ?? null;
            if ($methodCalls) {
                $name = $token['text'];
                if (!isset($calls[$name])) {
                    continue;
                }
                if ($previous !== T_OBJECT_OPERATOR || ($tokens[$index - 2]['text'] ?? null) !== '$this') {
                    throw new ConfigurationException('webman/captcha runtime font has an unknown drawing delegate');
                }
            } else {
                $name = $this->resolveFunction($token, $imports);
                if (!isset($calls[$name])) {
                    continue;
                }
                if (in_array($previous, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    throw new ConfigurationException('webman/captcha runtime font has an unknown drawing delegate');
                }
            }
            $argument = 0;
            $depth = 0;
            $start = $index + 2;
            for ($cursor = $start, $count = count($tokens); $cursor < $count; $cursor++) {
                $text = $tokens[$cursor]['text'];
                if ($depth === 0 && ($text === ',' || $text === ')')) {
                    if ($argument === $calls[$name]) {
                        if ($cursor !== $start + 1 || $tokens[$start]['id'] !== T_VARIABLE || $tokens[$start]['text'] !== '$font') {
                            throw new ConfigurationException('webman/captcha runtime font drawing argument is unsupported');
                        }
                        $allowed[$start] = true;
                    }
                    if ($text === ')') {
                        break;
                    }
                    $argument++;
                    $start = $cursor + 1;
                } elseif (in_array($text, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    $depth--;
                }
            }
        }
    }

    /** @param array $tokens @param array<int,true> $allowed */
    private function assertFontUses(array $tokens, array $allowed, string $method): void
    {
        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_VARIABLE && $token['text'] === '$font' && !isset($allowed[$index])) {
                throw new ConfigurationException("webman/captcha runtime font has an unsupported assignment or reference: {$method}");
            }
        }
    }
}
