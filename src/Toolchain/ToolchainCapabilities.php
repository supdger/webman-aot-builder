<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Cli\ProcessOutput;

final class ToolchainCapabilities
{
    public static function phpProbe(): string
    {
        return <<<'CODE'
if (PHP_INT_SIZE !== 8 || !extension_loaded('tokenizer') || !extension_loaded('json') || !extension_loaded('SPL')) {
    throw new RuntimeException('compiler PHP needs 64-bit integers, tokenizer, JSON and SPL');
}
token_get_all('<?php class DriverSyntax { public string $value { get => "ok"; } }', TOKEN_PARSE);
echo PHP_VERSION;
CODE;
    }

    public function assertCxx(string $compiler): void
    {
        $input = tmpfile();
        if ($input === false) {
            throw new ConfigurationException('unable to create compiler capability probe');
        }
        try {
            fwrite($input, 'static_assert(sizeof(void*) == 8); template<auto Value> constexpr auto probe() { if constexpr (Value > 0) return Value; else return 0; } static_assert(probe<1>() == 1);');
            rewind($input);
            $exit = ProcessOutput::run([$compiler, '--target=x86_64-unknown-linux-musl', '-std=c++17', '-x', 'c++', '-fsyntax-only', '-'], null, null, $input,
                static function (int $channel, string $chunk): void { fwrite(STDERR, $chunk); },
                static function (float $elapsed, float $silent): void { fwrite(STDERR, sprintf("[capability] compiler probe active %.0fs, silent %.0fs\n", $elapsed, $silent)); });
            if ($exit !== 0) {
                throw new ConfigurationException('compiler lacks required C++17 or Linux x86_64 target capability');
            }
        } finally {
            fclose($input);
        }
    }
}
