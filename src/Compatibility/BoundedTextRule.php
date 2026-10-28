<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class BoundedTextRule implements CompatibilityRule
{
    /**
     * @param list<string> $requiredBefore
     * @param list<string> $requiredAfter
     * @param MinimumVersion $verifiedMinimum Oldest verified input; source shape determines compatibility.
     */
    public function __construct(
        private readonly string $id,
        private readonly string $dependency,
        private readonly string $sourcePath,
        private readonly MinimumVersion $verifiedMinimum,
        private readonly array $requiredBefore,
        private readonly string $needle,
        private readonly string $replacement,
        private readonly int $expectedHits,
        private readonly array $requiredAfter,
        private readonly array $methods = []
    ) {
        if ($id === '' || $dependency === '' || $sourcePath === ''
            || $needle === '' || $expectedHits < 1 || $needle === $replacement
        ) {
            throw new ConfigurationException('invalid bounded compatibility rule');
        }
    }

    public function id(): string
    {
        return $this->id;
    }
    public function dependency(): string
    {
        return $this->dependency;
    }
    public function sourcePath(): string
    {
        return $this->sourcePath;
    }
    public function searchText(): string
    {
        return $this->needle;
    }
    public function expectedHits(): int
    {
        return $this->expectedHits;
    }

    public function transform(string $source, string $version): string
    {
        $tokens = $this->tokens($source);
        $before = $this->tokens('<?php ' . $this->needle);
        $after = $this->tokens('<?php ' . $this->replacement);
        $matches = $this->sourceMatches($tokens, $before);
        $adapted = array_values(array_filter($this->sourceMatches($tokens, $after), static function (array $match) use ($matches): bool {
            foreach ($matches as $original) {
                if ($match['start'] >= $original['start'] && $match['start'] < $original['start'] + $original['length']) { return false; }
            }
            return true;
        }));
        foreach ($this->requiredBefore as $marker) {
            if ($marker === '' || $this->matches($tokens, $this->tokens('<?php ' . $marker)) === []) {
                throw new ConfigurationException(
                    "compatibility rule {$this->id}: source structure drift in {$this->sourcePath} at {$version}"
                );
            }
        }
        $actualHits = count($matches) + count($adapted);
        if ($actualHits === 0) {
            throw new ConfigurationException(
                "compatibility rule {$this->id}: no supported source or adapted operation "
                . "in {$this->sourcePath} at {$version}; source structure requires review"
            );
        }
        $output = $source;
        foreach (array_reverse($matches) as $match) {
            $matched = array_slice($tokens, $match['start'], $match['length']);
            $start = $matched[0]['offset'];
            $end = $matched[count($matched) - 1]['end'];
            $fragment = substr($source, $start, $end - $start);
            $replacement = $this->rewrite($fragment, $matched, $start, $after);
            $output = substr_replace($output, $replacement, $start, $end - $start);
        }
        $outputTokens = $this->tokens($output);
        if ($this->sourceMatches($outputTokens, $before) !== []
            || count($this->sourceMatches($outputTokens, $after)) !== $actualHits
        ) {
            throw new ConfigurationException("compatibility rule {$this->id}: transformed source count drift in {$this->sourcePath} at {$version}");
        }
        foreach ($this->requiredAfter as $marker) {
            if ($marker === '' || $this->matches($outputTokens, $this->tokens('<?php ' . $marker)) === []) {
                throw new ConfigurationException("compatibility rule {$this->id}: postcondition failed in {$this->sourcePath} at {$version}");
            }
        }
        return $output;
    }

    private function sourceMatches(array $tokens, array $needle): array
    {
        $matches = $this->matches($tokens, $needle);
        if ($this->methods === []) { return $matches; }
        $className = pathinfo($this->sourcePath, PATHINFO_FILENAME);
        $ranges = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_CLASS || ($tokens[$index + 1]['text'] ?? null) !== $className) { continue; }
            $open = $index + 2;
            while (isset($tokens[$open]) && $tokens[$open]['id'] !== '{') { ++$open; }
            $classEnd = $this->bodyEnd($tokens, $open);
            for ($cursor = $open + 1; $cursor < $classEnd; ++$cursor) {
                if ($tokens[$cursor]['id'] !== T_FUNCTION) { continue; }
                $name = $cursor + 1;
                if (($tokens[$name]['text'] ?? null) === '&') { ++$name; }
                if (($tokens[$name]['id'] ?? null) !== T_STRING) { continue; }
                $body = $name + 1;
                while ($body < $classEnd && !in_array($tokens[$body]['id'], ['{', ';'], true)) { ++$body; }
                if (($tokens[$body]['id'] ?? null) !== '{') { continue; }
                $end = $this->bodyEnd($tokens, $body);
                if (in_array('*', $this->methods, true) || in_array($tokens[$name]['text'], $this->methods, true)) {
                    $ranges[] = [$body + 1, $end];
                }
                $cursor = $end;
            }
        }
        if ($ranges === []) { throw new ConfigurationException("compatibility rule {$this->id}: source method scope drift in {$this->sourcePath}"); }
        return array_values(array_filter($matches, static function (array $match) use ($ranges): bool {
            foreach ($ranges as [$start, $end]) {
                if ($match['start'] >= $start && $match['start'] + $match['length'] <= $end) { return true; }
            }
            return false;
        }));
    }

    private function bodyEnd(array $tokens, int $open): int
    {
        if (($tokens[$open]['id'] ?? null) !== '{') { throw new ConfigurationException("compatibility rule {$this->id}: source body is missing"); }
        $depth = 1;
        for ($index = $open + 1; isset($tokens[$index]); ++$index) {
            if (in_array($tokens[$index]['id'], ['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) { ++$depth; }
            elseif ($tokens[$index]['id'] === '}' && --$depth === 0) { return $index; }
        }
        throw new ConfigurationException("compatibility rule {$this->id}: source body is unbalanced");
    }

    /** @return list<array{id:int|string,text:string,offset:int,end:int}> */
    private function tokens(string $source): array
    {
        $result = [];
        $offset = 0;
        foreach (token_get_all($source) as $token) {
            $id = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;
            $end = $offset + strlen($text);
            if (!in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $result[] = ['id' => $id, 'text' => $text, 'offset' => $offset, 'end' => $end];
            }
            $offset = $end;
        }
        return $result;
    }

    /**
     * @param list<array{id:int|string,text:string,offset:int,end:int}> $source
     * @param list<array{id:int|string,text:string,offset:int,end:int}> $needle
     * @return list<array{start:int,length:int}>
     */
    private function matches(array $source, array $needle): array
    {
        if ($needle === []) {
            return [];
        }
        $matches = [];
        for ($index = 0; $index <= count($source) - count($needle); ++$index) {
            $cursor = $index;
            $variadics = [];
            foreach ($needle as $relative => $token) {
                if ($token['id'] === T_ELLIPSIS
                    && ($source[$cursor]['id'] ?? null) === T_STRING
                    && ($source[$cursor]['text'] ?? null) === 'mixed'
                ) {
                    ++$cursor;
                }
                $actual = $source[$cursor] ?? null;
                if (!is_array($actual)) {
                    continue 2;
                }
                if ($token['id'] === T_VARIABLE
                    && in_array($token['text'], ['$__err', '$__sig', '$__walk'], true)
                ) {
                    if (($needle[$relative - 1]['id'] ?? null) === T_ELLIPSIS
                        && $actual['id'] === T_VARIABLE
                    ) {
                        $variadics[$token['text']] = $actual['text'];
                    }
                    if ($actual['id'] !== T_VARIABLE
                        || $actual['text'] !== ($variadics[$token['text']] ?? $token['text'])
                    ) {
                        continue 2;
                    }
                } elseif (!$this->sameToken($actual, $token)) {
                    continue 2;
                }
                ++$cursor;
            }
            $matches[] = ['start' => $index, 'length' => $cursor - $index];
            $index = $cursor - 1;
        }
        return $matches;
    }

    /**
     * @param array{id:int|string,text:string,offset:int,end:int} $left
     * @param array{id:int|string,text:string,offset:int,end:int} $right
     */
    private function sameToken(array $left, array $right): bool
    {
        return $left['id'] === $right['id'] && $left['text'] === $right['text'];
    }

    /**
     * Preserve original whitespace and comments by editing only changed tokens.
     * Exact original snippets retain the established output bytes and lock digests.
     * @param list<array{id:int|string,text:string,offset:int,end:int}> $before
     * @param list<array{id:int|string,text:string,offset:int,end:int}> $after
     */
    private function rewrite(string $fragment, array $before, int $offset, array $after): string
    {
        $needleTokens = $this->tokens('<?php ' . $this->needle);
        $canonicalBefore = substr('<?php ' . $this->needle, $needleTokens[0]['offset'], $needleTokens[count($needleTokens) - 1]['end'] - $needleTokens[0]['offset']);
        $replacement = '<?php ' . $this->replacement;
        if ($fragment === $canonicalBefore) {
            return substr($replacement, $after[0]['offset'], $after[count($after) - 1]['end'] - $after[0]['offset']);
        }
        $n = count($before);
        $m = count($after);
        $lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $lengths[$i][$j] = $this->sameToken($before[$i], $after[$j])
                    ? 1 + $lengths[$i + 1][$j + 1]
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }
        $pairs = [[-1, -1]];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($this->sameToken($before[$i], $after[$j])) {
                $pairs[] = [$i, $j];
                ++$i;
                ++$j;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                ++$i;
            } else {
                ++$j;
            }
        }
        $pairs[] = [$n, $m];
        $edits = [];
        for ($pair = 1; $pair < count($pairs); ++$pair) {
            [$leftBefore, $leftAfter] = $pairs[$pair - 1];
            [$rightBefore, $rightAfter] = $pairs[$pair];
            for ($old = $leftBefore + 1; $old < $rightBefore; ++$old) {
                $edits[] = [$before[$old]['offset'] - $offset, $before[$old]['end'] - $before[$old]['offset'], ''];
            }
            if ($rightAfter > $leftAfter + 1) {
                $start = $leftAfter < 0 ? $after[0]['offset'] : $after[$leftAfter]['end'];
                $end = $rightAfter === $m ? $after[$m - 1]['end'] : $after[$rightAfter]['offset'];
                $at = $leftBefore + 1 < $n ? $before[$leftBefore + 1]['offset'] - $offset : strlen($fragment);
                $edits[] = [$at, 0, substr($replacement, $start, $end - $start)];
            }
        }
        usort($edits, static fn (array $a, array $b): int => ($b[0] <=> $a[0]) ?: ($b[1] <=> $a[1]));
        foreach ($edits as [$at, $length, $text]) {
            $fragment = substr_replace($fragment, $text, $at, $length);
        }
        return $fragment;
    }
}
