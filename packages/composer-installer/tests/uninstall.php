<?php
declare(strict_types=1);

// Every operation is isolated under a new temporary HOME; no real installation or PATH is changed.
$repository = dirname(__DIR__, 3);
$temporary = rtrim((string) realpath(sys_get_temp_dir()), '/') . '/aot-uninstall-tests-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);
$checks = 0;
function verify(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
    fwrite(STDOUT, "[通过] {$message}\n");
}
function directory(string $path): void { if (!is_dir($path)) { mkdir($path, 0700, true); } }
function native(string $path, string $version, bool $legacy = false): void {
    global $repository;
    directory($path . '/app/src'); directory($path . '/app/bin'); directory($path . '/runtime');
    $namespace = $legacy ? 'WebmanAot' : 'WebmanAotBuilder';
    file_put_contents($path . '/app/src/Version.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nfinal class Version\n{\n    public const VALUE = '{$version}';\n}\n");
    $entry = $legacy ? 'webman-aot.php' : 'webman-aot-builder.php';
    $content = $legacy ? shell_exec('git -C ' . escapeshellarg($repository) . ' show v0.1.2:bin/webman-aot.php')
        : file_get_contents($repository . '/bin/webman-aot-builder.php');
    file_put_contents($path . '/app/bin/' . $entry, $content);
    file_put_contents($path . '/manifest.json', json_encode(['schema' => 'webman-aot-builder-cli-generation-v1', 'version' => $version]));
}
/** @return array{int,string} */
function execute(array $arguments, string $input = '', bool $interactive = true, array $extraEnvironment = [], ?string $cwd = null): array {
    global $temporary, $repository;
    $environment = array_merge(getenv(), ['HOME' => $temporary . '/用户 空格', 'USERPROFILE' => $temporary . '/用户 空格',
        'LOCALAPPDATA' => $temporary . '/local', 'APPDATA' => $temporary . '/appdata', 'COMPOSER_HOME' => $temporary . '/global',
        'XDG_CONFIG_HOME' => $temporary . '/config', 'PATH' => $temporary . '/fake-bin:/usr/bin:/bin',
        'WEBMAN_AOT_HOME' => '', 'WEBMAN_AOT_BUILDER_HOME' => ''], $extraEnvironment);
    $engine = $repository . '/packages/composer-installer/src/Uninstaller.php';
    $command = $interactive ? [PHP_BINARY, '-r', 'require $argv[1]; exit((new Supdger\\WebmanAotInstaller\\Uninstaller(true))->run(array_slice($argv, 2)));', $engine]
        : [PHP_BINARY, $repository . '/packages/composer-installer/bin/webman-aot', 'uninstall'];
    $process = proc_open(array_merge($command, $arguments), [['pipe', 'r'], ['pipe', 'w'], ['redirect', 1]], $pipes, $cwd ?? $repository, $environment);
    if (!is_resource($process)) { throw new RuntimeException('无法启动测试'); }
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    return [proc_close($process), (string) $output];
}
function clean(string $path): void {
    if (is_dir($path) && !is_link($path)) { foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { clean($path . '/' . $name); } } rmdir($path); }
    elseif (file_exists($path) || is_link($path)) { unlink($path); }
}
try {
    $root = $temporary . '/独立 安装';
    $bin = $temporary . '/命令 空格';
    directory($bin);
    native($root . '/current', '0.3.2');
    native($root . '/versions/00000000000000000001-0.3.1', '0.3.1');
    native($root . '/versions/00000000000000000002-0.3.2', '0.3.2');
    native($root . '/.install-backups/previous/current', '0.2.3');
    directory($root . '/toolchains'); file_put_contents($root . '/toolchains/sentinel', 'keep');
    directory($temporary . '/project/dist-aot'); file_put_contents($temporary . '/project/dist-aot/sentinel', 'keep');
    file_put_contents($bin . '/webman-aot', file_get_contents($repository . '/bin/webman-aot'));
    directory($root . '/.previous-launcher');
    $legacy = shell_exec('git -C ' . escapeshellarg($repository) . ' show v0.1.2:bin/webman-aot');
    file_put_contents($root . '/.previous-launcher/webman-aot', $legacy);
    $options = ['--home=' . $root, '--home=' . $root . '/', '--bin-dir=' . $bin, '--bin-dir=' . $bin . '/'];
    [$code, $output] = execute(array_merge($options, ['--list']));
    verify($code === 0 && substr_count($output, '独立安装（当前）') === 1 && str_contains($output, '0.3.1') && str_contains($output, '历史备份'), '只读列多版本、备份、中文空格并去重');
    verify(is_dir($root . '/current') && !file_exists($temporary . '/用户 空格'), 'list不创建HOME或状态，不删除安装');
    verify(str_contains($output, '用途：') && str_contains($output, '卸载影响：') && str_contains($output, '此版本无法再运行'), '列表解释用途和卸载影响，活动入口撤销可见');
    [$code, $output] = execute($options, "y\ny\n", false);
    verify($code === 0 && is_dir($root . '/current') && str_contains($output, '非交互'), '产品Composer入口非TTY即使收到y仍默认保留');
    [$code, $output] = execute($options, "\nn\nq\n");
    verify($code === 0 && is_dir($root . '/current') && is_dir($root . '/versions/00000000000000000001-0.3.1'), '回车n与q保留当前和其他版本');
    [$code, $output] = execute($options, '');
    verify($code === 0 && is_dir($root . '/current') && str_contains($output, '未确认'), 'EOF不删除');
    [$code, $output] = execute($options, "n\ny\nq\n");
    verify($code === 0 && !file_exists($root . '/versions/00000000000000000002-0.3.2') && is_dir($root . '/versions/00000000000000000001-0.3.1'), '逐项仅删活动generation，旧generation保留');
    // Explicit root alone cannot prove that a standard launcher points to it.
    verify(is_file($bin . '/webman-aot'), '自定义root不猜测publiclauncher目标');
    native($root . '/versions/00000000000000000002-0.3.2', '0.3.2');
    [$code, $output] = execute($options, "n\ny\nq\n", true, ['WEBMAN_AOT_BUILDER_HOME' => $root]);
    verify($code === 0 && !file_exists($bin . '/webman-aot') && str_contains($output, '已撤销命令'), '明确绑定时删活动版本同时撤销入口，旧版本不自动公开启用');
    verify(is_file($root . '/.previous-launcher/webman-aot'), '备份旧命令未选择不恢复不删除');
    verify(is_file($root . '/toolchains/sentinel') && is_file($temporary . '/project/dist-aot/sentinel'), '共享工具链及项目dist成果保留');
    $unknown = "#!/bin/sh\n# WEBMAN_AOT_BUILDER_PUBLIC_LAUNCHER\n# WEBMAN_AOT_BUILDER_HOME /current/runtime /current/app/bin/webman-aot-builder.php\ntouch '" . $temporary . "/must-not-execute'\n";
    file_put_contents($bin . '/webman-aot', $unknown);
    [$code, $output] = execute($options, "n\nn\nn\nn\ny\n");
    verify(is_file($bin . '/webman-aot') && !file_exists($temporary . '/must-not-execute') && str_contains($output, '无足够所有权'), '伪造关键词launcher保留且从不执行');
    $linkRoot = $temporary . '/linked-root'; symlink($root, $linkRoot);
    [$code, $output] = execute(['--home=' . $linkRoot, '--list']);
    verify($code === 0 && str_contains($output, '归属') && is_dir($root . '/current'), '符号链接managedroot拒绝卸载');
    symlink($temporary . '/project', $root . '/current/app/external');
    [$code, $output] = execute(array_merge($options, ['--list']));
    verify(str_contains($output, '无法安全确认') && is_file($temporary . '/project/dist-aot/sentinel'), '版本子树链接拒绝删除');
    unlink($root . '/current/app/external');
    file_put_contents($root . '/current/unowned-file', 'keep');
    [$code, $output] = execute(array_merge($options, ['--list']));
    verify(str_contains($output, '无法安全确认') && is_file($root . '/current/unowned-file'), '版本目录未知附加文件保持');
    if (PHP_OS_FAMILY !== 'Windows') {
        $literal = $temporary . '/collision/aot\\history';
        $nested = $temporary . '/collision/aot/history';
        native($literal . '/current', '0.3.1'); native($nested . '/current', '0.3.2');
        [$code, $output] = execute(['--home=' . $literal, '--list']);
        verify($code === 0 && str_contains($output, $literal . '/current') && !str_contains($output, $nested . '/current'), 'Mac真实反斜杠路径不转成另一目录，也不漏掉目标');
        [$code, $output] = execute(['--home=' . $literal], "y\n");
        verify($code === 0 && !is_dir($literal . '/current') && is_dir($nested . '/current'), '路径collision仅删除明确选择的literal安装，另一真实安装保留');
        foreach (['trailing-space ' => 'trailing-space', 'quote"' => 'quote'] as $name => $otherName) {
            $exact = $temporary . '/collision/' . $name;
            $otherPath = $temporary . '/collision/' . $otherName;
            native($exact . '/current', '0.3.1'); native($otherPath . '/current', '0.3.2');
            [$code, $output] = execute(['--home=' . $exact], "y\n");
            verify($code === 0 && !is_dir($exact . '/current') && is_dir($otherPath . '/current'), 'Mac路径末尾空格或引号保留真实字节，只删目标：' . $name);
        }

    }
    $state = $temporary . '/composer 状态'; directory($state . '/runtime/current');
    file_put_contents($state . '/owner.json', json_encode(['schema' => 1, 'package' => 'supdger/webman-aot-builder']));
    file_put_contents($state . '/ready.json', json_encode(['version' => '0.3.2']));
    file_put_contents($state . '/runtime/current/payload', 'owned'); file_put_contents($state . '/user-file', 'keep');
    $heldLock = fopen($state . '/setup.lock', 'c+');
    flock($heldLock, LOCK_EX);
    [$code, $output] = execute(['--state-dir=' . $state], "y\n");
    verify($code === 70 && is_file($state . '/owner.json') && is_file($state . '/runtime/current/payload'), '持锁时拒绝卸载，owner与payload保持');
    $lockInode = fileinode($state . '/setup.lock');
    flock($heldLock, LOCK_UN); fclose($heldLock);
    [$code, $output] = execute(['--state-dir=' . $state], "y\n");
    verify($code === 0 && !is_dir($state . '/runtime') && is_file($state . '/user-file'), 'Composerowner白名单卸载，额外文件保持');
    verify(is_file($state . '/setup.lock') && fileinode($state . '/setup.lock') === $lockInode, '卸载保留原锁inode与状态根，防止并发setup创建两把锁');
    $badState = $temporary . '/badstate'; directory($badState . '/runtime'); file_put_contents($badState . '/runtime/sentinel', 'keep');
    [$code, $output] = execute(['--state-dir=' . $badState], "y\n");
    verify(is_file($badState . '/runtime/sentinel') && str_contains($output, '保留'), '无ownerComposer状态保留');
    $oldState = $temporary . '/legacy composer state'; directory($oldState . '/runtime/current');
    file_put_contents($oldState . '/owner.json', json_encode(['schema' => 1, 'package' => 'saiadmin/webman-aot-builder']));
    file_put_contents($oldState . '/runtime/current/sentinel', 'old');
    [$code, $output] = execute(['--state-dir=' . $oldState, '--list']);
    verify($code === 0 && str_contains($output, 'Composer 私有运行时/缓存（saiadmin/webman-aot-builder）') && is_file($oldState . '/runtime/current/sentinel'), '旧schema1状态明示精确归属，只读不迁移');
    [$code, $output] = execute(['--state-dir=' . $oldState], "n\n");
    verify($code === 0 && is_file($oldState . '/owner.json') && is_file($oldState . '/runtime/current/sentinel'), '旧包状态默认保留不自动adopt');
    [$code, $output] = execute(['--state-dir=' . $oldState], "y\n");
    verify($code === 0 && !is_dir($oldState . '/runtime') && !is_file($oldState . '/owner.json') && is_file($oldState . '/setup.lock'), '新入口明确确认仅清理旧owner白名单并保留锁');
    if (PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64') {
        $retainedInode = fileinode($oldState . '/setup.lock');
        $invalidArchive = $temporary . '/invalid-runtime.tar.gz'; file_put_contents($invalidArchive, 'invalid');
        $setup = proc_open([PHP_BINARY, $repository . '/packages/composer-installer/bin/webman-aot', 'setup', '--state-dir=' . $oldState, '--archive=' . $invalidArchive, '--non-interactive'], [STDIN, ['pipe', 'w'], ['redirect', 1]], $setupPipes, $repository);
        $setupOutput = stream_get_contents($setupPipes[1]); fclose($setupPipes[1]); $setupCode = proc_close($setup);
        verify($setupCode === 70 && json_decode(file_get_contents($oldState . '/owner.json'), true)['package'] === 'supdger/webman-aot-builder' && fileinode($oldState . '/setup.lock') === $retainedInode && !str_contains($setupOutput, '状态目录不属于'), '清旧owner后新setup可claim锁保留根，失败仅因为fixture资源损坏而非所有权');
    }
    // Separate owned states prove each confirmation applies to one object, including after EOF.
    $choiceA = $temporary . '/choice A'; $choiceB = $temporary . '/choice B';
    foreach ([$choiceA, $choiceB] as $choice) {
        directory($choice . '/runtime'); file_put_contents($choice . '/runtime/sentinel', 'owned');
        file_put_contents($choice . '/owner.json', json_encode(['schema' => 1, 'package' => 'supdger/webman-aot-builder']));
    }
    $choiceOptions = ['--state-dir=' . $choiceA, '--state-dir=' . $choiceB];
    directory($temporary . '/unrelated cwd');
    [$code, $output] = execute(array_merge($choiceOptions, ['--list']), '', false, [], $temporary . '/unrelated cwd');
    verify($code === 0 && str_contains($output, $choiceA) && str_contains($output, $choiceB)
        && str_contains($output, '内部日志及缓存') && !str_contains($output, '是否卸载')
        && is_file($choiceA . '/runtime/sentinel') && is_file($choiceB . '/runtime/sentinel'), '任意cwd产品list只读列项与准确私有运行时影响，不进入确认或准备');
    [$code, $output] = execute($choiceOptions, "y\n", true, [], $temporary . '/unrelated cwd');
    verify($code === 0 && !is_dir($choiceA . '/runtime') && is_file($choiceB . '/runtime/sentinel')
        && str_contains($output, '是否卸载') && str_contains($output, '卸载影响：移除本项私有 PHP')
        && str_contains($output, '未确认的项目已保留'), '确认提示再次解释删除影响，一次y仅删一项，随后EOF保留第二项');
    $unknownState = $temporary . '/other package state'; directory($unknownState . '/runtime');
    file_put_contents($unknownState . '/owner.json', json_encode(['schema' => 1, 'package' => 'other/webman-aot-builder']));
    file_put_contents($unknownState . '/runtime/sentinel', 'keep');
    [$code, $output] = execute(['--state-dir=' . $unknownState], "y\n");
    verify($code === 0 && is_file($unknownState . '/runtime/sentinel'), '相似包名owner不在精确名单，保留');
    $global = $temporary . '/global'; directory($global); directory($temporary . '/fake-bin');
    file_put_contents($global . '/composer.json', json_encode(['require' => ['supdger/webman-aot-builder' => '^0.3.3', 'other/tool' => '*']]));
    file_put_contents($global . '/composer.lock', json_encode(['packages' => [['name' => 'supdger/webman-aot-builder', 'version' => '0.3.3']]]));
    $fakeComposer = $temporary . '/fake-bin/composer';
    file_put_contents($fakeComposer, "#!/bin/sh\nprintf '%s\\n' \"\$COMPOSER_HOME\" \"\$@\" > " . escapeshellarg($temporary . '/composer-args') . "\nexit 7\n"); chmod($fakeComposer, 0700);
    [$code, $output] = execute([], "y\n");
    verify($code === 70 && str_contains($output, '退出码 7') && isset(json_decode(file_get_contents($global . '/composer.json'), true)['require']['other/tool']), 'Composer失败非零且其他全局包记录保持');
    $args = file_get_contents($temporary . '/composer-args');
    verify(str_starts_with($args, $global . "\n") && str_contains($args, "global\nremove\n--no-interaction\nsupdger/webman-aot-builder") && str_contains($args, '--no-scripts') && str_contains($args, '--no-plugins'), 'Composer指向选定globalhome且精确package，禁用hook与plugin');
    verify(str_contains($output, '[剩余]') && str_contains($output, $global), '完成重新检查并显示残留路径');
    file_put_contents($global . '/composer.json', json_encode(['require' => ['supdger/webman-aot-builder' => '^0.3.5', 'saiadmin/webman-aot-builder' => '^0.3.4', 'other/webman-aot-builder' => '*']]));
    file_put_contents($global . '/composer.lock', json_encode(['packages' => [['name' => 'supdger/webman-aot-builder', 'version' => '0.3.5'], ['name' => 'saiadmin/webman-aot-builder', 'version' => '0.3.4']]]));
    [$code, $output] = execute(['--list']);
    verify($code === 0 && substr_count($output, 'Composer 全局包（') === 2 && str_contains($output, 'Composer 全局包（saiadmin/webman-aot-builder）') && str_contains($output, 'Composer 全局包（supdger/webman-aot-builder）'), '同globalhome两个身份分别列项，路径去重不吞包');
    $removeFixture = $temporary . '/remove-exact.php';
    file_put_contents($removeFixture, '<?php $p=getenv("COMPOSER_HOME")."/composer.json"; $x=json_decode(file_get_contents($p),true); $name=end($argv); file_put_contents(' . var_export($temporary . '/removed-package', true) . ', $name); unset($x["require"][$name]); file_put_contents($p,json_encode($x));');
    file_put_contents($fakeComposer, "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($removeFixture) . ' "$@"' . "\n"); chmod($fakeComposer, 0700);
    [$code, $output] = execute([], "n\ny\n");
    $remainingPackages = json_decode(file_get_contents($global . '/composer.json'), true)['require'];
    verify($code === 0 && file_get_contents($temporary . '/removed-package') === 'saiadmin/webman-aot-builder' && !isset($remainingPackages['saiadmin/webman-aot-builder']) && isset($remainingPackages['supdger/webman-aot-builder'], $remainingPackages['other/webman-aot-builder']), '选择旧包仅精确remove旧包，新包和相似其他包保留');
    [$code, $output] = execute(['--yes']); verify($code !== 0 && str_contains($output, '免确认'), '无免确认全删入口');
    $legacyRoot = $temporary . '/用户 空格/Library/Application Support/webman-aot';
    native($legacyRoot . '/current', '0.1.2', true);
    native($legacyRoot . '/.install-backups/current-previous', '0.1.2', true);
    [$code, $output] = execute(['--list']);
    verify($code === 0 && str_contains($output, '旧版历史备份') && substr_count($output, '0.1.2') >= 2, 'v0.1.2直接app/runtime备份真实布局被识别');
    [$code, $output] = execute([], "n\ny\nq\n");
    verify($code === 0 && is_dir($legacyRoot . '/current') && !is_dir($legacyRoot . '/.install-backups/current-previous'), '旧版真实备份可逐项删且当前0.1.2保留');
    [$code, $output] = execute(['--home=' . $repository, '--list']); verify($code === 0, 'project工作区根仅安全列出不扫描成果');
    // A missing state directory on the real public entry never triggers preparation or download.
    [$code, $output] = execute(['--state-dir=' . $temporary . '/missing', '--list'], '', false);
    verify($code === 0 && !file_exists($temporary . '/missing'), '公开uninstall --state-dir不存在也不prepare不创建状态');
    fwrite(STDOUT, "[结果] {$checks} checks passed; all writes isolated under {$temporary}\n");
} finally { clean($temporary); }
