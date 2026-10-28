<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class SaiAdminCarbonIntervalRule
{
    public function transform(string $source): string
    {
        $tokens = $this->tokens($source);
        $methods = $this->methods($tokens);
        $invert = $methods['invert'] ?? null;
        if ($invert === null
            || $this->text($tokens, $invert['start'], $invert['body']) !== 'publicfunctioninvert($inverted=null):static'
            || $this->text($tokens, $invert['body'] + 1, $invert['end'])
                !== '$this->invert=(\\func_num_args()===0?!$this->invert:$inverted)?1:0;return$this;'
        ) {
            $this->fail('invert method semantics drifted');
        }
        $edits = [];
        $seen = [];
        $simple = ['abs', 'toPeriod', 'solveNegativeInterval', 'roundUnit'];
        foreach ($methods as $name => $method) {
            for ($index = $method['body'] + 1; $index < $method['end']; ++$index) {
                if (!in_array($tokens[$index][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                    || strtolower($tokens[$index + 1][1] ?? '') !== 'invert'
                    || ($tokens[$index + 2][1] ?? '') !== '('
                ) {
                    continue;
                }
                if ($name === 'invertCascade') {
                    // A cascading receiver can change the sign. Evaluate it before
                    // reading its invert property, preserving the returned object.
                    $this->cascadeEdits($tokens, $method, $edits);
                    $seen[$name] = ($seen[$name] ?? 0) + 1;
                    continue;
                }
                $close = $this->closing($tokens, $index + 2, '(', ')');
                $arguments = $this->text($tokens, $index + 3, $close);
                $receiver = $tokens[$index - 1][1] ?? '';
                if ($arguments !== '' && $arguments !== '!$this->invert') {
                    continue;
                }
                if (!in_array($name, $simple, true) || $receiver !== '$this'
                    || $tokens[$index][0] !== T_OBJECT_OPERATOR
                    || ($tokens[$close + 1][1] ?? '') !== ';'
                ) {
                    $this->fail('unsupported zero-argument invert receiver in ' . $name);
                }
                $seen[$name] = ($seen[$name] ?? 0) + 1;
                if ($arguments === '') {
                    $edits[] = [$tokens[$index + 2][2] + 1, 0, '!$this->invert'];
                }
            }
        }
        foreach ([...$simple, 'invertCascade'] as $name) {
            if (($seen[$name] ?? 0) !== 1) {
                $this->fail('expected one invert toggle in ' . $name);
            }
        }
        usort($edits, static fn(array $left, array $right): int => $right[0] <=> $left[0]);
        foreach ($edits as [$offset, $length, $after]) {
            $source = substr_replace($source, $after, $offset, $length);
        }
        return $source;
    }

    private function cascadeEdits(array $tokens, array $method, array &$edits): void
    {
        $prefix = '$this->set(array_map(function($value){return-$value;},$values))->doCascade(true)';
        $original = 'return' . $prefix . '->invert();';
        $adapted = '$interval=' . $prefix . ';return$interval->invert(!$interval->invert);';
        $body = $this->text($tokens, $method['body'] + 1, $method['end']);
        if ($body === $adapted) {
            return;
        }
        if ($body !== $original) {
            $this->fail('invertCascade receiver structure drifted');
        }
        $first = $method['body'] + 1;
        $operator = $method['end'] - 5;
        if ($tokens[$operator][0] !== T_OBJECT_OPERATOR || $tokens[$operator + 1][1] !== 'invert') {
            $this->fail('invertCascade terminal structure drifted');
        }
        $edits[] = [$tokens[$first][2], strlen($tokens[$first][1]), '$interval ='];
        $edits[] = [$tokens[$operator][2], strlen($tokens[$operator][1]), '; return $interval->'];
        $edits[] = [$tokens[$operator + 2][2] + 1, 0, '!$interval->invert'];
    }

    /** @return array<string,array{start:int,body:int,end:int}> */
    private function methods(array $tokens): array
    {
        $namespace = false;
        $class = null;
        foreach ($tokens as $index => $token) {
            if ($token[0] === T_NAMESPACE) {
                if ($namespace || ($tokens[$index + 1][1] ?? '') !== 'Carbon'
                    || ($tokens[$index + 2][1] ?? '') !== ';'
                ) { $this->fail('unexpected namespace'); }
                $namespace = true;
            }
            if ($token[0] === T_CLASS && ($tokens[$index + 1][1] ?? '') === 'CarbonInterval') {
                if ($class !== null) { $this->fail('duplicate CarbonInterval class'); }
                $class = $index;
            }
        }
        if (!$namespace || $class === null) { $this->fail('requires Carbon\\CarbonInterval'); }
        $body = $class;
        while (isset($tokens[$body]) && $tokens[$body][1] !== '{') { ++$body; }
        $end = $this->closing($tokens, $body, '{', '}');
        $methods = [];
        for ($index = $body + 1; $index < $end; ++$index) {
            if ($tokens[$index][0] !== T_FUNCTION) { continue; }
            $nameIndex = $index + 1;
            if (($tokens[$nameIndex][1] ?? '') === '&') { ++$nameIndex; }
            $name = $tokens[$nameIndex][1] ?? '';
            if (($tokens[$nameIndex][0] ?? null) !== T_STRING) { continue; }
            if (isset($methods[$name])) { $this->fail('duplicate method ' . $name); }
            $start = $index;
            while (in_array($tokens[$start - 1][0] ?? null,
                [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT], true)) { --$start; }
            $methodBody = $index + 2;
            while (isset($tokens[$methodBody]) && !in_array($tokens[$methodBody][1], ['{', ';'], true)) { ++$methodBody; }
            if (($tokens[$methodBody][1] ?? null) !== '{') { $this->fail('unsupported method ' . $name); }
            $methodEnd = $this->closing($tokens, $methodBody, '{', '}');
            $methods[$name] = ['start' => $start, 'body' => $methodBody, 'end' => $methodEnd];
            $index = $methodEnd;
        }
        return $methods;
    }

    private function closing(array $tokens, int $start, string $open, string $close): int
    {
        $depth = 0;
        for ($index = $start; isset($tokens[$index]); ++$index) {
            if ($tokens[$index][1] === $open) { ++$depth; }
            elseif ($tokens[$index][1] === $close && --$depth === 0) { return $index; }
        }
        $this->fail('unbalanced ' . $open);
    }

    private function text(array $tokens, int $start, int $end): string
    {
        return implode('', array_column(array_slice($tokens, $start, $end - $start), 1));
    }

    /** @return list<array{int|string,string,int}> */
    private function tokens(string $source): array
    {
        try { $raw = token_get_all($source, TOKEN_PARSE); }
        catch (\ParseError $error) { $this->fail('invalid PHP: ' . $error->getMessage()); }
        $tokens = [];
        $offset = 0;
        foreach ($raw as $token) {
            [$id, $text] = is_array($token) ? [$token[0], $token[1]] : [$token, $token];
            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $tokens[] = [$id, $text, $offset]; }
            $offset += strlen($text);
        }
        return $tokens;
    }

    private function fail(string $reason): never
    {
        throw new ConfigurationException('vendor/nesbot/carbon/src/Carbon/CarbonInterval.php: ' . $reason);
    }
}
