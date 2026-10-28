<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ProgressOutput;
use WebmanAotBuilder\Toolchain\NativeDownloader;

require dirname(__DIR__) . '/src/Cli/ProgressOutput.php';
require dirname(__DIR__) . '/src/Toolchain/Downloader.php';
require dirname(__DIR__) . '/src/Toolchain/NativeDownloader.php';
require dirname(__DIR__) . '/src/Version.php';

if (PHP_INT_SIZE !== 8 || !class_exists(ZipArchive::class) || !class_exists(Phar::class)) {
    fwrite(STDERR, "64-bit PHP with ZIP and Phar capabilities is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$output = $root . '/dist/source-build';
$compare = null;
$revision = 'v' . WebmanAotBuilder\Version::VALUE;
$revisionProvided = false;
$flavor = 'small';
$preparedTypephp = null;
$minimalComponentInput = null;
$resultPath = null;
$inputDirectory = $root . '/dist/installer-inputs';

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        fwrite(STDOUT, "Usage: php tools/build-windows-installer.php [--flavor=small|full] [--minimal-component=<local-zip>] [--prepared-typephp=<patched-source>] [--compare=<local-zip>] [--inputs=<cache-directory>] [--output=<directory>] [--revision=<value>]\n");
        exit(0);
    }
    if (str_starts_with($argument, '--result=')) {
        $resultPath = substr($argument, strlen('--result='));
    } elseif (str_starts_with($argument, '--inputs=')) {
        $inputDirectory = substr($argument, strlen('--inputs='));
    } elseif (str_starts_with($argument, '--compare=')) {
        $compare = substr($argument, strlen('--compare='));
    } elseif (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } elseif (str_starts_with($argument, '--revision=')) {
        $revision = substr($argument, strlen('--revision='));
        $revisionProvided = true;
    } elseif (str_starts_with($argument, '--prepared-typephp=')) {
        $preparedTypephp = substr($argument, strlen('--prepared-typephp='));
    } elseif (str_starts_with($argument, '--flavor=')) {
        $flavor = substr($argument, strlen('--flavor='));
    } elseif (str_starts_with($argument, '--minimal-component=')) {
        $minimalComponentInput = substr($argument, strlen('--minimal-component='));
    } else {
        fwrite(STDERR, "Unknown option: {$argument}\n");
        exit(2);
    }
}

if ($preparedTypephp !== null && !is_dir($preparedTypephp)) {
    fwrite(STDERR, 'prepared TypePHP directory is missing' . PHP_EOL);
    exit(64);
}

if (($resultPath !== null && (preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $resultPath) !== 1 || file_exists($resultPath) || file_exists($resultPath . '.pending')))
    || $output === '' || $revision === '' || !in_array($flavor, ['small', 'full'], true)
    || ($compare !== null && !is_file($compare))
    || ($minimalComponentInput !== null && !is_file($minimalComponentInput))) {
    fwrite(STDERR, "Output and revision must be non-empty; --compare must name an existing ZIP.\n");
    exit(2);
}
if (preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $output) !== 1) {
    $output = $root . '/' . $output;
}

/**
 * @return array<string, mixed>
 */
function readLockedJson(string $path): array
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException("Unable to read lock file: {$path}");
    }
    $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException("Invalid lock file: {$path}");
    }
    return $value;
}

function verifiedInput(string $url, string $sha256, string $directory): string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
        throw new RuntimeException('Locked input has an invalid SHA-256');
    }
    $name = basename((string) parse_url($url, PHP_URL_PATH));
    if ($name === '' || $name === '.' || $name === '..') {
        throw new RuntimeException('Locked input has no filename');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create input directory: {$directory}");
    }
    $path = $directory . '/' . $name;
    if (is_file($path) && hash_equals($sha256, (string) hash_file('sha256', $path))) {
        fwrite(STDOUT, "[OK] Reusing SHA-256 verified {$name}\n");
        return $path;
    }

    $partial = $path . '.partial';
    if (is_file($partial) && hash_equals($sha256, (string) hash_file('sha256', $partial))) {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException("Unable to replace stale input: {$name}");
        }
        if (!rename($partial, $path)) {
            throw new RuntimeException("Unable to activate verified input: {$name}");
        }
        fwrite(STDOUT, "[OK] Reusing SHA-256 verified partial {$name}\n");
        return $path;
    }
    // Display hints only; SHA-256 remains the authority for accepted inputs.
    $expectedBytes = match ($sha256) {
        '2cf521fb6bcb45b634c7e9a7ab2afb6d41698b987bbc73c4975d90a065e0f16c' => 35113790,
        '7e5fe3549397e819c20b82c4586af5cad7cb03d6743d86fbca5094d629894902' => 14289309,
        default => null,
    };
    if ($expectedBytes !== null && is_file($partial) && filesize($partial) > $expectedBytes) {
        unlink($partial);
    }
    $output = new ProgressOutput(STDOUT);
    $lastBytes = 0;
    $downloadStarted = microtime(true);
    $showProgress = static function (int $bytes, ?int $reportedTotal = null, bool $verified = false) use (
        $name,
        $expectedBytes,
        $output,
        &$lastBytes,
        $downloadStarted
    ): void {
        $total = $reportedTotal ?? $expectedBytes;
        $status = $total === null
            ? sprintf('[download] %s: %.1f MiB', $name, $bytes / 1048576)
            : sprintf(
                '[download] %s: %.1f%%',
                $name,
                min($verified ? 100 : 99.9, $bytes * 100 / $total)
            );
        $elapsed = max(0.001, microtime(true) - $downloadStarted);
        $status .= sprintf('，%.2f MiB，平均 %.2f MiB/秒，已用 %.1f 秒', $bytes / 1048576, $bytes / 1048576 / $elapsed, $elapsed);
        if ($bytes < $lastBytes) {
            $status .= ' (retrying from start)';
        }
        $output->update($status);
        $lastBytes = $bytes;
    };
    $showProgress(0);
    try {
        try {
            (new NativeDownloader())->download(
                $url,
                $partial,
                $showProgress,
                static function (string $message) use ($output): void {
                    $output->finish();
                    fwrite(STDERR, $message . "\n");
                }
            );
        } catch (RuntimeException $exception) {
            $output->finish();
            throw new RuntimeException(
                "Download failed for {$name}. Check the connection and rerun the same build command; "
                . "the partial file will be resumed when the source supports ranges. "
                . "Completed SHA-256 verified inputs will be reused. {$exception->getMessage()}",
                previous: $exception
            );
        }
        clearstatcache(true, $partial);
        $bytes = filesize($partial);
        if (!hash_equals($sha256, (string) hash_file('sha256', $partial))) {
            unlink($partial);
            throw new RuntimeException("Downloaded input SHA-256 mismatch: {$name}");
        }
        $showProgress(is_int($bytes) ? $bytes : 0, null, true);
        $output->finish();
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException("Unable to replace stale input: {$name}");
        }
        if (!rename($partial, $path)) {
            throw new RuntimeException("Unable to activate verified input: {$name}");
        }
    } finally {
        $output->finish();
    }
    fwrite(STDOUT, "[OK] SHA-256 verified {$name}\n");
    return $path;
}

/**
 * @return array<string, string>
 */
function zipDigests(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException("Unable to open installer ZIP: {$path}");
    }
    try {
        $digests = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || $name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (isset($digests[$name])
                || str_starts_with($name, '/')
                || in_array('..', explode('/', $name), true)
            ) {
                throw new RuntimeException("Unsafe or repeated installer entry: {$name}");
            }
            $stream = $zip->getStream($name);
            if (!is_resource($stream)) {
                throw new RuntimeException("Unable to read installer entry: {$name}");
            }
            $digest = hash_init('sha256');
            hash_update_stream($digest, $stream);
            fclose($stream);
            $digests[$name] = hash_final($digest);
        }
        ksort($digests, SORT_STRING);
        $manifest = $zip->getFromName('payload-manifest.sha256');
        if (!is_string($manifest)) {
            throw new RuntimeException("Installer payload manifest is missing: {$path}");
        }
        if (trim($manifest) === '') {
            throw new RuntimeException("Installer payload manifest is empty: {$path}");
        }
        $listed = [];
        foreach (explode("\n", trim($manifest)) as $line) {
            if (preg_match('/^([a-f0-9]{64})  (payload\/.+)$/D', $line, $matches) !== 1
                || isset($listed[$matches[2]])
                || ($digests[$matches[2]] ?? null) !== $matches[1]
            ) {
                throw new RuntimeException("Installer payload verification failed: {$path}");
            }
            $listed[$matches[2]] = true;
        }
        foreach ($digests as $name => $_) {
            if (str_starts_with($name, 'payload/') && !isset($listed[$name])) {
                throw new RuntimeException("Installer has an unlisted payload file: {$name}");
            }
        }
        return $digests;
    } finally {
        $zip->close();
    }
}

function referenceRevision(string $path): string
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException("Unable to open comparison ZIP: {$path}");
    }
    try {
        $contents = $zip->getFromName('package.json');
    } finally {
        $zip->close();
    }
    if (!is_string($contents)) {
        throw new RuntimeException('Comparison ZIP has no package.json');
    }
    $package = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($package)
        || ($package['schema'] ?? null) !== 'webman-aot-builder-installer-package-v1'
        || ($package['version'] ?? null) !== WebmanAotBuilder\Version::VALUE
        || ($package['platform'] ?? null) !== 'windows-x86_64'
        || !is_string($package['revision'] ?? null)
        || preg_match('/^[a-zA-Z0-9._-]{1,128}$/D', $package['revision']) !== 1
    ) {
        throw new RuntimeException('Comparison ZIP has incompatible package metadata');
    }
    return $package['revision'];
}

$startedAt = microtime(true);
try {
    if ($compare !== null && !$revisionProvided) {
        fwrite(STDOUT, "[verify] Checking reference installer contents ...\n");
        zipDigests($compare);
        $revision = referenceRevision($compare);
        fwrite(STDOUT, "[OK] Using verified reference revision: {$revision}\n");
    }
    $runtimeLock = readLockedJson($root . '/installer/runtime.lock.json');
    $windowsRuntime = $runtimeLock['runtimes']['windows-x86_64'] ?? null;
    $toolchainLock = readLockedJson($root . '/toolchain.lock.json');
    $minimalLock = readLockedJson($root . '/toolchain/minimal-components.lock.json');
    $minimalWindows = $minimalLock['components']['windows-x86_64'] ?? null;
    if (($minimalLock['schema'] ?? null) !== 'webman-aot-builder-minimal-components-lock-v1'
        || ($minimalLock['version'] ?? null) !== WebmanAotBuilder\Version::VALUE
        || ($minimalLock['toolchainLockSha256'] ?? null)
            !== hash_file('sha256', $root . '/toolchain.lock.json')
        || !is_array($minimalWindows)
        || !preg_match('/^[a-f0-9]{64}$/D', (string) ($minimalWindows['sha256'] ?? ''))
        || ($minimalWindows['archive'] ?? null) !== 'webman-aot-builder-'
            . WebmanAotBuilder\Version::VALUE . '-windows-x86_64-components.zip'
    ) {
        throw new RuntimeException('Locked minimal Windows component is invalid');
    }
    $typePhp = null;
    foreach ($toolchainLock['components'] ?? [] as $component) {
        if (is_array($component) && ($component['id'] ?? null) === 'typephp-source') {
            $typePhp = $component;
            break;
        }
    }
    if (!is_array($windowsRuntime) || !is_array($typePhp)) {
        throw new RuntimeException('Locked Windows runtime or TypePHP source is missing');
    }
    $inputs = $inputDirectory;
    fwrite(STDOUT, "[prepare] Checking locked PHP and TypePHP inputs ...\n");
    $phpArchive = verifiedInput(
        (string) $windowsRuntime['archiveUrl'],
        (string) $windowsRuntime['archiveSha256'],
        $inputs
    );
    $typePhpArchive = verifiedInput(
        (string) $typePhp['sourceUrl'],
        (string) $typePhp['sha256'],
        $inputs
    );
    if ($minimalComponentInput !== null) {
        $minimalComponent = realpath($minimalComponentInput);
        if (!is_string($minimalComponent)
            || !hash_equals((string) $minimalWindows['sha256'], (string) hash_file('sha256', $minimalComponent))) {
            throw new RuntimeException('Local minimal Windows component differs from the source lock');
        }
        fwrite(STDOUT, "[OK] Reusing SHA-256 verified local minimal Windows component\n");
    } else {
        $minimalComponent = verifiedInput(
            'https://github.com/supdger/webman-aot-builder/releases/download/v'
                . WebmanAotBuilder\Version::VALUE . '/' . $minimalWindows['archive'],
            (string) $minimalWindows['sha256'],
            $inputs
        );
    }
    if (!is_dir($output) && !mkdir($output, 0700, true) && !is_dir($output)) {
        throw new RuntimeException("Unable to create output directory: {$output}");
    }
    $archive = $output . '/webman-aot-builder-' . WebmanAotBuilder\Version::VALUE
        . ($flavor === 'full' ? '-full' : '') . '-windows-x86_64.zip';
    if ($compare !== null && is_file($archive) && realpath($archive) === realpath($compare)) {
        throw new RuntimeException('Comparison ZIP must not be the output ZIP');
    }

    $command = [PHP_BINARY];
    $packageCwd = $root;
    $packageEnvironment = null;
    $sourceRuntime = getenv('WEBMAN_AOT_SOURCE_PHP_RUNTIME');
    if (PHP_OS_FAMILY === 'Windows' && $sourceRuntime !== false) {
        if (preg_match('~^(?:[A-Za-z]:[\\\\/]|\\\\\\\\)~', $sourceRuntime) !== 1
            || !is_file($sourceRuntime . '/php.ini')
            || !is_file($sourceRuntime . '/php.exe')
            || !hash_equals((string) $windowsRuntime['binarySha256'],
                (string) hash_file('sha256', $sourceRuntime . '/php.exe'))) {
            throw new RuntimeException('Source PHP runtime path or locked binary is invalid');
        }
        $command = [$sourceRuntime . '/php.exe', '-c', 'php.ini', '-d', 'extension_dir=ext',
            $root . '/tools/windows-php-bootstrap.php'];
        $packageCwd = $sourceRuntime;
        $packageEnvironment = getenv();
        $packageEnvironment['WEBMAN_AOT_CALLER_CWD'] = $root;
    } else {
        $loadedIni = php_ini_loaded_file();
        if (is_string($loadedIni) && $loadedIni !== '') {
            $command[] = '-c';
            $command[] = $loadedIni;
        }
    }
    array_push(
        $command,
        $root . '/tools/package-installers.php',
        '--platform=windows-x86_64',
        '--windows-runtime-archive=' . $phpArchive,
        '--typephp-source-archive=' . $typePhpArchive,
        '--minimal-component=' . $minimalComponent,
        '--flavor=' . $flavor,
        '--output=' . $output,
        '--revision=' . $revision,
    );
    if ($flavor === 'small' && $preparedTypephp !== null) {
        $command[] = '--prepared-typephp=' . $preparedTypephp;
    }
    fwrite(STDOUT, "[build] Packaging Windows installer; this can take several minutes ...\n");
    $process = proc_open(
        $command,
        [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']],
        $pipes,
        $packageCwd,
        $packageEnvironment,
        PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : []
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Windows installer packager');
    }
    $packageStartedAt = microtime(true);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $result = '';
    $error = '';
    $nextReport = microtime(true) + 5;
    while (true) {
        $result .= (string) stream_get_contents($pipes[1]);
        $chunk = (string) stream_get_contents($pipes[2]);
        $error .= $chunk;
        fwrite(STDERR, $chunk);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) >= $nextReport) {
            fwrite(STDOUT, sprintf(
                "[build] Packaging still running after %.1f seconds ...\n",
                microtime(true) - $packageStartedAt
            ));
            $nextReport = microtime(true) + 5;
        }
        usleep(200000);
    }
    stream_set_blocking($pipes[1], true);
    stream_set_blocking($pipes[2], true);
    $result .= (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $error .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $closed = proc_close($process);
    if (($status['exitcode'] >= 0 ? $status['exitcode'] : $closed) !== 0) {
        throw new RuntimeException('Windows installer packaging failed: ' . trim($error));
    }
    $packageResult = is_string($result) ? json_decode($result, true) : null;
    if (!is_array($packageResult)
        || ($packageResult['revision'] ?? null) !== $revision
        || ($packageResult['packages'][0]['platform'] ?? null) !== 'windows-x86_64'
    ) {
        throw new RuntimeException('Windows installer packager returned unexpected metadata');
    }
    if (!is_file($archive)) {
        throw new RuntimeException('Windows installer was not produced');
    }
    fwrite(STDOUT, "[verify] Checking built installer contents ...\n");
    zipDigests($archive);
    fwrite(STDOUT, "[OK] Built and internally verified: {$archive}\n");
    fwrite(STDOUT, "[OK] Installer ZIP SHA-256: " . hash_file('sha256', $archive) . "\n");
    if ($compare !== null) {
        fwrite(STDOUT, "[compare] Comparing source-built and reference files ...\n");
        $actual = zipDigests($archive);
        $published = zipDigests($compare);
        if ($actual !== $published) {
            $names = array_unique(array_merge(array_keys($actual), array_keys($published)));
            sort($names, SORT_STRING);
            $different = array_values(array_filter(
                $names,
                static fn (string $name): bool => ($actual[$name] ?? null) !== ($published[$name] ?? null)
            ));
            throw new RuntimeException(
                'Source-built and published installer contents differ: ' . implode(', ', array_slice($different, 0, 10))
            );
        }
        fwrite(STDOUT, "[MATCH] Source-built and reference installers contain the same "
            . count($actual) . " verified files.\n");
        fwrite(STDOUT, "ZIP byte hashes can differ because archive metadata or compression differs.\n");
    }
    if ($resultPath !== null) {
        // The PS owner publishes only after its isolated install/version succeeds.
        $pending = $resultPath . '.pending';
        $metadata = ['schema' => 'webman-aot-builder-source-build-result-v1',
            'platform' => 'windows-x86_64', 'flavor' => $flavor, 'revision' => $revision,
            'archive' => realpath($archive), 'size' => filesize($archive),
            'sha256' => hash_file('sha256', $archive), 'verified' => ['payload-manifest']];
        if (file_put_contents($pending, json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
            throw new RuntimeException('Unable to stage source-build result');
        }
    }
    fwrite(STDOUT, sprintf("[OK] Source build finished in %.1f seconds.\n", microtime(true) - $startedAt));
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf(
        "[ERROR] Source build failed after %.1f seconds: %s\n",
        microtime(true) - $startedAt,
        $exception->getMessage()
    ));
    exit(1);
}
