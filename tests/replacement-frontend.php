<?php

declare(strict_types=1);

ini_set('memory_limit', '-1');

use WebmanAotBuilder\Compatibility\UpstreamProjectGenerator;
use WebmanAotBuilder\Toolchain\FullStaticProjectOverlay;
use WebmanAotBuilder\Project\SourceTreeSnapshot;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
function ensure(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function copyTree(string $source, string $target, bool $skipBuild = false): void
{
    ensure(!is_link($source), 'frontend fixture source is a symlink');
    if (is_dir($source)) {
        mkdir($target, 0700, true);
        foreach (new DirectoryIterator($source) as $entry) {
            if (!$entry->isDot() && !($skipBuild && in_array($entry->getFilename(), ['build', '.typephp', 'main.php', 'project.linux.yml'], true))) {
                copyTree($entry->getPathname(), $target . '/' . $entry->getFilename());
            }
        }
    } else { ensure(copy($source, $target), 'frontend fixture copy failed'); }
}
function removeTree(string $root): void
{
    if (!is_dir($root)) { return; }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
$sourceMirror = realpath($argv[1] ?? '');
$preparedFile = realpath($argv[2] ?? '');
$archive = realpath($argv[3] ?? '');
$generatorCache = realpath($argv[4] ?? '');
ensure(is_string($sourceMirror) && is_file($sourceMirror . '/composer.lock') && is_dir($sourceMirror . '/vendor') && is_string($preparedFile) && is_string($archive), 'Pass a complete project fixture, prepared-toolchain.json and locked generator archive');
$prepared = json_decode(file_get_contents($preparedFile), true, flags: JSON_THROW_ON_ERROR);
$tools = (new \WebmanAotBuilder\Toolchain\PreparedToolchain())->load($preparedFile, dirname($preparedFile), dirname(__DIR__) . '/toolchain.lock.json', $prepared['host']);
if (($argv[5] ?? '') !== '--selected-runtime') {
    echo "STAGE execute frontend with the selected prepared PHP driver and extension configuration\n";
    $environment = getenv();
    $environment['PHPRC'] = $tools['phprc'];
    $process = proc_open([$tools['php'], __FILE__, $sourceMirror, $preparedFile, $archive, $generatorCache ?: '', '--selected-runtime'], [STDIN, STDOUT, STDERR], $pipes, null, $environment);
    ensure(is_resource($process), 'selected frontend PHP driver could not start');
    exit(proc_close($process));
}
ensure(function_exists('filter_var'), 'selected frontend PHP driver has no filter capability');
$started = microtime(true);
$before = (new SourceTreeSnapshot($sourceMirror))->capture();
$root = sys_get_temp_dir() . '/webman-aot-carbon-frontend-' . bin2hex(random_bytes(6));
$mirror = $root . '/.webman-aot-builder/build/project';
mkdir(dirname($mirror), 0700, true);
try {
    echo "STAGE copy failed frontend inputs to an isolated fixture\n";
    copyTree($sourceMirror, $mirror, true);
    $lockFile = dirname(__DIR__) . '/compatibility/locks/webman-workerman-2026-09-25.json';
    mkdir($root . '/cache', 0700);
    if (is_string($generatorCache)) {
        $lock = json_decode(file_get_contents($lockFile), true, flags: JSON_THROW_ON_ERROR);
        copyTree($generatorCache, $root . '/cache/upstream-generator-' . $lock['generator']['revision']);
    } else {
        ensure(class_exists(ZipArchive::class), 'Pass the verified extracted generator cache when the prepared PHP has no ZIP extension');
    }
    echo "STAGE fresh generation and verified replacement dependency completion\n";
    $generated = (new UpstreamProjectGenerator())->generate($mirror, $archive, $root . '/cache', $lockFile, 'webman', 'webman-server', $tools['phpx'] . '/full-static/sdk', $tools['sdkContext']);
    echo "STAGE final full-static overlay retains all ignored declaration domains\n";
    (new FullStaticProjectOverlay())->apply($generated['projectFile'], $tools['sysroot'], 'webman', $tools['sdkContext']['sdkDirectory']);
    putenv('PHPX_HOME=' . $tools['phpx']);
    putenv('PHP_HOME=' . dirname($tools['php']));
    putenv('PHPRC=' . $tools['phprc']);
    putenv('PATH=' . dirname($tools['compiler']) . ':' . dirname($tools['php']) . ':/usr/bin:/bin');
    chdir($mirror);
    $compilerArguments = [$tools['typephp'] . '/bin/tpc.php', $mirror . '/project.linux.yml', '--full-static', '--compiler=clang++', '--job=4', '--no-progress'];
    $GLOBALS['argv'] = $compilerArguments;
    $GLOBALS['argc'] = count($compilerArguments);
    require $tools['typephp'] . '/bin/bootstrap.php';
    require $tools['typephp'] . '/src/polyfills.php';
    require $tools['typephp'] . '/src/gen_stub.php';
    echo "STAGE real TypePHP project loader, declaration preparation and recursive trait composition\n";
    $runtime = TypePhp\Build\CompilerRuntime::source($tools['typephp'], $compilerArguments[0]);
    $translator = TypePhp\Translator::getInstance($runtime);
    $translator->setDiagnosticReporter(new TypePhp\Diagnostics\ThrowingDiagnosticReporter());
    $project = $translator->parseArgv($compilerArguments);
    $files = $translator->prepare($project);
    ensure(in_array($mirror . '/vendor/nesbot/carbon/src/Carbon/Traits/LocalFactory.php', $files, true), 'LocalFactory was not prepared');
    ensure(in_array($mirror . '/.typephp/build/carbon-interval.php', $files, true), 'CarbonInterval replacement was not prepared');
    ensure(!in_array($mirror . '/vendor/nesbot/carbon/src/Carbon/CarbonInterval.php', $files, true), 'original and replacement CarbonInterval were both prepared');
    echo 'PASS real frontend prepare completed for ' . count($files) . " inputs\n";
    foreach (['.typephp/build/carbon-period.php', 'vendor/nesbot/carbon/src/Carbon/Carbon.php', 'vendor/nesbot/carbon/src/Carbon/CarbonImmutable.php', '.typephp/build/symfony-http-foundation-pdo-session-handler.php', '.typephp/build/symfony-http-kernel-error-listener.php'] as $replacement) {
        echo 'STAGE real TypePHP convert ' . $replacement . "\n";
        $input = $mirror . '/' . $replacement;
        ensure(in_array($input, $files, true), 'replacement is absent from prepared inputs: ' . $replacement);
        $output = $translator->convertFile($input, true);
        ensure(is_string($output) && is_file($output), 'replacement produced no C++ translation unit: ' . $replacement);
        echo 'PASS real TypePHP convert ' . $replacement . ' -> ' . basename($output) . "\n";
    }
    ensure((new SourceTreeSnapshot($sourceMirror))->capture() === $before, 'failed mirror inputs changed');
    echo 'PASS real frontend prepare, trait composition and five affected conversions completed for ' . count($files) . ' inputs in ' . round(microtime(true) - $started, 3) . "s; fixture unchanged\n";
} finally {
    chdir(dirname(__DIR__));
    removeTree($root);
}
