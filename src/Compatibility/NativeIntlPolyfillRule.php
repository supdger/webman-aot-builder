<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class NativeIntlPolyfillRule
{
    /**
     * @param array<string,mixed> $policy
     * @return array{shadowSha256:string,projectSha256:string}
     */
    public function apply(string $mirrorDirectory, array $policy, array $runtimeCapabilities): array
    {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror) || is_link($mirrorDirectory)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
            || ($policy['rule'] ?? null) !== 'symfony.native-intl-polyfill.v1'
            || !is_array($policy['packages'] ?? null)
            || !is_array($policy['shadows'] ?? null)
        ) {
            throw new ConfigurationException('native intl polyfill adaptation policy is invalid');
        }

        if (($runtimeCapabilities['intlEnabled'] ?? null) !== true
            || !is_bool($runtimeCapabilities['nativeGraphemeLevenshtein'] ?? null)
        ) { throw new ConfigurationException('native intl adaptation requires proven selected SDK Intl/function capabilities'); }
        $retained = ['grapheme_strrev'];
        if (!$runtimeCapabilities['nativeGraphemeLevenshtein']) { array_unshift($retained, 'grapheme_levenshtein'); }

        $lock = json_decode($this->read($mirror . '/composer.lock', 'Composer lock'), true);
        if (!is_array($lock)) {
            throw new ConfigurationException('native intl polyfill Composer lock is invalid');
        }
        $installed = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                if (isset($installed[$package['name']])) {
                    throw new ConfigurationException('native intl polyfill Composer lock contains duplicate package: ' . $package['name']);
                }
                $installed[$package['name']] = $package;
            }
        }

        $projectFile = $mirror . '/project.linux.yml';
        $project = $this->read($projectFile, 'TypePHP project');
        $sections = explode("\nignore:\n", $project);
        if (count($sections) !== 2) {
            throw new ConfigurationException('native intl TypePHP project structure drifted');
        }
        $declarations = [];
        $layout = null;
        foreach (['grapheme', 'idn', 'normalizer'] as $name) {
            $packageName = "symfony/polyfill-intl-{$name}";
            $packagePolicy = $policy['packages'][$packageName] ?? null;
            $shadowPolicy = $policy['shadows'][$name] ?? null;
            $package = $installed[$packageName] ?? null;
            $bootstrap = "vendor/symfony/polyfill-intl-{$name}/bootstrap80.php";
            $shadow = ".typephp/build/symfony-{$name}-functions.php";
            if (!is_array($packagePolicy) || !is_array($shadowPolicy)
                || !is_array($package)
                || !is_string($package['version'] ?? null)
                || $package['version'] === ''
            ) {
                throw new ConfigurationException("native intl polyfill package drifted: {$name}");
            }
            $bootstrapDeclarations = $this->declarations($this->read($mirror . '/' . $bootstrap, $bootstrap), $name, true);
            $declarations[$name] = $this->declarations($this->read($mirror . '/' . $shadow, $shadow), $name, false);
            $adaptedShadow = $name === 'grapheme' && array_diff(array_keys($declarations[$name]), $retained) === [];
            $expected = $adaptedShadow ? array_intersect_key($bootstrapDeclarations, array_flip($retained)) : $bootstrapDeclarations;
            if (array_diff_key($expected, $declarations[$name]) !== [] || array_diff_key($declarations[$name], $expected) !== []) {
                throw new ConfigurationException("native intl polyfill source declaration set drifted: {$name}");
            }
            foreach ($expected as $function => $declaration) {
                if (array_column($this->tokens('<?php ' . $declaration), 'text')
                    !== array_column($this->tokens('<?php ' . $declarations[$name][$function]), 'text')
                ) {
                    throw new ConfigurationException("native intl polyfill shadow source declaration drifted: {$function}");
                }
            }
            $shadowCount = substr_count($sections[0] . "\n", "\n  - {$shadow}\n");
            $bootstrapCount = substr_count($sections[0] . "\n", "\n  - {$bootstrap}\n");
            $ignoredCount = substr_count("\n" . $sections[1], "\n  - {$bootstrap}\n");
            if ($name === 'idn') {
                $layout = match ($shadowCount) { 1 => 'original', 0 => 'adapted', default => null };
            }
            if ($bootstrapCount !== 0 || $ignoredCount !== 1 || ($layout === null && $name !== 'grapheme')
                || ($name === 'grapheme' && $shadowCount !== 1)
                || ($name === 'normalizer' && $shadowCount !== ($layout === 'original' ? 1 : 0))
            ) {
                throw new ConfigurationException("native intl polyfill project entries drifted: {$name}");
            }
        }

        $shadowPath = $mirror . '/.typephp/build/symfony-grapheme-functions.php';
        $functions = $retained;
        $retained = [];
        foreach ($functions as $function) {
            if (isset($declarations['grapheme'][$function])) {
                $retained[] = $declarations['grapheme'][$function];
            }
        }
        $adapted = "<?php\n\nuse Symfony\\Polyfill\\Intl\\Grapheme as p;\n\n"
            . implode("\n\n", $retained) . "\n";
        $this->declarations($adapted, 'grapheme', false);
        foreach (['idn', 'normalizer'] as $name) {
            $line = "  - .typephp/build/symfony-{$name}-functions.php\n";
            if ($layout === 'original') {
                $project = str_replace($line, '', $project);
            }
        }
        if (file_put_contents($shadowPath, $adapted, LOCK_EX) === false
            || file_put_contents($projectFile, $project, LOCK_EX) === false
        ) {
            throw new ConfigurationException('unable to write native intl AOT adaptation');
        }
        return [
            'shadowSha256' => hash('sha256', $adapted),
            'projectSha256' => hash('sha256', $project),
        ];
    }

    /** @return array<string,string> Current function declarations, preserving their source bytes. */
    private function declarations(string $source, string $name, bool $guarded): array
    {
        $tokens = $this->tokens($source);
        $class = ucfirst($name);
        $header = 'use Symfony\\Polyfill\\Intl\\' . $class . ' as p;';
        $headerTokens = array_column($this->tokens('<?php ' . $header), 'text');
        if (array_slice(array_column($tokens, 'text'), 0, count($headerTokens)) !== $headerTokens) {
            throw new ConfigurationException("native intl polyfill source alias drifted: {$name}");
        }
        $native = match ($name) {
            'grapheme' => ['grapheme_str_split', 'grapheme_extract', 'grapheme_stripos', 'grapheme_stristr', 'grapheme_strlen', 'grapheme_strpos', 'grapheme_strripos', 'grapheme_strrpos', 'grapheme_strstr', 'grapheme_substr', 'grapheme_levenshtein', 'grapheme_strrev'],
            'idn' => ['idn_to_ascii', 'idn_to_utf8'],
            'normalizer' => ['normalizer_is_normalized', 'normalizer_normalize', 'normalizer_get_raw_decomposition'],
        };
        $functions = [];
        $constants = [];
        for ($index = count($headerTokens); $index < count($tokens);) {
            $end = null;
            $guardName = null;
            $guardKind = null;
            if ($tokens[$index]['text'] === 'if' && $guarded) {
                $conditionEnd = $this->closing($tokens, $index + 1, '(', ')');
                $condition = array_column(array_slice($tokens, $index + 2, $conditionEnd - $index - 2), 'text');
                $bodyStart = $conditionEnd + 1;
                $end = $this->closing($tokens, $bodyStart, '{', '}');
                $body = array_column(array_slice($tokens, $bodyStart + 1, $end - $bodyStart - 1), 'text');
                if ($name === 'grapheme' && ($condition === ['extension_loaded', '(', "'intl'", ')'] && $body === ['return', ';']
                    || $condition === ['\\PHP_VERSION_ID', '>=', '80500'] && $body === ['return', 'require', '__DIR__', '.', "'/bootstrap85.php'", ';'])
                ) {
                    $index = $end + 1;
                    continue;
                }
                if (count($condition) !== 5 || $condition[0] !== '!'
                    || !in_array($condition[1], ['function_exists', 'defined'], true)
                    || $condition[2] !== '(' || $condition[4] !== ')'
                ) {
                    throw new ConfigurationException("native intl polyfill unsupported source guard: {$name}");
                }
                $guardName = trim($condition[3], "'");
                $guardKind = $condition[1];
                $index = $bodyStart + 1;
            }
            if ($tokens[$index]['text'] === 'function') {
                $function = $tokens[$index + 1]['text'] ?? '';
                if (!in_array($function, $native, true) || isset($functions[$function])
                    || ($guarded && $guardKind !== 'function_exists')
                    || ($guardName !== null && $guardName !== $function)
                ) {
                    throw new ConfigurationException("native intl polyfill function source drifted: {$function}");
                }
                $parametersEnd = $this->closing($tokens, $index + 2, '(', ')');
                $bodyStart = $parametersEnd + 1;
                while (($tokens[$bodyStart]['text'] ?? null) !== '{' && $bodyStart < count($tokens)) {
                    ++$bodyStart;
                }
                $functionEnd = $this->closing($tokens, $bodyStart, '{', '}');
                if ($end !== null && $functionEnd !== $end - 1) {
                    throw new ConfigurationException("native intl polyfill source guard contains extra statements: {$function}");
                }
                $functions[$function] = substr($source, $tokens[$index]['offset'], $tokens[$functionEnd]['end'] - $tokens[$index]['offset']);
                $index = $end === null ? $functionEnd + 1 : $end + 1;
            } elseif (in_array($tokens[$index]['text'], ['define', 'const'], true)) {
                $constant = $tokens[$index]['text'] === 'define' ? trim($tokens[$index + 2]['text'] ?? '', "'") : ($tokens[$index + 1]['text'] ?? '');
                if (isset($constants[$constant]) || ($guarded && $guardKind !== 'defined') || ($guardName !== null && $guardName !== $constant)
                    || !in_array($constant, $this->nativeConstants($name), true)
                ) {
                    throw new ConfigurationException("native intl polyfill constant source drifted: {$constant}");
                }
                $constants[$constant] = true;
                $constantEnd = $index;
                while (($tokens[$constantEnd]['text'] ?? null) !== ';' && $constantEnd < count($tokens)) {
                    ++$constantEnd;
                }
                $definition = array_column(array_slice($tokens, $index, $constantEnd - $index + 1), 'text');
                $valid = $tokens[$index]['text'] === 'define'
                    ? count($definition) === 7 && array_slice($definition, 0, 4) === ['define', '(', "'{$constant}'", ','] && preg_match('/\A[0-9]+\z/D', $definition[4]) === 1 && array_slice($definition, -2) === [')', ';']
                    : count($definition) === 5 && $definition[2] === '=' && preg_match('/\A[0-9]+\z/D', $definition[3]) === 1;
                if (!$valid || ($end !== null && $constantEnd !== $end - 1)) {
                    throw new ConfigurationException("native intl polyfill constant definition source drifted: {$constant}");
                }
                $index = $end === null ? $constantEnd + 1 : $end + 1;
            } else {
                throw new ConfigurationException("native intl polyfill unsupported source statement: {$name}");
            }
        }
        return $functions;
    }

    /** @return list<string> Symbols provided by the locked PHP 8.4 intl SDK. */
    private function nativeConstants(string $name): array
    {
        return match ($name) {
            'grapheme' => ['GRAPHEME_EXTR_COUNT', 'GRAPHEME_EXTR_MAXBYTES', 'GRAPHEME_EXTR_MAXCHARS'],
            'normalizer' => [],
            'idn' => ['U_IDNA_PROHIBITED_ERROR', 'U_IDNA_ERROR_START', 'U_IDNA_UNASSIGNED_ERROR', 'U_IDNA_CHECK_BIDI_ERROR', 'U_IDNA_STD3_ASCII_RULES_ERROR', 'U_IDNA_ACE_PREFIX_ERROR', 'U_IDNA_VERIFICATION_ERROR', 'U_IDNA_LABEL_TOO_LONG_ERROR', 'U_IDNA_ZERO_LENGTH_LABEL_ERROR', 'U_IDNA_DOMAIN_NAME_TOO_LONG_ERROR', 'U_IDNA_ERROR_LIMIT', 'U_STRINGPREP_PROHIBITED_ERROR', 'U_STRINGPREP_UNASSIGNED_ERROR', 'U_STRINGPREP_CHECK_BIDI_ERROR', 'IDNA_DEFAULT', 'IDNA_ALLOW_UNASSIGNED', 'IDNA_USE_STD3_RULES', 'IDNA_CHECK_BIDI', 'IDNA_CHECK_CONTEXTJ', 'IDNA_NONTRANSITIONAL_TO_ASCII', 'IDNA_NONTRANSITIONAL_TO_UNICODE', 'INTL_IDNA_VARIANT_UTS46', 'IDNA_ERROR_EMPTY_LABEL', 'IDNA_ERROR_LABEL_TOO_LONG', 'IDNA_ERROR_DOMAIN_NAME_TOO_LONG', 'IDNA_ERROR_LEADING_HYPHEN', 'IDNA_ERROR_TRAILING_HYPHEN', 'IDNA_ERROR_HYPHEN_3_4', 'IDNA_ERROR_LEADING_COMBINING_MARK', 'IDNA_ERROR_DISALLOWED', 'IDNA_ERROR_PUNYCODE', 'IDNA_ERROR_LABEL_HAS_DOT', 'IDNA_ERROR_INVALID_ACE_LABEL', 'IDNA_ERROR_BIDI', 'IDNA_ERROR_CONTEXTJ'],
        };
    }

    /** @return list<array{text:string,offset:int,end:int}> */
    private function tokens(string $source): array
    {
        try {
            $raw = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $exception) {
            throw new ConfigurationException('native intl polyfill source structure drifted: invalid PHP', previous: $exception);
        }
        $tokens = [];
        $offset = 0;
        foreach ($raw as $token) {
            $id = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;
            $end = $offset + strlen($text);
            if (!in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $normalized = $id === T_NAME_FULLY_QUALIFIED && in_array($text, ['\\extension_loaded', '\\function_exists', '\\defined', '\\define'], true) ? substr($text, 1) : $text;
                if ($id === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\'"]([A-Za-z0-9_\/.]+)[\'"]$/D', $text, $match) === 1) {
                    $normalized = "'" . $match[1] . "'";
                }
                $tokens[] = ['text' => $normalized, 'offset' => $offset, 'end' => $end];
            }
            $offset = $end;
        }
        return $tokens;
    }

    /** @param list<array{text:string,offset:int,end:int}> $tokens */
    private function closing(array $tokens, int $start, string $open, string $close): int
    {
        if (($tokens[$start]['text'] ?? null) === $open) {
            $depth = 0;
            for ($index = $start; $index < count($tokens); ++$index) {
                if ($tokens[$index]['text'] === $open) {
                    ++$depth;
                } elseif ($tokens[$index]['text'] === $close && --$depth === 0) {
                    return $index;
                }
            }
        }
        throw new ConfigurationException('native intl polyfill source structure drifted: unbalanced declaration');
    }

    private function read(string $path, string $label): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new ConfigurationException("native intl polyfill source is missing or unsafe: {$label}");
        }
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new ConfigurationException("native intl polyfill source is unreadable: {$label}");
        }
        return $source;
    }
}
