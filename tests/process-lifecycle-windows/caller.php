<?php
declare(strict_types=1);

[$script, $app, $mode, $input, $receipt] = $argv;
spl_autoload_register(static function (string $class) use ($app): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require $app . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
$started = microtime(true);
$record = ['success' => false, 'mode' => $mode];
try {
    if ($mode === 'prepare') {
        $workspace = new WebmanAotBuilder\Project\ProjectWorkspace($input);
        $paths = $workspace->prepare(str_repeat('a', 64), str_repeat('b', 64));
        mkdir($paths['build'] . '/project');
        file_put_contents($paths['build'] . '/project/held-object.o', 'owned-object');
        mkdir($input . '/dist-aot');
        file_put_contents($input . '/dist-aot/sentinel', 'published-output');
        file_put_contents($paths['cache'] . '/sentinel', 'verified-cache');
        $record['paths'] = $paths;
        $record['success'] = true;
    } elseif ($mode === 'cleanup') {
        $paths = json_decode(file_get_contents($input), true, flags: JSON_THROW_ON_ERROR);
        $workspace = new WebmanAotBuilder\Project\ProjectWorkspace($paths['project']);
        set_error_handler(static fn(): bool => true);
        try {
            $probe = fopen($paths['build'] . '/project/held-object.o', 'rb');
            $record['exclusiveAtStart'] = $probe === false;
            if (is_resource($probe)) { fclose($probe); }
        } finally {
            restore_error_handler();
        }
        if (!$record['exclusiveAtStart']) {
            throw new RuntimeException('holder released before actual cleanup; this run is not evidence');
        }
        try {
            $workspace->finishAttempt($paths['build']);
            $record['removed'] = !is_dir($paths['build']);
        } catch (WebmanAotBuilder\Cli\ConfigurationException $e) {
            $record['error'] = $e->getMessage();
            $record['removed'] = false;
        }
        $record['dist'] = file_get_contents($paths['project'] . '/dist-aot/sentinel');
        $record['cache'] = file_get_contents($paths['cache'] . '/sentinel');
        $record['success'] = true;
    } else {
        $command = json_decode(file_get_contents($input), true, flags: JSON_THROW_ON_ERROR);
        $output = [1 => '', 2 => ''];
        try {
            $code = WebmanAotBuilder\Cli\ProcessOutput::run(
                $command, dirname($input), null, ['file', 'NUL', 'r'],
                static function (int $index, string $chunk) use (&$output, $mode): void {
                    $output[$index] .= $chunk;
                    if ($mode === 'callback-cancel' && str_contains($chunk, 'ROOTREADY')) {
                        throw new RuntimeException('owned callback cancellation');
                    }
                },
                static function (float $elapsed, float $silent): void {
                    fwrite(STDERR, sprintf("[fixture] child elapsed %.1fs silent %.1fs\n", $elapsed, $silent));
                }
            );
            $record['exit'] = $code;
        } catch (RuntimeException $e) {
            if ($mode !== 'callback-cancel' || $e->getMessage() !== 'owned callback cancellation') { throw $e; }
            $record['cancelled'] = true;
        }
        $record['output'] = $output;
        $record['success'] = true;
    }
} catch (Throwable $e) {
    $record['error'] = $e->getMessage();
} finally {
    $record['seconds'] = microtime(true) - $started;
    file_put_contents($receipt, json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
}
exit($record['success'] ? 0 : 1);
