<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class TargetPhpModuleTable
{
    /** @return array<string,string>|null Registered symbol => actual PHP extension name. */
    public function read(string $library, string $moduleHeader): ?array
    {
        // The name pointer is at byte 32 in this PHP struct's ELF64 ABI.
        if (preg_match('/struct\s+_zend_module_entry\s*\{\s*unsigned short size;\s*unsigned int zend_api;\s*unsigned char zend_debug;\s*unsigned char zts;\s*const struct _zend_ini_entry \*ini_entry;\s*const struct _zend_module_dep \*deps;\s*const char \*name;/s', $moduleHeader) !== 1) {
            return null;
        }
        $stream = fopen($library, 'rb');
        if (!is_resource($stream)) {
            throw new ConfigurationException('target PHP module library cannot be read');
        }
        $registered = null;
        $names = [];
        try {
            if ($this->bytes($stream, 8) !== "!<arch>\n") {
                return null;
            }
            while (!feof($stream)) {
                $header = fread($stream, 60);
                if ($header === '') {
                    break;
                }
                if (!is_string($header) || strlen($header) !== 60 || substr($header, 58) !== "`\n"
                    || preg_match('/^\s*\d+\s*$/D', substr($header, 48, 10)) !== 1
                ) {
                    return null;
                }
                $size = (int) trim(substr($header, 48, 10));
                $object = $this->bytes($stream, $size);
                if ($size % 2 !== 0) {
                    $this->bytes($stream, 1);
                }
                $elf = $this->elf($object);
                if ($elf === null) {
                    continue;
                }
                foreach ($elf['symbols'] as $symbol) {
                    if ($symbol['name'] === 'php_builtin_extensions' && $symbol['section'] > 0) {
                        if ($registered !== null || $symbol['size'] === 0 || $symbol['size'] % 8 !== 0) {
                            return null;
                        }
                        $registered = [];
                        for ($offset = $symbol['value']; $offset < $symbol['value'] + $symbol['size']; $offset += 8) {
                            $pointer = $elf['relocations'][$symbol['section']][$offset] ?? null;
                            if ($pointer === null || $pointer['addend'] !== 0 || $pointer['symbol']['name'] === '') {
                                return null;
                            }
                            $registered[] = $pointer['symbol']['name'];
                        }
                    }
                    if ($symbol['section'] > 0 && ($symbol['size'] === 168)
                        && ($symbol['name'] === 'basic_functions_module' || str_ends_with($symbol['name'], '_module_entry'))
                    ) {
                        $pointer = $elf['relocations'][$symbol['section']][$symbol['value'] + 32] ?? null;
                        if ($pointer === null || $pointer['symbol']['section'] === 0) {
                            continue;
                        }
                        $section = $elf['sections'][$pointer['symbol']['section']] ?? null;
                        if ($section === null) {
                            continue;
                        }
                        $offset = $pointer['symbol']['value'] + $pointer['addend'];
                        if ($offset < 0 || $offset >= $section['size']) {
                            continue;
                        }
                        $name = $this->cstring($object, $section['offset'] + $offset);
                        if ($name !== '' && strlen($name) <= 128 && preg_match('/^[A-Za-z0-9_ .-]+$/D', $name) === 1) {
                            $names[$symbol['name']] = $name;
                        }
                    }
                }
            }
        } finally {
            fclose($stream);
        }
        if ($registered === null || $registered === []) {
            return null;
        }
        $modules = [];
        foreach ($registered as $symbol) {
            if (!isset($names[$symbol])) {
                return null;
            }
            $modules[$symbol] = $names[$symbol];
        }
        return $modules;
    }

    /** @return array{sections:array,symbols:array,relocations:array}|null */
    private function elf(string $object): ?array
    {
        if (strlen($object) < 64 || substr($object, 0, 6) !== "\x7fELF\x02\x01"
            || $this->number($object, 16, 2) !== 1 || $this->number($object, 58, 2) !== 64
        ) {
            return null;
        }
        $start = $this->number($object, 40, 8);
        $count = $this->number($object, 60, 2);
        if ($count === 0 || $start < 64 || $start + $count * 64 > strlen($object)) {
            return null;
        }
        $sections = [];
        for ($index = 0; $index < $count; ++$index) {
            $offset = $start + $index * 64;
            $section = ['type' => $this->number($object, $offset + 4, 4),
                'offset' => $this->number($object, $offset + 24, 8),
                'size' => $this->number($object, $offset + 32, 8),
                'link' => $this->number($object, $offset + 40, 4),
                'info' => $this->number($object, $offset + 44, 4),
                'entrySize' => $this->number($object, $offset + 56, 8)];
            if ($section['type'] !== 8 && $section['offset'] + $section['size'] > strlen($object)) {
                return null;
            }
            $sections[$index] = $section;
        }
        $symbols = [];
        $relocations = [];
        foreach ($sections as $tableIndex => $table) {
            if ($table['type'] !== 2 || $table['entrySize'] !== 24 || $table['size'] % 24 !== 0) {
                continue;
            }
            $strings = $sections[$table['link']] ?? null;
            if ($strings === null || $strings['type'] !== 3) {
                return null;
            }
            $local = [];
            for ($offset = $table['offset']; $offset < $table['offset'] + $table['size']; $offset += 24) {
                $nameOffset = $this->number($object, $offset, 4);
                if ($nameOffset >= $strings['size']) {
                    return null;
                }
                $symbol = ['name' => $this->cstring($object, $strings['offset'] + $nameOffset),
                    'section' => $this->number($object, $offset + 6, 2),
                    'value' => $this->number($object, $offset + 8, 8),
                    'size' => $this->number($object, $offset + 16, 8)];
                $local[] = $symbol;
                $symbols[] = $symbol;
            }
            foreach ($sections as $section) {
                if ($section['type'] !== 4 || $section['link'] !== $tableIndex
                    || $section['entrySize'] !== 24 || $section['size'] % 24 !== 0
                ) {
                    continue;
                }
                for ($offset = $section['offset']; $offset < $section['offset'] + $section['size']; $offset += 24) {
                    $info = $this->number($object, $offset + 8, 8);
                    if (!in_array($info & 0xffffffff, [1, 257], true)) {
                        continue;
                    }
                    $symbol = $local[$info >> 32] ?? null;
                    if ($symbol === null) {
                        return null;
                    }
                    $relocations[$section['info']][$this->number($object, $offset, 8)] = [
                        'symbol' => $symbol, 'addend' => unpack('q', substr($object, $offset + 16, 8))[1],
                    ];
                }
            }
        }
        return ['sections' => $sections, 'symbols' => $symbols, 'relocations' => $relocations];
    }

    private function number(string $bytes, int $offset, int $size): int
    {
        return unpack(match ($size) { 2 => 'v', 4 => 'V', 8 => 'P' }, substr($bytes, $offset, $size))[1];
    }

    private function cstring(string $bytes, int $offset): string
    {
        $end = strpos($bytes, "\0", $offset);
        return $end === false ? '' : substr($bytes, $offset, $end - $offset);
    }

    private function bytes($stream, int $size): string
    {
        $bytes = '';
        while (strlen($bytes) < $size) {
            $chunk = fread($stream, min(1048576, $size - strlen($bytes)));
            if (!is_string($chunk) || $chunk === '') {
                throw new ConfigurationException('target PHP module library read failed');
            }
            $bytes .= $chunk;
        }
        return $bytes;
    }
}
