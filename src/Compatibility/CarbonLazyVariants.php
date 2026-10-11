<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class CarbonLazyVariants
{
    /**
     * Statically select the same mutually exclusive declarations as Carbon's loaders.
     * Only installed declaration signatures and the target SDK PHP version decide.
     *
     * @return list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}>
     */
    public function apply(string $mirror, object $generator, ?string $targetPhpVersion): array
    {
        $root = 'vendor/nesbot/carbon/';
        $specifications = [
            [
                'selector' => $root . 'src/Carbon/MessageFormatter/MessageFormatterMapper.php',
                'strong' => $root . 'lazy/Carbon/MessageFormatter/MessageFormatterMapperStrongType.php',
                'weak' => $root . 'lazy/Carbon/MessageFormatter/MessageFormatterMapperWeakType.php',
                'class' => 'LazyMessageFormatter',
                'signature' => 'vendor/symfony/translation/Formatter/MessageFormatterInterface.php',
                'method' => 'format',
                'test' => 'parameter',
                'shadow' => '.typephp/build/carbon-message-formatter-variant.php',
            ],
            [
                'selector' => $root . 'src/Carbon/Translator.php',
                'strong' => $root . 'lazy/Carbon/TranslatorStrongType.php',
                'weak' => $root . 'lazy/Carbon/TranslatorWeakType.php',
                'class' => 'LazyTranslator',
                'signature' => is_file($mirror . '/vendor/symfony/translation-contracts/TranslatorInterface.php')
                    ? 'vendor/symfony/translation-contracts/TranslatorInterface.php'
                    : 'vendor/symfony/translation/Translator.php',
                'method' => 'trans',
                'test' => 'return',
                'shadow' => '.typephp/build/carbon-translator-variant.php',
            ],
            [
                'selector' => $root . 'src/Carbon/CarbonPeriod.php',
                'strong' => $root . 'lazy/Carbon/UnprotectedDatePeriod.php',
                'weak' => $root . 'lazy/Carbon/ProtectedDatePeriod.php',
                'class' => 'DatePeriodBase',
                'test' => 'php-version',
                'shadow' => '.typephp/build/carbon-date-period-variant.php',
            ],
        ];
        $mappings = [];
        foreach ($specifications as $specification) {
            if (!is_file($mirror . '/' . $specification['selector'])
                || !is_file($mirror . '/' . $specification['strong'])
                || !is_file($mirror . '/' . $specification['weak'])
            ) {
                continue;
            }
            $selector = $this->read($mirror . '/' . $specification['selector']);
            $strong = $this->read($mirror . '/' . $specification['strong']);
            $weak = $this->read($mirror . '/' . $specification['weak']);
            if (!$this->matchesLoader($mirror, $specification, $selector)) {
                continue;
            }
            if ($specification['test'] === 'php-version') {
                if (!is_string($targetPhpVersion)
                    || preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $targetPhpVersion) !== 1
                    || preg_match('/require\s+PHP_VERSION\s*<\s*8\.2\b/', $selector) !== 1
                ) {
                    continue;
                }
                $useStrong = version_compare($targetPhpVersion, '8.2', '>=');
            } else {
                if (!is_file($mirror . '/' . $specification['signature'])
                    || !str_contains($selector, 'new ReflectionMethod(')
                    || !str_contains($selector, "'" . $specification['method'] . "'")
                    || !str_contains($selector, $specification['test'] === 'parameter'
                        ? '$transMethod->getParameters()[0]->hasType()' : '$transMethod->hasReturnType()')
                ) {
                    continue;
                }
                if ($specification['test'] === 'parameter'
                    && preg_match('/new\s+ReflectionMethod\s*\(\s*MessageFormatterInterface::class\s*,\s*[\'"]format[\'"]\s*\)/', $selector) !== 1
                ) {
                    continue;
                }
                if ($specification['test'] === 'return'
                    && preg_match('/new\s+ReflectionMethod\s*\(\s*class_exists\s*\(\s*TranslatorInterface::class\s*\)\s*\?\s*TranslatorInterface::class\s*:\s*Translation\\\\Translator::class\s*,\s*[\'"]trans[\'"]\s*,?\s*\)/', $selector) !== 1
                ) {
                    continue;
                }
                $signature = $this->read($mirror . '/' . $specification['signature']);
                $useStrong = $this->signatureHasType($signature, $specification['method'], $specification['test']);
                if ($useStrong === null) {
                    continue;
                }
            }
            $selected = $useStrong ? $strong : $weak;
            $selectedPath = $useStrong ? $specification['strong'] : $specification['weak'];
            $class = preg_quote($specification['class'], '/');
            $pattern = '/\bif\s*\(\s*!class_exists\(\s*' . $class . '::class\s*,\s*false\s*\)\s*\)\s*\{([\s\S]*)\}\s*$/D';
            if (preg_match($pattern, $selected, $guard) !== 1
                || preg_match('/\b(?:abstract\s+)?class\s+' . $class . '\b/', $guard[1]) !== 1
                || preg_match($pattern, $useStrong ? $weak : $strong) !== 1
            ) {
                continue;
            }
            $active = (string) preg_replace_callback($pattern, static fn(array $match): string => $match[1], $selected, 1);
            $staticSelector = $this->removeLoader($mirror, $specification, $selector);
            if ($staticSelector === null) {
                continue;
            }
            try {
                $adaptedSelector = (new \ReflectionMethod($generator, 'patchSwitchTerminals'))
                    ->invoke($generator, $specification['selector'], $selector);
                if (str_contains($adaptedSelector, basename($specification['strong']))
                    || str_contains($adaptedSelector, basename($specification['weak']))
                ) {
                    $adaptedSelector = $this->removeLoader($mirror, $specification, $adaptedSelector);
                }
                if ($adaptedSelector !== null) {
                    $staticSelector = $adaptedSelector;
                }
            } catch (\RuntimeException $exception) {
                fwrite(STDERR, "[adapt] skipped optional Carbon selector transform {$specification['selector']}: {$exception->getMessage()}\n");
            }
            token_get_all($staticSelector, TOKEN_PARSE);
            token_get_all($active, TOKEN_PARSE);
            $selectorShadow = '.typephp/build/carbon-loader-' . basename($specification['selector']);
            $this->write($mirror . '/' . $selectorShadow, $staticSelector);
            $this->write($mirror . '/' . $specification['shadow'], $active);
            foreach ([$specification['selector'] => [$selector, $selectorShadow, $staticSelector],
                $specification['strong'] => [$strong, $specification['shadow'], $active],
                $specification['weak'] => [$weak, $specification['shadow'], $active]] as $path => [$source, $shadow, $replacement]) {
                $mappings[] = ['path' => $path, 'shadow' => $shadow,
                    'sourceSha256' => hash('sha256', $source), 'shadowSha256' => hash('sha256', $replacement)];
            }
            fwrite(STDERR, '[adapt] Carbon lazy declaration selected ' . $selectedPath . "\n");
        }
        return $mappings;
    }

    private function signatureHasType(string $source, string $method, string $test): ?bool
    {
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError) {
            return null;
        }
        $tokens = array_values(array_filter($tokens, static fn(mixed $token): bool =>
            !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $results = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            $nameIndex = $index + 1;
            if (($tokens[$nameIndex] ?? null) === '&'
                || (is_array($tokens[$nameIndex] ?? null) && $tokens[$nameIndex][1] === '&')
            ) {
                ++$nameIndex;
            }
            $name = $tokens[$nameIndex] ?? null;
            if (!is_array($name) || $name[0] !== T_STRING || strcasecmp($name[1], $method) !== 0
                || ($tokens[$nameIndex + 1] ?? null) !== '('
            ) {
                continue;
            }
            $parameters = [];
            $depth = 1;
            for ($cursor = $nameIndex + 2; isset($tokens[$cursor]); ++$cursor) {
                $part = $tokens[$cursor];
                if ($part === '(') {
                    ++$depth;
                } elseif ($part === ')') {
                    --$depth;
                    if ($depth === 0) {
                        break;
                    }
                }
                $parameters[] = $part;
            }
            if ($depth !== 0) {
                return null;
            }
            $results[] = $test === 'return'
                ? (($tokens[$cursor + 1] ?? null) === ':')
                : $this->firstParameterHasType($parameters);
        }
        return count($results) === 1 ? $results[0] : null;
    }

    /** @param list<array|string> $parameters */
    private function firstParameterHasType(array $parameters): ?bool
    {
        $prefix = [];
        $attributeDepth = 0;
        foreach ($parameters as $token) {
            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                ++$attributeDepth;
                continue;
            }
            if ($attributeDepth > 0) {
                if ($token === '[') {
                    ++$attributeDepth;
                } elseif ($token === ']') {
                    --$attributeDepth;
                }
                continue;
            }
            if (is_array($token) && $token[0] === T_VARIABLE) {
                while ($prefix !== []) {
                    $last = $prefix[count($prefix) - 1];
                    if ($last === '&' || (is_array($last) && ($last[1] === '&' || $last[0] === T_ELLIPSIS))) {
                        array_pop($prefix);
                        continue;
                    }
                    break;
                }
                return $prefix !== [];
            }
            $prefix[] = $token;
        }
        return null;
    }

    private function loaderPattern(array $specification): string
    {
        $condition = match ($specification['test']) {
            'parameter' => '\$transMethod\s*->\s*getParameters\s*\(\s*\)\s*\[\s*0\s*\]\s*->\s*hasType\s*\(\s*\)',
            'return' => '\$transMethod\s*->\s*hasReturnType\s*\(\s*\)',
            'php-version' => 'PHP_VERSION\s*<\s*8\.2',
        };
        return '\brequire\s+' . $condition
            . '\s*\?\s*__DIR__\s*\.\s*([\'"])([^\'"]+)\1'
            . '\s*:\s*__DIR__\s*\.\s*([\'"])([^\'"]+)\3\s*;';
    }

    private function matchesLoader(string $mirror, array $specification, string $selector): bool
    {
        if (preg_match('~' . $this->loaderPattern($specification) . '~', $selector, $match) !== 1) {
            return false;
        }
        $truePath = $specification[$specification['test'] === 'php-version' ? 'weak' : 'strong'];
        $falsePath = $specification[$specification['test'] === 'php-version' ? 'strong' : 'weak'];
        $directory = dirname($mirror . '/' . $specification['selector']);
        $trueTarget = realpath($mirror . '/' . $truePath);
        $falseTarget = realpath($mirror . '/' . $falsePath);
        return $trueTarget !== false && $falseTarget !== false
            && realpath($directory . '/' . $match[2]) === $trueTarget
            && realpath($directory . '/' . $match[4]) === $falseTarget;
    }

    private function reflectionAssignmentPattern(array $specification): ?string
    {
        return match ($specification['test']) {
            'parameter' => '~^\$transMethod\s*=\s*new\s+ReflectionMethod\s*\(\s*MessageFormatterInterface::class\s*,\s*[\'"]format[\'"]\s*,?\s*\)\s*;$~D',
            'return' => '~^\$transMethod\s*=\s*new\s+ReflectionMethod\s*\(\s*class_exists\s*\(\s*TranslatorInterface::class\s*\)\s*\?\s*TranslatorInterface::class\s*:\s*Translation\\\\Translator::class\s*,\s*[\'"]trans[\'"]\s*,?\s*\)\s*;$~D',
            default => null,
        };
    }

    private function removeLoader(string $mirror, array $specification, string $source): ?string
    {
        $tokens = token_get_all($source);
        $offset = 0;
        $depth = 0;
        $previous = null;
        $ranges = [];
        $requires = 0;
        $allRequires = 0;
        $assignments = 0;
        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
                ++$allRequires;
            }
            $boundary = $previous === ';' || $previous === '}'
                || (is_array($previous) && $previous[0] === T_OPEN_TAG);
            if ($depth === 0 && $boundary && is_array($token)
                && ($token[0] === T_REQUIRE || ($token[0] === T_VARIABLE && $text === '$transMethod'))
            ) {
                $statement = '';
                for ($end = $index; isset($tokens[$end]); ++$end) {
                    $statement .= is_array($tokens[$end]) ? $tokens[$end][1] : $tokens[$end];
                    if ($tokens[$end] === ';') {
                        break;
                    }
                }
                $assignmentPattern = $this->reflectionAssignmentPattern($specification);
                if ($token[0] === T_REQUIRE
                    && preg_match('~^' . $this->loaderPattern($specification) . '$~D', $statement) === 1
                    && $this->matchesLoader($mirror, $specification, $statement)
                ) {
                    ++$requires;
                    $ranges[] = [$offset, strlen($statement)];
                } elseif ($token[0] === T_VARIABLE && $assignmentPattern !== null
                    && preg_match($assignmentPattern, $statement) === 1
                ) {
                    ++$assignments;
                    $ranges[] = [$offset, strlen($statement)];
                }
            }
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                ++$depth;
            } elseif ($token === '}') {
                --$depth;
            }
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previous = $token;
            }
            $offset += strlen($text);
        }
        if ($requires !== 1 || $allRequires !== 1 || $assignments !== ($specification['test'] === 'php-version' ? 0 : 1)) {
            return null;
        }
        foreach (array_reverse($ranges) as [$start, $length]) {
            $source = substr_replace($source, '', $start, $length);
        }
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$transMethod') {
                return null;
            }
        }
        return $source;
    }

    private function read(string $path): string
    {
        $source = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($source)) {
            throw new ConfigurationException("Carbon lazy source is missing or unsafe: {$path}");
        }
        return $source;
    }

    private function write(string $path, string $source): void
    {
        if (is_link($path) || file_put_contents($path, $source, LOCK_EX) !== strlen($source)) {
            throw new ConfigurationException("cannot write Carbon lazy shadow: {$path}");
        }
    }
}
