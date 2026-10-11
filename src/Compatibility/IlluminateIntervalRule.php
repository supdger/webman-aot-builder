<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

final class IlluminateIntervalRule
{
    private const UNITS = ['microseconds', 'milliseconds', 'seconds', 'minutes', 'hours', 'days', 'weeks', 'months', 'years'];

    public function transform(string $source): string
    {
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError) {
            return $source;
        }
        $offset = 0;
        $items = [];
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $items[] = ['text' => $text, 'offset' => $offset, 'id' => is_array($token) ? $token[0] : null];
            }
            $offset += strlen($text);
        }
        $intervalNames = ['\\carbon\\carboninterval'];
        $namespace = null;
        foreach ($items as $index => $item) {
            if ($item['id'] === T_NAMESPACE) {
                if ($namespace !== null) { return $source; }
                $namespace = strtolower($items[$index + 1]['text'] ?? '');
            }
            if ($item['id'] !== T_USE
                || strtolower(ltrim($items[$index + 1]['text'] ?? '', '\\')) !== 'carbon\\carboninterval'
            ) { continue; }
            $next = $items[$index + 2]['text'] ?? '';
            if ($next === ';') { $intervalNames[] = 'carboninterval'; }
            elseif (strtolower($next) === 'as' && ($items[$index + 4]['text'] ?? '') === ';') {
                $intervalNames[] = strtolower($items[$index + 3]['text']);
            }
        }
        if ($namespace !== 'illuminate\\support') { return $source; }
        $found = [];
        $edits = [];
        for ($index = 0; $index < count($items); ++$index) {
            if ($items[$index]['id'] !== T_FUNCTION) { continue; }
            $unit = strtolower($items[$index + 1]['text'] ?? '');
            if (!in_array($unit, self::UNITS, true)) { continue; }
            if (isset($found[$unit])) { return $source; }
            $found[$unit] = true;
            $cursor = $index + 2;
            if (($items[$cursor]['text'] ?? '') !== '(') { return $source; }
            $parameters = [];
            while (isset($items[++$cursor]) && $items[$cursor]['text'] !== ')') { $parameters[] = $items[$cursor]['text']; }
            $variables = array_values(array_filter($parameters, static fn(string $text): bool => str_starts_with($text, '$')));
            if (count($variables) !== 1 || !in_array(implode('', array_slice($parameters, 0, -1)), ['int', 'int|float', 'float|int'], true)
                || end($parameters) !== $variables[0]
            ) { return $source; }
            $variable = $variables[0];
            $returnType = [];
            while (isset($items[++$cursor]) && $items[$cursor]['text'] !== '{') { $returnType[] = $items[$cursor]['text']; }
            if (($returnType[0] ?? null) !== ':' || !$this->isInterval(implode('', array_slice($returnType, 1)), $intervalNames)) {
                return $source;
            }
            if (($items[++$cursor]['text'] ?? '') !== 'return') { return $source; }
            $start = ++$cursor;
            $expression = [];
            while (isset($items[$cursor]) && $items[$cursor]['text'] !== ';') { $expression[] = $items[$cursor++]['text']; }
            if (($items[$cursor + 1]['text'] ?? '') !== '}') { return $source; }
            $expression = $this->unwrap($expression);
            $constructor = $this->constructor($unit, $variable);
            if (($expression[0] ?? '') === 'new' && $this->isInterval($expression[1] ?? '', $intervalNames)
                && implode('', array_slice($expression, 2)) === substr($constructor, strlen('new CarbonInterval'))
            ) { continue; }
            if (!$this->isInterval($expression[0] ?? '', $intervalNames) || ($expression[1] ?? '') !== '::'
                || ($expression[3] ?? '') !== '(' || end($expression) !== ')'
            ) { return $source; }
            $methodName = $expression[2];
            $method = strtolower($methodName);
            $arguments = array_slice($expression, 4, -1);
            if (end($arguments) === ',') { array_pop($arguments); }
            $arguments = $this->unwrap($arguments);
            $unitLiterals = ["'{$unit}'", '"' . $unit . '"'];
            if ($method === 'make') {
                foreach ($unitLiterals as $literal) {
                    if (in_array($arguments, [[$variable, ',', $literal],
                        [$variable, ',', 'unit', ':', $literal],
                        ['interval', ':', $variable, ',', 'unit', ':', $literal],
                        ['unit', ':', $literal, ',', 'interval', ':', $variable]], true)
                    ) { continue 2; }
                }
            }
            if ($method === '__callstatic' && count($arguments) === 5
                && in_array(strtolower($arguments[0]), ["'{$unit}'", '"' . $unit . '"'], true)
                && array_slice($arguments, 1) === [',', '[', $variable, ']']
            ) { continue; }
            if ($method !== $unit || $arguments !== [$variable]) { return $source; }
            $replacement = $expression[0] . "::__callStatic('{$methodName}', [{$variable}])";
            $original = substr($source, $items[$start]['offset'], $items[$cursor]['offset'] - $items[$start]['offset']);
            foreach (token_get_all('<?php ' . $original) as $part) {
                if (is_array($part) && in_array($part[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $replacement = $part[1] . "\n" . $replacement;
                }
            }
            $edits[] = [$items[$start]['offset'], $items[$cursor]['offset'], $replacement];
        }
        foreach (self::UNITS as $unit) { if (!isset($found[$unit])) { return $source; } }
        foreach (array_reverse($edits) as [$start, $end, $replacement]) {
            $source = substr_replace($source, $replacement, $start, $end - $start);
        }
        return $source;
    }

    /** @param list<string> $tokens @return list<string> */
    private function unwrap(array $tokens): array
    {
        while (($tokens[0] ?? null) === '(' && end($tokens) === ')') {
            $depth = 0;
            foreach ($tokens as $index => $token) {
                if ($token === '(') { ++$depth; }
                elseif ($token === ')' && --$depth === 0 && $index !== count($tokens) - 1) { return $tokens; }
            }
            $tokens = array_slice($tokens, 1, -1);
        }
        return $tokens;
    }

    /** @param list<string> $intervalNames */
    private function isInterval(string $name, array $intervalNames): bool
    {
        return in_array(strtolower($name), $intervalNames, true);
    }

    private function constructor(string $unit, string $variable): string
    {
        $position = ['years' => 0, 'months' => 1, 'weeks' => 2, 'days' => 3, 'hours' => 4,
            'minutes' => 5, 'seconds' => 6, 'microseconds' => 7, 'milliseconds' => 7][$unit];
        $arguments = array_fill(0, $position, '0');
        $arguments[] = $unit === 'milliseconds' ? $variable . '*1000' : $variable;
        return 'new CarbonInterval(' . implode(',', $arguments) . ')';
    }
}
