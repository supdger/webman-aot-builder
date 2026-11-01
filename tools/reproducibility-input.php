#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Toolchain/UnifiedPatchApplier.php';
require dirname(__DIR__) . '/src/Toolchain/ReproducibilityInput.php';

use WebmanAotBuilder\Toolchain\ReproducibilityInput;

try {
    $result = (new ReproducibilityInput())->describe(dirname(__DIR__));
    fwrite(
        STDOUT,
        json_encode(
            $result,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL
    );
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
