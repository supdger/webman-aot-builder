<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class SaiAdminCarbonPeriodRule
{
    /** @return array<string,string> */
    public function replacements(string $period, string $interval): array
    {
        $replacements = [];
        foreach ($this->calls($period, $interval) as $call) {
            $replacements[$call['before']] = $call['after'];
        }
        return $replacements;
    }

    public function transform(string $period, string $interval): string
    {
        $edits = [];
        foreach ($this->calls($period, $interval) as $call) {
            array_push($edits, ...$call['edits']);
        }
        usort($edits, static fn(array $left, array $right): int => $right['offset'] <=> $left['offset']);
        foreach ($edits as $edit) {
            $period = substr_replace($period, $edit['after'], $edit['offset'], $edit['length']);
        }
        return $period;
    }

    /** @return list<array{before:string,after:string,edits:list<array{offset:int,length:int,after:string}>}> */
    private function calls(string $period, string $interval): array
    {
        $tokens = $this->tokens($period);
        $intervalTokens = $this->tokens($interval);
        $this->assertClass($tokens, 'CarbonPeriod');
        $this->assertClass($intervalTokens, 'CarbonInterval');
        $methods = [];
        foreach ($intervalTokens as $index => $token) {
            if ($token[0] === T_FUNCTION && ($intervalTokens[$index - 1][0] ?? null) === T_STATIC
                && ($intervalTokens[$index + 1][0] ?? null) === T_STRING
            ) {
                $methods[strtolower($intervalTokens[$index + 1][1])] = true;
            }
        }
        foreach (['__callstatic', 'make'] as $required) {
            if (!isset($methods[$required])) {
                throw new ConfigurationException('CarbonInterval compatibility requires static ' . $required);
            }
        }
        $units = ['year', 'month', 'week', 'day', 'hour', 'minute', 'second', 'millisecond', 'microsecond'];
        $replacements = [];
        foreach ($tokens as $index => $token) {
            if (!in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                || !in_array(strtolower($token[1]), ['carboninterval', '\\carbon\\carboninterval'], true)
                || ($tokens[$index + 1][0] ?? null) !== T_DOUBLE_COLON
            ) {
                continue;
            }
            $method = $tokens[$index + 2] ?? [null, '', 0];
            if ($method[0] !== T_STRING || ($tokens[$index + 3][1] ?? null) !== '(') {
                throw new ConfigurationException('CarbonPeriod has an unsupported dynamic CarbonInterval call');
            }
            $name = strtolower($method[1]);
            if (isset($methods[$name])) {
                continue;
            }
            if (!in_array($name, $units, true) && !(str_ends_with($name, 's') && in_array(substr($name, 0, -1), $units, true))) {
                throw new ConfigurationException('CarbonPeriod has an unsupported CarbonInterval::' . $method[1] . ' call');
            }
            $depth = 1;
            $end = $index + 4;
            for (; $end < count($tokens); ++$end) {
                if ($tokens[$end][1] === '(') {
                    ++$depth;
                } elseif ($tokens[$end][1] === ')' && --$depth === 0) {
                    break;
                }
            }
            if ($depth !== 0) {
                throw new ConfigurationException('CarbonPeriod interval arguments are unbalanced');
            }
            $argumentDepth = 0;
            for ($argumentIndex = $index + 4; $argumentIndex < $end; ++$argumentIndex) {
                $argumentToken = $tokens[$argumentIndex];
                if (in_array($argumentToken[1], ['(', '[', '{'], true)) {
                    ++$argumentDepth;
                } elseif (in_array($argumentToken[1], [')', ']', '}'], true)) {
                    --$argumentDepth;
                }
                if ($argumentDepth === 0 && $argumentToken[0] === T_STRING
                    && ($tokens[$argumentIndex + 1][1] ?? null) === ':'
                    && in_array($tokens[$argumentIndex - 1][1] ?? null, ['(', ','], true)
                ) {
                    throw new ConfigurationException('CarbonPeriod magic interval call uses unsupported named arguments');
                }
            }
            $before = substr($period, $token[2], $tokens[$end][2] + 1 - $token[2]);
            // Edit only the method token and delimiters: nested calls never overlap,
            // and comments between the class, method and arguments stay in place.
            $edits = [
                ['offset' => $method[2], 'length' => strlen($method[1]), 'after' => '__callStatic'],
                ['offset' => $tokens[$index + 3][2], 'length' => 1, 'after' => "('" . $method[1] . "', ["],
                ['offset' => $tokens[$end][2], 'length' => 1, 'after' => '])'],
            ];
            $after = $before;
            foreach (array_reverse($edits) as $edit) {
                $after = substr_replace($after, $edit['after'], $edit['offset'] - $token[2], $edit['length']);
            }
            $replacements[] = ['before' => $before, 'after' => $after, 'edits' => $edits];
        }
        return $replacements;
    }

    /** @return list<array{int|string,string,int}> */
    private function tokens(string $source): array
    {
        try {
            $raw = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $error) {
            throw new ConfigurationException('Carbon compatibility source is invalid PHP: ' . $error->getMessage());
        }
        $tokens = [];
        $offset = 0;
        foreach ($raw as $token) {
            [$id, $text] = is_array($token) ? [$token[0], $token[1]] : [$token, $token];
            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = [$id, $text, $offset];
            }
            $offset += strlen($text);
        }
        return $tokens;
    }

    private function assertClass(array $tokens, string $class): void
    {
        $namespace = false;
        $declaration = false;
        foreach ($tokens as $index => $token) {
            if ($token[0] === T_NAMESPACE && ($tokens[$index + 1][1] ?? null) === 'Carbon') {
                $namespace = true;
            }
            if ($token[0] === T_CLASS && ($tokens[$index + 1][1] ?? null) === $class) {
                $declaration = true;
            }
        }
        if (!$namespace || !$declaration) {
            throw new ConfigurationException('Carbon compatibility source requires Carbon\\' . $class);
        }
    }
}
