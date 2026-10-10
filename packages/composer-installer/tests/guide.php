<?php
declare(strict_types=1);

// Real subprocess behavior; the existing injectable TTY flag makes menu input deterministic.
require dirname(__DIR__) . '/src/Installer.php';
use Supdger\WebmanAotInstaller\Installer;

$root = dirname(__DIR__);
$targetVersion = json_decode((string) file_get_contents($root . '/resources/releases.json'), true, flags: JSON_THROW_ON_ERROR)['version'];
$base = sys_get_temp_dir() . '/composer-guide-tests-' . bin2hex(random_bytes(6));
mkdir($base, 0700, true);
$passed = 0;
function check(bool $value, string $message): void {
    global $passed;
    if (!$value) { throw new RuntimeException($message); }
    fwrite(STDOUT, '[通过] ' . $message . PHP_EOL);
    $passed++;
}
/** @return array{int,string} */
function invoke(array $arguments, string $input = '', ?bool $tty = false, array $extraEnvironment = []): array {
    global $base;
    $process = proc_open([PHP_BINARY, $base . '/entry.php', $tty === null ? 'auto' : ($tty ? 'tty' : 'pipe'), ...$arguments],
        [['pipe', 'r'], ['pipe', 'w'], ['redirect', 1]], $pipes, $base,
        array_merge(getenv(), ['CI' => '', 'COMPOSER_NO_INTERACTION' => ''], $extraEnvironment));
    if (!is_resource($process)) { throw new RuntimeException('测试进程无法启动'); }
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    return [proc_close($process), (string) $output];
}
function removeFixture(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') { removeFixture($path . '/' . $name); }
        }
        rmdir($path);
    } else { unlink($path); }
}
$entry = '<?php require ' . var_export($root . '/src/Installer.php', true) . '; require '
    . var_export($root . '/src/Process.php', true) . '; require ' . var_export($root . '/src/Archive.php', true)
    . '; $tty=$argv[1]==="auto"?null:$argv[1]==="tty"; exit((new Supdger\\WebmanAotInstaller\\Installer(null,$tty))->run([$argv[0],...array_slice($argv,2)]));';
file_put_contents($base . '/entry.php', $entry);
try {
    if (function_exists('pcntl_alarm') && function_exists('pcntl_async_signals')) {
        $inputEntry = $base . '/input-errors.php';
        $inputScript = <<<'PHP'
<?php
require $argv[1];
$seen = [];
if ($argv[4] === 'custom') {
    set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
        $seen[] = $message;
        return true;
    });
}
pcntl_async_signals(true);
pcntl_signal(SIGALRM, static function (): void {
    trigger_error('UNRELATED_SIGNAL_WARNING', E_USER_WARNING);
});
pcntl_alarm(1);
$object = (new ReflectionClass($argv[2]))->newInstanceWithoutConstructor();
$method = new ReflectionMethod($object, $argv[3]);
$value = $argv[2] === 'WebmanAotBuilder\\Guided\\Flow' ? $method->invoke($object, '输入边界回归') : $method->invoke($object);
trigger_error('AFTER_READ_WARNING', E_USER_WARNING);
echo json_encode(['value' => $value, 'seen' => $seen]), PHP_EOL;
PHP;
        file_put_contents($inputEntry, $inputScript);
        foreach ([
            [$root . '/src/Console.php', 'Supdger\\WebmanAotInstaller\\Console', 'read'],
            [dirname($root, 2) . '/src/Guided/Flow.php', 'WebmanAotBuilder\\Guided\\Flow', 'read'],
        ] as [$source, $class, $method]) {
            foreach (['custom', 'default'] as $handler) {
                fwrite(STDOUT, '[步骤] ' . $class . ' 读取期间保留 ' . $handler . " 错误处理\n");
                $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', $inputEntry, $source, $class, $method, $handler],
                    [['pipe', 'r'], ['pipe', 'w'], ['redirect', 1]], $pipes);
                if (!is_resource($process)) { throw new RuntimeException('输入边界测试无法启动'); }
                usleep(1200000);
                fwrite($pipes[0], "0\n");
                fclose($pipes[0]);
                $output = (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $code = proc_close($process);
                $expectedValue = $class === 'WebmanAotBuilder\\Guided\\Flow' ? '"value":"0"' : '"value":"0\n"';
                check($code === 0 && str_contains($output, $expectedValue)
                    && str_contains($output, 'UNRELATED_SIGNAL_WARNING') && str_contains($output, 'AFTER_READ_WARNING')
                    && !str_contains($output, '终端输入已断开'),
                    $class . ' 不吞读取期间无关警告，且恢复 ' . $handler . ' 处理器');
            }
        }
    } else {
        fwrite(STDOUT, "[未运行] 输入期间信号警告回归需要 pcntl。\n");
    }
    if (PHP_OS_FAMILY === 'Darwin') {
        $parentEntry = $base . '/parent-input.php';
        file_put_contents($parentEntry, '<?php require ' . var_export($root . '/src/Console.php', true)
            . '; try { $value=Supdger\\WebmanAotInstaller\\Console::read(); echo json_encode(["value"=>$value,"blocked"=>stream_get_meta_data(STDIN)["blocked"]]); } catch(Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(7); }');
        foreach (['half-line', 'empty-eof', 'fragment-eof', 'parent-ended'] as $case) {
            fwrite(STDOUT, '[步骤] 恢复控制台管道读取：' . $case . PHP_EOL);
            $process = proc_open([PHP_BINARY, $parentEntry], [['pipe','r'], ['pipe','w'], ['redirect',1], ['pipe','r']], $pipes, null,
                array_merge(getenv(), ['WEBMAN_AOT_GUIDE_CONSOLE_DEPTH'=>'1','WEBMAN_AOT_GUIDE_PARENT_FD'=>'3']));
            if (!is_resource($process)) { throw new RuntimeException('父管道输入测试无法启动'); }
            if ($case === 'half-line') {
                usleep(1200000);
                check(proc_get_status($process)['running'], '开放无数据管道继续等待，不因探测周期结束');
                fwrite($pipes[0], 'half');
                usleep(1200000);
                check(proc_get_status($process)['running'], '部分行继续等待，不提前接受菜单选择');
                fwrite($pipes[0], "-line\n");
            } elseif ($case === 'fragment-eof') {
                fwrite($pipes[0], 'fragment');
            }
            if ($case === 'parent-ended') { fclose($pipes[3]); }
            else { fclose($pipes[0]); }
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            foreach ([0,3] as $descriptor) { if (is_resource($pipes[$descriptor])) { fclose($pipes[$descriptor]); } }
            $code = proc_close($process);
            if ($case === 'parent-ended') {
                check($code === 7 && str_contains($output, '启动引导的父入口已结束'), '父管道消失仍停止读取，无旁路');
            } else {
                $expected = match ($case) { 'half-line' => "half-line\n", 'fragment-eof' => 'fragment', default => false };
                $result = json_decode($output, true);
                check($code === 0 && is_array($result) && $result['value'] === $expected && $result['blocked'] === true,
                    $case . ' 保留完整行或正常EOF，恢复阻塞模式');
            }
        }
    }
    $state = $base . '/untouched';
    [$code, $output] = invoke(['--state-dir=' . $state]);
    check($code === 0 && str_contains($output, 'composer global exec -- webman-aot guide') && !file_exists($state),
        '非TTY无参立即给可执行下一步，无状态或下载');
    [$code, $output] = invoke(['--state-dir=' . $state], '', null);
    check($code === 0 && !file_exists($state), '真实stdio探测的无参管道不会尝试恢复控制台');
    [$code, $output] = invoke(['guide', '--state-dir=' . $state], '', null, ['WEBMAN_AOT_GUIDE_CONSOLE_DEPTH' => '1']);
    check($code === 0 && str_contains($output, '需要交互终端') && !file_exists($state), '恢复后的非TTY有界退出，循环guard阻止重启');
    [$code, $output] = invoke(['guide', '--state-dir=' . $state], '1', false);
    check($code === 0 && !file_exists($state), '非TTY显式guide不会以输入猜测安装');
    [$code, $output] = invoke(['start', '--state-dir=' . $state], '', true);
    check($code === 0 && str_contains($output, '流程已结束') && !file_exists($state), '初始EOF安全结束，无状态写入');
    [$code, $output] = invoke(['guide', '--state-dir=' . $state], "0\n", true);
    check($code === 0 && !file_exists($state), '初始0取消不准备或安装');
    [$code, $output] = invoke(['--state-dir=' . $state], "2\n0\n", true);
    check($code === 0 && str_contains($output, '完整安装包路径') && !file_exists($state), '本地导入0取消不写状态');
    file_put_contents($base . '/bad archive.zip', 'wrong archive');
    [$code, $output] = invoke(['guide', '--state-dir=' . $state], "2\n" . $base . "/bad archive.zip\n0\n", true);
    check($code === 0 && str_contains($output, '大小') && substr_count($output, '完整安装包路径') === 2 && !file_exists($state),
        '错误本地包显示校验失败并可重输或取消，无状态写入');
    [$code, $output] = invoke(['guide', '--state-dir=' . $state, '--non-interactive'], "1\n", true);
    check($code === 0 && !file_exists($state), '显式non-interactive优先，不使用终端输入准备资源');
    foreach (['COMPOSER_NO_INTERACTION', 'CI'] as $name) {
        [$code, $output] = invoke(['guide', '--state-dir=' . $state], "1\n", true, [$name => '1']);
        check($code === 0 && !file_exists($state), $name . ' 明确无人标志下不恢复控制台或安装');
    }
    [$code, $output] = invoke(['--version']);
    check($code === 0 && trim($output) === 'Composer 入口 ' . Installer::VERSION . '；目标 Webman AOT Builder ' . $targetVersion,
        '非TTY版本输出保持机器调用兼容');
    [$code, $output] = invoke(['--version'], '', true);
    check($code === 0 && str_contains($output, '下一步：') && !file_exists($state), 'TTY版本查询显示菜单下一步，不准备资源');
    [$code, $output] = invoke(['--help']);
    check($code === 0 && str_contains($output, 'composer global exec -- webman-aot guide') && str_contains($output, '导入完整包'),
        'help明确单入口及后续项目菜单');
    if (PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64') {
        $state = $base . '/中文 ready state';
        mkdir($state . '/runtime/current/runtime/bin', 0700, true);
        mkdir($state . '/runtime/current/app/bin', 0700, true);
        mkdir($state . '/runtime/current/app/tools', 0700, true);
        $record = $base . '/guide.json';
        file_put_contents($state . '/runtime/current/runtime/bin/php', "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . " \"\$@\"\n");
        chmod($state . '/runtime/current/runtime/bin/php', 0700);
        file_put_contents($state . '/runtime/current/app/bin/webman-aot-builder.php', '<?php exit(99);');
        file_put_contents($state . '/runtime/current/app/tools/guided.php',
            '<?php file_put_contents(' . var_export($record, true) . ',json_encode([getcwd(),array_slice($argv,1),getenv("WEBMAN_AOT_BUILDER_HOME")])); exit(23);');
        file_put_contents($state . '/ready.json', json_encode(['version' => $targetVersion, 'host' => 'macos-arm64']));
        file_put_contents($state . '/owner.json', json_encode(['schema' => 1, 'package' => 'supdger/webman-aot-builder']));
        [$code, $output] = invoke(['guide', '--state-dir=' . $state], "invalid\n1\n", true);
        $actual = json_decode(file_get_contents($record), true);
        check($code === 23 && str_contains($output, '选择无效'), '无效选择可修正，项目菜单退出码透传');
        check($actual[0] === $base && $actual[1] === ['--mode=project', '--home=' . $state . '/runtime', '--bin-dir=' . $state . '/bin', '--no-path']
            && $actual[2] === $state . '/runtime', '就绪状态直调原Flow，cwd及私有home/bin参数正确');
        check(!file_exists($state . '/cache') && !file_exists($state . '/setup.lock'), '就绪启动不重新下载或安装');
        unlink($state . '/runtime/current/app/tools/guided.php');
        [$code, $output] = invoke(['start', '--state-dir=' . $state], "1\n", true);
        check($code === 70 && str_contains($output, '缺少所需入口'), '缺原菜单脚本明确失败，不悄悄调用旧bin');
    }
    fwrite(STDOUT, "完成：{$passed} 项首次使用行为检查通过。\n");
} finally {
    removeFixture($base);
    fwrite(STDOUT, '临时夹具已清理：' . (file_exists($base) ? '否' : '是') . PHP_EOL);
}
