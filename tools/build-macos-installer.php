<?php

declare(strict_types=1);

use WebmanAotBuilder\Toolchain\NativeDownloader;

$root = dirname(__DIR__);
require $root . '/src/Toolchain/Downloader.php';
require $root . '/src/Toolchain/NativeDownloader.php';
require $root . '/src/Version.php';

/** @return array<string, mixed> */
function sourceJson(string $path): array
{
    $value = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException("无效 JSON：{$path}");
    }
    return $value;
}

/** @param list<string> $command */
function sourceProcess(array $command, string $cwd, bool $capture = false): string
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('无法启动构包子进程');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $started = microtime(true);
    $next = $started + 5;
    do {
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        if ($capture) {
            $output .= $stdout;
        } else {
            fwrite(STDOUT, $stdout);
        }
        fwrite(STDERR, $stderr);
        $status = proc_get_status($process);
        if ($status['running'] && microtime(true) >= $next) {
            fwrite(STDERR, sprintf("[状态] 当前阶段仍在执行，已用 %.1f 秒。\n", microtime(true) - $started));
            $next = microtime(true) + 5;
        }
        if ($status['running']) {
            usleep(100000);
        }
    } while ($status['running']);
    $tail = (string) stream_get_contents($pipes[1]);
    if ($capture) {
        $output .= $tail;
    } else {
        fwrite(STDOUT, $tail);
    }
    fwrite(STDERR, (string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closed = proc_close($process);
    $code = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
    if ($code !== 0) {
        throw new RuntimeException("子进程失败，退出码 {$code}", $code > 0 ? $code : 1);
    }
    return $output;
}

function sourceDownload(string $url, string $digest, string $inputs): string
{
    if (!str_starts_with($url, 'https://') || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
        throw new RuntimeException('材料下载锁无效');
    }
    $name = basename((string) parse_url($url, PHP_URL_PATH));
    $path = $inputs . '/' . $name;
    if (is_file($path) && !is_link($path) && hash_equals($digest, (string) hash_file('sha256', $path))) {
        fwrite(STDOUT, "[成功] 缓存 SHA-256 通过：{$name}，跳过下载。\n");
        return $path;
    }
    $partial = $path . '.partial';
    fwrite(STDOUT, "[下载] {$name}：显示下载量、速度和耗时；服务器未报告总量时不显示比例。\n");
    $started = microtime(true);
    $last = $started - 5;
    (new NativeDownloader())->download($url, $partial,
        static function (int $bytes, ?int $total = null) use ($name, $started, &$last): void {
            $now = microtime(true);
            if ($now - $last < 1) {
                return;
            }
            $elapsed = max(0.001, $now - $started);
            $percent = $total !== null && $total > 0 ? sprintf(' %.1f%%', min(99.9, $bytes * 100 / $total)) : '';
            fwrite(STDOUT, sprintf("[下载] %s%s，%.2f MiB，平均 %.2f MiB/秒，已用 %.1f 秒。\n", $name, $percent, $bytes / 1048576, $bytes / 1048576 / $elapsed, $elapsed));
            $last = $now;
        }, static fn (string $message) => fwrite(STDERR, $message . "\n"));
    if (!hash_equals($digest, (string) hash_file('sha256', $partial))) {
        throw new RuntimeException("SHA-256 不符，拒绝使用：{$name}；重试会重新核验输入");
    }
    if (!rename($partial, $path)) {
        throw new RuntimeException("无法保存已校验缓存：{$path}");
    }
    fwrite(STDOUT, sprintf("[成功] 下载及 SHA-256 通过：%s，耗时 %.1f 秒。\n", $name, microtime(true) - $started));
    return $path;
}

function sourceRemove(string $directory): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}

$started = microtime(true);
$scratch = null;
$exitCode = 0;
try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help') {
            fwrite(STDOUT, "Usage: tools/build-macos-installer.sh --flavor=small|full --result=<absolute> [--output=<directory>] [--inputs=<cache-directory>] [--minimal-component=<local-zip>] [--prepared-typephp=<patched-source>]\n");
            exit(0);
        }
        if (preg_match('/^--(materials|flavor|result|output|inputs|minimal-component|prepared-typephp)=(.+)$/D', $argument, $match) !== 1 || isset($options[$match[1]])) {
            throw new InvalidArgumentException("未知或重复参数：{$argument}", 64);
        }
        $options[$match[1]] = $match[2];
    }
    $flavor = $options['flavor'] ?? 'small';
    if (isset($options['prepared-typephp']) && !is_dir($options['prepared-typephp'])) {
        throw new RuntimeException('prepared TypePHP directory is missing');
    }
    $resultPath = $options['result'] ?? null;
    if (!in_array($flavor, ['small', 'full'], true) || !is_string($resultPath) || !str_starts_with($resultPath, '/')) {
        throw new InvalidArgumentException('需要 --flavor=small|full 和 --result=绝对路径', 64);
    }
    if (file_exists($resultPath) || is_link($resultPath) || file_exists($resultPath . '.package.json')) {
        throw new RuntimeException('结果文件已存在，请使用新的任务结果路径');
    }
    $materials = $options['materials'] ?? '';
    $materialLock = [];
    foreach (file($root . '/tools/source-build-materials.lock', FILE_IGNORE_NEW_LINES) as $line) {
        [$key, $value] = explode(' ', $line, 2);
        $materialLock[$key] = $value;
    }
    $runtime = sourceJson($root . '/installer/runtime.lock.json')['runtimes']['macos-arm64'];
    $php = $materials . '/' . $materialLock['runtime'];
    $compiler = $materials . '/' . $materialLock['compiler'];
    $phpSource = $materials . '/' . $materialLock['source'];
    foreach ([$php => $runtime['binarySha256'], $compiler => $runtime['compilerDriver']['binarySha256'], $phpSource => $runtime['compilerDriver']['sourceSha256']] as $path => $digest) {
        if (!is_file($path) || is_link($path) || !hash_equals($digest, (string) hash_file('sha256', $path))) {
            throw new RuntimeException("实际材料与 runtime.lock 不符：{$path}");
        }
    }
    $toolchain = sourceJson($root . '/toolchain.lock.json');
    $minimal = sourceJson($root . '/toolchain/minimal-components.lock.json');
    if (($minimal['toolchainLockSha256'] ?? '') !== hash_file('sha256', $root . '/toolchain.lock.json') || ($minimal['version'] ?? '') !== WebmanAotBuilder\Version::VALUE) {
        throw new RuntimeException('精简组件与当前 toolchain 锁或源码版本不一致');
    }
    $typePhp = null;
    foreach ($toolchain['components'] as $component) {
        if ($component['id'] === 'typephp-source') {
            $typePhp = $component;
        }
    }
    if (!is_array($typePhp)) {
        throw new RuntimeException('缺少锁定 TypePHP 输入');
    }
    $inputs = $options['inputs'] ?? $root . '/dist/installer-inputs';
    if (!is_dir($inputs) && !mkdir($inputs, 0700, true)) {
        throw new RuntimeException('无法创建材料缓存目录');
    }
    $typeArchive = sourceDownload($typePhp['sourceUrl'], $typePhp['sha256'], $inputs);
    $component = $minimal['components']['macos-arm64'];
    if (isset($options['minimal-component'])) {
        $minimalArchive = realpath($options['minimal-component']);
        if (!is_string($minimalArchive) || is_link($options['minimal-component'])
            || !is_file($minimalArchive) || !hash_equals($component['sha256'], (string) hash_file('sha256', $minimalArchive))) {
            throw new RuntimeException('本地精简组件与当前源码锁不一致');
        }
        fwrite(STDOUT, "[成功] 本地精简组件 SHA-256 与当前源码锁一致。\n");
    } else {
        $minimalArchive = sourceDownload('https://github.com/supdger/webman-aot-builder/releases/download/v' . $minimal['version'] . '/' . $component['archive'], $component['sha256'], $inputs);
    }
    $revision = 'source-snapshot';
    if (file_exists($root . '/.git')) {
        $revision = trim(sourceProcess(['git', 'rev-parse', 'HEAD'], $root, true));
        if (trim(sourceProcess(['git', 'status', '--porcelain'], $root, true)) !== '') {
            $revision .= '-dirty';
        }
    }
    fwrite(STDOUT, "[源码] {$revision}；本地开发快照，版本号相同不代表正式 Release 字节相同。\n");
    $output = $options['output'] ?? $root . '/dist/source-build';
    if (!str_starts_with($output, '/')) {
        $output = $root . '/' . $output;
    }
    if (!is_dir($output) && !mkdir($output, 0700, true)) {
        throw new RuntimeException('无法创建构包输出目录');
    }
    fwrite(STDOUT, "[开始] 制作 macOS {$flavor} 安装包。\n");
    $upgradeOptions = $flavor === 'small' && isset($options['prepared-typephp']) ? ['--prepared-typephp=' . $options['prepared-typephp']] : [];
    $json = sourceProcess([PHP_BINARY, '-n', $root . '/tools/package-installers.php', '--platform=macos-arm64', '--mac-runtime=' . $php, '--mac-compiler-driver=' . $compiler, '--mac-runtime-license-dir=' . $materials . '/' . $materialLock['licenses'], '--php-source-archive=' . $phpSource, '--typephp-source-archive=' . $typeArchive, '--minimal-component=' . $minimalArchive, '--flavor=' . $flavor, '--output=' . $output, '--revision=' . $revision, ...$upgradeOptions], $root, true);
    $packageResult = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $package = $packageResult['packages'][0] ?? null;
    if (($packageResult['schema'] ?? '') !== 'webman-aot-builder-installer-package-result-v1' || ($packageResult['revision'] ?? '') !== $revision || count($packageResult['packages'] ?? []) !== 1 || !is_array($package) || ($package['platform'] ?? '') !== 'macos-arm64') {
        throw new RuntimeException('构包器结果身份不符合请求');
    }
    $archive = realpath($package['path']);
    if (!is_string($archive) || !is_file($archive) || filesize($archive) !== $package['size'] || !hash_equals($package['sha256'], (string) hash_file('sha256', $archive))) {
        throw new RuntimeException('实际归档大小或 SHA-256 不符合构包结果');
    }
    $scratch = sys_get_temp_dir() . '/webman-aot-source-check-' . bin2hex(random_bytes(8));
    mkdir($scratch, 0700);
    mkdir($scratch . '/package', 0700);
    fwrite(STDOUT, "[开始] 解压实际新包，逐一核验 payload 清单，并在私有目录安装自检；不改用户 PATH。\n");
    sourceProcess(['tar', '-xzf', $archive, '-C', $scratch . '/package'], $root);
    $manifest = file($scratch . '/package/payload-manifest.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $listed = [];
    foreach ($manifest as $line) {
        if (preg_match('/^([a-f0-9]{64})  (payload\/.+)$/D', $line, $match) !== 1 || isset($listed[$match[2]]) || in_array('..', explode('/', $match[2]), true)) {
            throw new RuntimeException('包清单路径或摘要无效');
        }
        $listed[$match[2]] = true;
    }
    $actualFiles = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch . '/package/payload', FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr($file->getPathname(), strlen($scratch . '/package/'));
        if ($file->isLink() || !$file->isFile() || !isset($listed[$relative])) {
            throw new RuntimeException('包包含未登记文件或链接：' . $relative);
        }
        $actualFiles++;
    }
    if ($actualFiles !== count($listed)) {
        throw new RuntimeException('包清单文件数量与实际 payload 不同');
    }
    sourceProcess(['shasum', '-a', '256', '-c', 'payload-manifest.sha256'], $scratch . '/package', true);
    fwrite(STDOUT, "[成功] 实际 payload 共 {$actualFiles} 个文件，清单摘要与文件集合完整一致。\n");
    sourceProcess(['sh', $scratch . '/package/install.sh', '--home', $scratch . '/home', '--bin-dir', $scratch . '/bin', '--no-path'], $root);
    $oldHome = getenv('WEBMAN_AOT_BUILDER_HOME');
    putenv('WEBMAN_AOT_BUILDER_HOME=' . $scratch . '/home');
    try {
        $version = trim(sourceProcess([$scratch . '/bin/webman-aot', 'version'], $root, true));
    } finally {
        $oldHome === false ? putenv('WEBMAN_AOT_BUILDER_HOME') : putenv('WEBMAN_AOT_BUILDER_HOME=' . $oldHome);
    }
    if ($version !== 'webman-aot ' . WebmanAotBuilder\Version::VALUE) {
        throw new RuntimeException("新安装版本自检失败：{$version}");
    }
    file_put_contents($resultPath . '.package.json', $json);
    $result = ['schema' => 'webman-aot-builder-source-build-result-v1', 'platform' => 'macos-arm64', 'flavor' => $flavor, 'revision' => $revision, 'archive' => $archive, 'size' => filesize($archive), 'sha256' => hash_file('sha256', $archive), 'verified' => ['payload-manifest', 'isolated-install-version']];
    $temporaryResult = $resultPath . '.tmp-' . bin2hex(random_bytes(8));
    if (file_put_contents($temporaryResult, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false || !rename($temporaryResult, $resultPath)) {
        throw new RuntimeException('无法原子发布成功结果');
    }
    fwrite(STDOUT, sprintf("[成功] 安装包与新安装版本自检通过：%s\n包类型：%s；大小：%.2f MiB；总耗时：%.1f 秒。\n", $archive, $flavor, filesize($archive) / 1048576, microtime(true) - $started));
} catch (Throwable $error) {
    fwrite(STDERR, sprintf("[失败] 构包/自检已停止，耗时 %.1f 秒：%s\n请检查上方原始错误，修复网络或输入后重试；已校验缓存可复用。\n问题反馈：https://github.com/supdger/webman-aot-builder/issues\n", microtime(true) - $started, $error->getMessage()));
    $exitCode = $error->getCode() > 0 && $error->getCode() < 256 ? $error->getCode() : 1;
} finally {
    if (is_string($scratch) && is_dir($scratch)) {
        sourceRemove($scratch);
        fwrite(STDOUT, "[清理] 本次私有安装自检目录已清理。\n");
    }
}

exit($exitCode);
