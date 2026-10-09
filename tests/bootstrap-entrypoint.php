<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\BootstrapEntrypointRule;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
$started = microtime(true);
if (count($argv) < 2) {
    throw new RuntimeException('Pass one or more official webman-framework source directories');
}
$rule = new BootstrapEntrypointRule();
foreach (array_slice($argv, 1) as $root) {
    $path = rtrim($root, '/\\') . '/src/support/bootstrap.php';
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('official bootstrap source is missing');
    }
    $before = hash_file('sha256', $path);
    $spaced = '';
    foreach (token_get_all($source) as $token) {
        $spaced .= is_array($token) && $token[0] === T_WHITESPACE
            ? "\n /* compatible spacing */ \t"
            : (is_array($token) ? $token[1] : $token);
    }
    $qualified = str_replace('use Webman\Config;', 'use Webman\Config as Settings;', $source);
    $qualified = str_replace('Config::', 'Settings::', $qualified);
    $qualified = str_replace('Route::', '\Webman\Route::', $qualified);
    $qualified = str_replace('base_path(', '\base_path(', $qualified);
    $renamed = str_replace(['$timezone', '$className', '$firm'], ['$zone', '$bootstrapClass', '$vendorName'], $source);
    foreach (['official' => $source, 'comments-whitespace' => $spaced, 'aliases-qualified-names' => $qualified, 'local-variable-names' => $renamed] as $case => $bytes) {
        $rule->validate($bytes);
        echo 'PASS bootstrap ' . basename($root) . ' ' . $case . "\n";
    }
    foreach ([
        'extra-side-effect' => $source . "\nfile_put_contents('new-startup.log', 'unrepresented');\n",
        'missing-middleware' => str_replace("Middleware::load(config('middleware', []));", '', $source),
        'unknown-autoload-path' => str_replace("config('autoload.files', [])", "config('unexpected.files', [])", $source),
        'changed-bootstrap-worker-context' => str_replace('$className::start($worker);', '$className::start(null);', $source),
        'changed-shutdown-delay' => str_replace('sleep(1);', 'sleep(20);', $source),
    ] as $case => $bytes) {
        try {
            $rule->validate($bytes);
            throw new RuntimeException('accepted unsupported startup ' . $case);
        } catch (ConfigurationException $exception) {
            if (!str_contains($exception->getMessage(), 'startup')) {
                throw new RuntimeException('incorrect bootstrap diagnostic: ' . $exception->getMessage());
            }
            echo 'PASS reject bootstrap ' . basename($root) . ' ' . $case . "\n";
        }
    }
    if (hash_file('sha256', $path) !== $before) {
        throw new RuntimeException('official bootstrap source changed');
    }
}
echo sprintf("PASS bootstrap entrypoint compatibility completed in %.3fs\n", microtime(true) - $started);
