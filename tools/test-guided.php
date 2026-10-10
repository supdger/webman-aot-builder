<?php

declare(strict_types=1);

// Behavioral regression: real processes and private fixtures; never installs to the user's home.
$root = dirname(__DIR__);
if (PHP_OS_FAMILY !== 'Darwin' || php_uname('m') !== 'arm64') {
    fwrite(STDERR, "This flow regression needs macOS ARM64; Windows native acceptance is separate.\n");
    exit(78);
}
$base = $argv[1] ?? sys_get_temp_dir() . '/webman-aot-guided-test-' . bin2hex(random_bytes(6));
if (file_exists($base)) {
    fwrite(STDERR, "Fixture directory must be new: {$base}\n");
    exit(64);
}
mkdir($base, 0700, true);
$package = $base . '/安装包 with spaces';
$home = $base . '/private home 中文';
$bin = $base . '/private bin 中文';
$project = $base . '/项目 with spaces';
foreach ([$package, $project, $package . '/payload', $package . '/tools'] as $directory) {
    mkdir($directory, 0700, true);
}
file_put_contents($project . '/composer.json', '{"require":{"workerman/webman-framework":"*"}}');
file_put_contents($project . '/start.php', '<?php');
file_put_contents($package . '/payload-manifest.sha256', 'fixture');
file_put_contents($package . '/package.json', json_encode(['schema' => 'webman-aot-builder-installer-package-v1', 'platform' => 'macos-arm64', 'version' => 'fixture-version', 'flavor' => 'small']));
$fixtureLauncher = <<<'SH'
#!/bin/sh
set -eu
[ "$WEBMAN_AOT_BUILDER_HOME" = "$(dirname "$0")/../private home 中文" ] || exit 62
printf '%s:%s\n' "$1" "$PWD" >> "$WEBMAN_AOT_BUILDER_HOME/events"
case "$1" in
version) echo 'webman-aot fixture-version';;
build)
    if [ "${2:-}" = '--fresh' ]; then echo fresh > "$WEBMAN_AOT_BUILDER_HOME/fresh-used"; fi
    if [ -e fail-once ]; then rm fail-once; echo 'fixture retry required' >&2; exit 23; fi
    [ ! -e fail-build ] || { echo 'fixture compiler failed' >&2; exit 23; }
    mkdir -p dist-aot
    echo 'fixture compiled';;
verify)
    [ ! -e fail-verify ] || { echo 'fixture verifier failed' >&2; exit 24; }
    if [ -e bad-report ]; then echo '{"schema":"wrong"}'; exit 0; fi
    printf '{"schema":"webman-aot-builder-verify-report-v1","path":"%s/dist-aot","scope":"build-host-structure-and-integrity","staticStructure":"pass"}\n' "$PWD";;
*) exit 64;;
esac
SH;
$fixtureLauncher = str_replace('[ "$WEBMAN_AOT_BUILDER_HOME" = "$(dirname "$0")/../private home 中文" ] || exit 62', '[ -d "$WEBMAN_AOT_BUILDER_HOME/current" ] || exit 62', $fixtureLauncher);
file_put_contents($package . '/launcher', $fixtureLauncher);
$installer = <<<'SH'
#!/bin/sh
set -eu
home=''; bin=''; no_path=0
while [ "$#" -gt 0 ]; do
  case "$1" in
    --home) home=$2; shift;;
    --bin-dir) bin=$2; shift;;
    --no-path) no_path=1;;
    *) exit 64;;
  esac
  shift
done
[ "$no_path" = 1 ] || exit 63
mkdir -p "$home/current" "$bin"
cp "$(dirname "$0")/launcher" "$bin/webman-aot"
chmod 700 "$bin/webman-aot"
echo 'fixture installed'
SH;
file_put_contents($package . '/install.sh', $installer);
$log = $base . '/results.log';
$count = 0;
function check(bool $condition, string $name): void
{
    global $count;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    $count++;
    fwrite(STDOUT, "PASS {$count}: {$name}\n");
}
/** @param list<string> $args @return array{code:int,text:string} */
function invoke(array $args, string $input = '', ?string $entry = null, ?callable $observe = null, array $environment = []): array
{
    global $root, $log;
    $p = proc_open([PHP_BINARY, $entry ?? $root . '/tools/guided.php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, array_merge(getenv(), $environment));
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    $text = '';
    $started = microtime(true);
    while (true) {
        $chunk = stream_get_contents($pipes[1]);
        $status = proc_get_status($p);
        if (is_string($chunk) && $chunk !== '') {
            $text .= $chunk;
            file_put_contents($log, $chunk, FILE_APPEND);
            if ($observe !== null) $observe($chunk, microtime(true) - $started, $status['running']);
        }
        if (!$status['running']) {
            $code = $status['exitcode'];
            $tail = stream_get_contents($pipes[1]);
            $text .= $tail;
            file_put_contents($log, $tail, FILE_APPEND);
            break;
        }
        usleep(20000);
    }
    fclose($pipes[1]);
    $closeCode = proc_close($p);
    if ($code < 0) $code = $closeCode;
    file_put_contents($log, "\n[exit {$code}]\n", FILE_APPEND);
    return ['code' => $code, 'text' => $text];
}
$common = ['--home=' . $home, '--bin-dir=' . $bin, '--no-path'];
$install = ['--mode=install', '--package-root=' . $package, ...$common];
try {
    $r = invoke(['--unknown']);
    check($r['code'] !== 0 && str_contains($r['text'], '无效选项'), 'unknown options fail with help');
    $r = invoke(['--help']);
    check($r['code'] === 0 && str_contains($r['text'], '--install'), 'help explains explicit installation');
    $r = invoke($install, '0' . "\n");
    check($r['code'] === 0 && !file_exists($home) && !file_exists($bin), 'cancel writes no installation');
    $r = invoke($install);
    check($r['code'] === 0 && !file_exists($home), 'non-TTY EOF safely cancels');
    $openPipe = proc_open([PHP_BINARY, $root . '/tools/guided.php', ...$install], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $openPipes);
    $deadline = microtime(true) + 2;
    do {
        usleep(20000);
        $openStatus = proc_get_status($openPipe);
    } while ($openStatus['running'] && microtime(true) < $deadline);
    if ($openStatus['running']) proc_terminate($openPipe);
    fclose($openPipes[0]);
    $openText = stream_get_contents($openPipes[1]);
    fclose($openPipes[1]);
    proc_close($openPipe);
    check(!$openStatus['running'] && $openStatus['exitcode'] === 0 && !file_exists($home), 'open non-TTY pipe without input cannot wait forever');
    $r = invoke($install, "invalid\n1\n0\n");
    check($r['code'] === 0 && str_contains($r['text'], '选择无效') && is_file($bin . '/webman-aot'), 'invalid choice retries and confirmed installation works');
    $identityPath = $package . '/package.json';
    $originalIdentity = (string) file_get_contents($identityPath);
    $identity = json_decode($originalIdentity, true);
    $identity['version'] = 'different-version';
    file_put_contents($identityPath, json_encode($identity));
    $rMismatch = invoke([...$install, '--install']);
    check($rMismatch['code'] !== 0 && str_contains($rMismatch['text'], '新安装版本与本包版本不一致'), 'new launcher version must match package identity');
    file_put_contents($identityPath, $originalIdentity);
    $events = (string) file_get_contents($home . '/events');
    check(str_contains($events, 'version:') && str_contains($r['text'], 'fixture-version'), 'new absolute launcher validates version with private home');
    $oldBin = $base . '/old-path';
    mkdir($oldBin);
    file_put_contents($oldBin . '/webman-aot', "#!/bin/sh\necho OLD_PATH_MUST_NOT_RUN\nexit 99\n");
    chmod($oldBin . '/webman-aot', 0700);
    $r = invoke(['--mode=project', '--project=' . $project, ...$common], '', null, null, ['PATH' => $oldBin . ':' . getenv('PATH')]);
    check($r['code'] === 0 && !str_contains($r['text'], 'OLD_PATH_MUST_NOT_RUN'), 'existing PATH command cannot impersonate selected installation');
    $events = (string) file_get_contents($home . '/events');
    check($r['code'] === 0 && str_contains($events, 'build:' . $project) && str_contains($events, 'verify:' . $project), 'space and Chinese directory reaches build then verify');
    check(!str_contains($r['text'], '"schema"') && str_contains($r['text'], '本机构建产物结构和完整性') && str_contains($r['text'], '不代表 Linux'), 'machine report becomes accurate human scope');
    file_put_contents($project . '/fail-build', '');
    file_put_contents($home . '/events', '');
    $r = invoke(['--mode=project', '--project=' . $project, ...$common]);
    check($r['code'] === 23 && !str_contains((string) file_get_contents($home . '/events'), 'verify:'), 'build failure preserves exit code and never verifies');
    check(str_contains($r['text'], 'fixture compiler failed') && str_contains($r['text'], '/issues'), 'original error and recovery link visible');
    unlink($project . '/fail-build');
    file_put_contents($project . '/fail-once', '');
    $r = invoke(['--mode=project', '--project=' . $project, ...$common], "1\n");
    check($r['code'] === 0 && !file_exists($home . '/fresh-used') && str_contains($r['text'], '继续编译'), 'guided normal retry uses the original build command');
    file_put_contents($project . '/fail-once', '');
    $r = invoke(['--mode=project', '--project=' . $project, ...$common], "3\n");
    check($r['code'] === 0 && is_file($home . '/fresh-used') && str_contains($r['text'], '全量重建'), 'guided explicit full rebuild passes fresh to the public launcher');
    unlink($home . '/fresh-used');
    $r = invoke(['--mode=project', '--project=' . $base . '/missing', ...$common], "2\n{$project}\n");
    check($r['code'] === 0 && str_contains($r['text'], '项目目录不存在'), 'invalid project allows reselection');
    file_put_contents($project . '/bad-report', '');
    $r = invoke(['--mode=project', '--project=' . $project, ...$common]);
    check($r['code'] !== 0 && str_contains($r['text'], '校验结果无效'), 'invalid verify machine result rejected');
    unlink($project . '/bad-report');
    file_put_contents($project . '/fail-verify', '');
    $r = invoke(['--mode=project', '--project=' . $project, ...$common]);
    check($r['code'] === 24 && str_contains($r['text'], 'fixture verifier failed'), 'verify failure preserves original process error');
    unlink($project . '/fail-verify');
    // A fake source backend runs as a real shell process and publishes machine contract data.
    $source = $base . '/source';
    mkdir($source . '/tools', 0700, true);
    $wrapper = $source . '/entry.php';
    file_put_contents($wrapper, '<?php require ' . var_export($root . '/src/Cli/ProcessOutput.php', true) . '; require ' . var_export($root . '/src/Guided/ProcessRunner.php', true) . '; require ' . var_export($root . '/src/Guided/ProcessFailure.php', true) . '; require ' . var_export($root . '/src/Guided/Flow.php', true) . '; exit((new WebmanAotBuilder\\Guided\\Flow(__DIR__))->run(array_slice($argv,1)));');
    $backendPhp = $source . '/tools/backend.php';
    file_put_contents($backendPhp, <<<'BACKEND'
<?php
foreach (array_slice($argv,1) as $argument) {
    if (str_starts_with($argument,'--result=')) $path=substr($argument,9);
}
$archive=__DIR__.'/archive.tar'; file_put_contents($archive,'fixture');
if (is_file(__DIR__.'/fail')) { fwrite(STDERR,"fixture backend failed\n"); exit(31); }
if (is_file(__DIR__.'/permission-error')) { fwrite(STDERR,"[ERROR] unable to open new phar /private/package.tar for writing\n问题反馈：https://github.com/supdger/webman-aot-builder/issues\n"); exit(13); }
if (is_file(__DIR__.'/http-error')) { fwrite(STDERR,"curl: (22) The requested URL returned error: 503\n问题反馈：https://github.com/supdger/webman-aot-builder/issues\n"); exit(22); }
if (is_file(__DIR__.'/missing')) exit(0);
$data=['schema'=>'webman-aot-builder-source-build-result-v1','platform'=>'macos-arm64','flavor'=>'small','revision'=>'fixture','archive'=>$archive,'size'=>filesize($archive),'sha256'=>hash_file('sha256',$archive),'verified'=>['payload-manifest','isolated-install-version']];
if (is_file(__DIR__.'/corrupt')) $data['sha256']=str_repeat('0',64);
if (is_file(__DIR__.'/malformed')) $data['verified']=[];
file_put_contents($path,json_encode($data)); echo "fixture backend complete\n";
BACKEND);
    file_put_contents($source . '/tools/build-macos-installer.sh', "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($backendPhp) . ' "$@"' . "\n");
    $r = invoke(['--mode=source', '--flavor=small', ...$common], "0\n", $wrapper);
    check($r['code'] === 0 && str_contains($r['text'], '安装包已就绪') && str_contains($r['text'], '未执行安装'), 'source result validates actual archive and cancel stops before extraction');
    foreach (['missing', 'corrupt', 'malformed', 'fail'] as $failure) {
        file_put_contents($source . '/tools/' . $failure, '');
        $r = invoke(['--mode=source', '--flavor=small', '--install', ...$common], '', $wrapper);
        check($r['code'] === ($failure === 'fail' ? 31 : 1) && !str_contains($r['text'], '[开始] 解压'), 'source ' . $failure . ' result prevents dependent installation');
        unlink($source . '/tools/' . $failure);
    }
    foreach (['permission-error', 'http-error'] as $error) {
        file_put_contents($source . '/tools/' . $error, '');
        $r = invoke(['--mode=source', '--flavor=small', '--install', ...$common], '', $wrapper);
        if ($error === 'permission-error') {
            check($r['code'] === 13 && str_contains($r['text'], 'unable to open new phar') && str_contains($r['text'], '输出目录无法写入') && !str_contains($r['text'], '下载服务器返回 HTTP 错误'), 'real permission error with Issues URL is not misclassified as HTTP');
        } else {
            check($r['code'] === 22 && str_contains($r['text'], 'curl: (22)') && str_contains($r['text'], '下载服务器返回 HTTP 错误'), 'real curl HTTP failure keeps actionable HTTP advice');
        }
        unlink($source . '/tools/' . $error);
    }
    // Real quiet child confirms the 5-second live status and streamed stderr.
    $runnerEntry = $base . '/runner.php';
    file_put_contents($runnerEntry, '<?php require ' . var_export($root . '/src/Cli/ProcessOutput.php', true) . '; require ' . var_export($root . '/src/Guided/ProcessRunner.php', true) . '; $r=(new WebmanAotBuilder\\Guided\\ProcessRunner(' . var_export($base . '/runner.log', true) . '))->run([PHP_BINARY,"-r",\'fwrite(STDERR,"child-start\\n"); usleep(5400000); fwrite(STDOUT,$argv[1]); exit(29);\',"空格 中文"],"quiet-child"); exit($r["code"]);');
    fwrite(STDOUT, "Testing quiet child: observing live output and five-second heartbeat...\n");
    $earlyOutput = false;
    $liveHeartbeat = false;
    $r = invoke([], '', $runnerEntry, static function (string $chunk, float $elapsed, bool $running) use (&$earlyOutput, &$liveHeartbeat): void {
        if (str_contains($chunk, 'child-start') && $running && $elapsed < 2) $earlyOutput = true;
        // The observer also includes PHP startup time; the real child state proves live delivery.
        if (str_contains($chunk, '[等待输出] quiet-child') && $running && $elapsed >= 4.9) $liveHeartbeat = true;
    });
    check($earlyOutput && $liveHeartbeat, 'output and heartbeat arrive before real child completes');
    check($r['code'] === 29 && str_contains($r['text'], '[等待输出] quiet-child') && str_contains($r['text'], '空格 中文') && str_contains($r['text'], 'child-start'), 'real child streams output, reports quiet progress and preserves arguments/exit');
    fwrite(STDOUT, "{$count} behavioral checks passed. Fixtures and raw logs: {$base}\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\nEvidence: {$log}\n");
    exit(1);
}
