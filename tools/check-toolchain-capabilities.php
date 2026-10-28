<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

try {
    if (count($argv) !== 3 || !in_array($argv[1], ['php', 'cxx'], true) || !is_file($argv[2])) {
        throw new InvalidArgumentException('usage: check-toolchain-capabilities.php <php|cxx> <selected-executable>');
    }
    if ($argv[1] === 'cxx') {
        (new WebmanAotBuilder\Toolchain\ToolchainCapabilities())->assertCxx($argv[2]);
        echo "C++17 Linux x86_64 target capability verified\n";
    } else {
        $process = proc_open([$argv[2], '-n', '-r', WebmanAotBuilder\Toolchain\ToolchainCapabilities::phpProbe()], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('selected PHP lacks compiler syntax or required runtime capabilities');
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
