<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Guided;

final class Flow
{
    private const ISSUES = 'https://github.com/supdger/webman-aot-builder/issues';
    /** @var array<string,string|bool> */
    private array $options = [];
    private ProcessRunner $runner;
    private string $workspace;
    private string $log;
    private string $home;
    private string $bin;
    private string $platform;
    private bool $installationConfirmed = false;

    public function __construct(private readonly string $root)
    {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $this->parse($arguments);
            if (!stream_isatty(STDIN)) {
                stream_set_blocking(STDIN, false);
            }
            if (isset($this->options['help'])) {
                fwrite(STDOUT, "一次启动构包、安装和项目构建\n用法：guided.php --mode=source|install|project [--flavor=small|full] [--install] [--project=目录]\n隔离安装：--home=目录 --bin-dir=目录 --no-path\n安装包模式：--package-root=目录；--install 表示明确同意安装。\n");
                return 0;
            }
            $this->platform = PHP_OS_FAMILY === 'Windows' ? 'windows-x86_64' : 'macos-arm64';
            if (PHP_OS_FAMILY !== 'Windows' && (PHP_OS_FAMILY !== 'Darwin' || php_uname('m') !== 'arm64')) {
                throw new \RuntimeException('此入口支持 Windows x64 或 macOS ARM64。');
            }
            $base = PHP_OS_FAMILY === 'Windows' ? (getenv('LOCALAPPDATA') ?: '') : (getenv('HOME') ?: '');
            if ($base === '') {
                throw new \RuntimeException('无法确定当前用户目录，请指定 --home 和 --bin-dir。');
            }
            $this->home = $this->absolute((string) ($this->options['home'] ?? ($base . (PHP_OS_FAMILY === 'Windows' ? '/webman-aot-builder' : '/Library/Application Support/webman-aot-builder'))));
            $this->bin = $this->absolute((string) ($this->options['bin-dir'] ?? (PHP_OS_FAMILY === 'Windows' ? $this->home . '/bin' : $base . '/.local/bin')));
            $this->workspace = sys_get_temp_dir() . '/webman-aot-guided-' . bin2hex(random_bytes(8));
            if (!mkdir($this->workspace, 0700, true)) {
                throw new \RuntimeException('无法创建本次临时目录。');
            }
            $this->log = $this->workspace . '/guided.log';
            $this->runner = new ProcessRunner($this->log);
            fwrite(STDOUT, "本次日志（仅保存在本机）：{$this->log}\n");
            $mode = (string) ($this->options['mode'] ?? 'source');
            if ($mode === 'project') {
                return $this->projects();
            }
            $package = $mode === 'source' ? $this->source() : $this->absolute((string) ($this->options['package-root'] ?? ''));
            if ($package === null) {
                return 0;
            }
            if (!$this->install($package)) {
                return 0;
            }
            return $this->projects();
        } catch (ProcessFailure $failure) {
            $this->failure($failure->getMessage());
            return $failure->getCode() > 0 ? $failure->getCode() : 1;
        } catch (\Throwable $failure) {
            $this->failure($failure->getMessage());
            return 1;
        }
    }

    /** @param list<string> $arguments */
    private function parse(array $arguments): void
    {
        $values = ['mode', 'flavor', 'project', 'package-root', 'home', 'bin-dir'];
        foreach ($arguments as $argument) {
            if (in_array($argument, ['--install', '--no-path', '--help'], true)) {
                $key = substr($argument, 2);
                $value = true;
            } else {
                $parts = explode('=', $argument, 2);
                $key = substr($parts[0], 2);
                if (!str_starts_with($argument, '--') || count($parts) !== 2 || !in_array($key, $values, true) || $parts[1] === '') {
                    throw new \InvalidArgumentException("无效选项：{$argument}；请使用 --help 查看用法。");
                }
                $value = $parts[1];
            }
            if (isset($this->options[$key])) {
                throw new \InvalidArgumentException("选项重复：--{$key}");
            }
            $this->options[$key] = $value;
        }
        if (!in_array($this->options['mode'] ?? 'source', ['source', 'install', 'project'], true)) {
            throw new \InvalidArgumentException('模式必须是 source、install 或 project。');
        }
        if (isset($this->options['flavor']) && !in_array($this->options['flavor'], ['small', 'full'], true)) {
            throw new \InvalidArgumentException('包类型必须是 small（轻量）或 full（完整）。');
        }
    }

    private function source(): ?string
    {
        fwrite(STDOUT, "轻量包 small：下载较少，首次构建时下载组件。\n完整包 full：包含锁定工具链，可离线安装。\n");
        $flavor = $this->options['flavor'] ?? null;
        if ($flavor === null) {
            $choice = $this->choose('选择包类型：1 轻量包；2 完整包；0 结束', ['1', '2', '0']);
            if ($choice === null || $choice === '0') {
                fwrite(STDOUT, "已取消构包。\n");
                return null;
            }
            $flavor = $choice === '1' ? 'small' : 'full';
        }
        $resultPath = $this->workspace . '/source-result.json';
        $command = PHP_OS_FAMILY === 'Windows'
            ? ['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $this->root . '/tools/build-windows-installer.ps1', '-Flavor', $flavor, '-Result', $resultPath]
            : ['/bin/sh', $this->root . '/tools/build-macos-installer.sh', '--flavor=' . $flavor, '--result=' . $resultPath];
        $this->execute($command, '准备锁定材料、构包并隔离自检', $this->root);
        $result = $this->sourceResult($resultPath, $flavor);
        fwrite(STDOUT, sprintf("安装包已就绪：%s\n类型：%s；大小：%s 字节；源码版本：%s\n校验：包清单和隔离安装版本均通过。\n", $result['archive'], $flavor, $result['size'], $result['revision']));
        // Installation is a separate user choice; even extraction waits for that choice.
        if (!$this->confirmInstall()) {
            fwrite(STDOUT, "已结束，安装包保留在上述位置；未执行安装。\n");
            return null;
        }
        $this->options['install'] = true;
        $extract = $this->workspace . '/package';
        mkdir($extract, 0700);
        $this->execute(['tar', '-xf', $result['archive'], '-C', $extract], '解压本次安装包');
        $candidates = [$extract, ...((array) glob($extract . '/*', GLOB_ONLYDIR))];
        foreach ($candidates as $candidate) {
            if (is_file($candidate . '/payload-manifest.sha256')) {
                return $candidate;
            }
        }
        throw new \RuntimeException('解压后的安装包缺少清单，请重新构包。');
    }

    /** @return array<string,mixed> */
    private function sourceResult(string $path, string $flavor): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('构包后端未提供成功结果；不会安装未知产物。');
        }
        $result = $this->json((string) file_get_contents($path), '构包成功结果');
        if (($result['schema'] ?? null) !== 'webman-aot-builder-source-build-result-v1'
            || ($result['platform'] ?? null) !== $this->platform || ($result['flavor'] ?? null) !== $flavor
            || !is_string($result['revision'] ?? null) || $result['revision'] === ''
            || !is_string($result['archive'] ?? null) || !$this->isAbsolute($result['archive'])
            || !is_int($result['size'] ?? null) || $result['size'] <= 0
            || !is_string($result['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $result['sha256']) !== 1
            || !is_array($result['verified'] ?? null)
            || !in_array('payload-manifest', $result['verified'], true)
            || !in_array('isolated-install-version', $result['verified'], true)
        ) {
            throw new \RuntimeException('构包成功结果字段无效；请重新构包，不会继续安装。');
        }
        if (!is_file($result['archive']) || is_link($result['archive'])
            || filesize($result['archive']) !== $result['size']
            || !hash_equals($result['sha256'], (string) hash_file('sha256', $result['archive']))) {
            throw new \RuntimeException('安装包摘要或大小不匹配；文件可能损坏，请重新构包。');
        }
        return $result;
    }

    private function confirmInstall(): bool
    {
        if ($this->installationConfirmed) {
            return true;
        }
        fwrite(STDOUT, "安装目标：{$this->home}\n启动命令位置：{$this->bin}\n");
        fwrite(STDOUT, isset($this->options['no-path']) ? "PATH：不修改用户 PATH。\n" : "PATH：安装器将把启动命令目录加入用户 PATH。\n");
        if (isset($this->options['install'])) {
            fwrite(STDOUT, "已通过 --install 明确选择安装。\n");
            return $this->installationConfirmed = true;
        }
        return $this->installationConfirmed = ($this->choose('是否安装？1 安装；0 取消', ['1', '0']) === '1');
    }

    private function install(string $package): bool
    {
        if (!is_dir($package) || !is_file($package . '/payload-manifest.sha256')) {
            throw new \RuntimeException('安装包目录无效或缺少包清单，请选择解压后的完整目录。');
        }
        if (!is_file($package . '/package.json')) {
            throw new \RuntimeException('安装包缺少身份文件 package.json，请重新取得完整包。');
        }
        $identity = $this->json((string) file_get_contents($package . '/package.json'), '安装包身份');
        if (($identity['schema'] ?? null) !== 'webman-aot-builder-installer-package-v1'
            || ($identity['platform'] ?? null) !== $this->platform
            || !is_string($identity['version'] ?? null) || $identity['version'] === ''
            || !in_array($identity['flavor'] ?? null, ['small', 'complete'], true)) {
            throw new \RuntimeException('安装包身份无效或不匹配当前平台，请选择当前平台的完整安装包。');
        }
        fwrite(STDOUT, '本包版本：' . $identity['version'] . '；类型：' . ($identity['flavor'] === 'complete' ? '完整包' : '轻量包') . "\n");
        if (!$this->confirmInstall()) {
            fwrite(STDOUT, "已取消安装；未写入安装目录或修改 PATH。\n");
            return false;
        }
        $command = PHP_OS_FAMILY === 'Windows'
            ? ['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $package . '/install.ps1', '-InstallRoot', $this->home, '-BinDir', $this->bin]
            : ['/bin/sh', $package . '/install.sh', '--home', $this->home, '--bin-dir', $this->bin];
        if (isset($this->options['no-path'])) {
            $command[] = PHP_OS_FAMILY === 'Windows' ? '-NoPath' : '--no-path';
        }
        $this->execute($command, '校验包并安装', $package);
        $version = $this->execute($this->launcher(['version']), '验证本次新安装版本', null, $this->installedEnvironment());
        if (trim($version['stdout']) !== 'webman-aot ' . $identity['version']) {
            throw new \RuntimeException('新安装版本与本包版本不一致，请查看安装日志；不会继续构建项目。');
        }
        fwrite(STDOUT, "安装成功，当前入口可以直接构建项目。\n");
        if (is_file($package . '/payload/minimal-toolchain/component.zip')) {
            fwrite(STDOUT, "完整包已按安装器流程准备离线工具链。\n");
        } else {
            fwrite(STDOUT, "轻量包首次构建会下载锁定组件，请保持网络可用。\n");
        }
        return true;
    }

    private function projects(): int
    {
        $project = $this->options['project'] ?? null;
        $lastCode = 0;
        $fresh = false;
        while (true) {
            if ($project === null) {
                if ($this->choose('下一步：1 构建项目；0 结束', ['1', '0']) !== '1') {
                    fwrite(STDOUT, "流程已结束。\n");
                    return $lastCode;
                }
                $project = $this->read('输入 Webman/SaiAdmin 项目目录（直接回车取消）');
                if ($project === null || $project === '') {
                    return $lastCode;
                }
            }
            try {
                $project = $this->absolute($project);
                if (!is_dir($project)) {
                    throw new \RuntimeException('项目目录不存在：' . $project);
                }
                $environment = $this->installedEnvironment();
                $this->execute($this->launcher($fresh ? ['build', '--fresh'] : ['build']), $fresh ? '全量重建项目' : '构建项目（自动恢复已完成单元）', $project, $environment);
                $verification = $this->runner->run($this->launcher(['verify', '--json']), '校验本次项目产物', $project, $environment, true);
                if ($verification['code'] !== 0) {
                    throw new ProcessFailure('校验项目产物失败；退出码 ' . $verification['code'] . '。请查看上面的原始错误。', $verification['code']);
                }
                $report = $this->json($verification['stdout'], '项目校验结果');
                if (($report['schema'] ?? null) !== 'webman-aot-builder-verify-report-v1'
                    || !in_array($report['scope'] ?? null, ['target-static-and-integrity', 'build-host-structure-and-integrity'], true)
                    || ($report['staticStructure'] ?? null) !== 'pass' || !is_string($report['path'] ?? null)
                    || !is_dir($project . '/dist-aot') || realpath($report['path']) === false
                    || realpath($report['path']) !== realpath($project . '/dist-aot')) {
                    throw new \RuntimeException('项目校验结果无效，不能确认本次产物。');
                }
                fwrite(STDOUT, '实际校验范围：' . ($report['scope'] === 'target-static-and-integrity' ? '目标静态链接和完整性' : '本机构建产物结构和完整性') . "\n");
                fwrite(STDOUT, "项目构建与校验成功。\n产物位置：{$project}/dist-aot\n上述结果是本机结构/完整性及工具报告的目标静态校验范围，不代表 Linux 部署或业务运行验收。\n");
                return 0;
            } catch (\Throwable $failure) {
                $lastCode = $failure instanceof ProcessFailure && $failure->getCode() > 0 ? $failure->getCode() : 1;
                $this->failure($failure->getMessage());
                $choice = $this->choose('项目未完成：1 继续编译（复用已完成单元）；2 重选目录；3 全量重建此目录；0 结束', ['1', '2', '3', '0']);
                if ($choice === '1' || $choice === '3') {
                    $fresh = $choice === '3';
                    continue;
                }
                // EOF and explicit end both stop immediately; only 2 opens another prompt.
                if ($choice !== '2') {
                    return $lastCode;
                }
                $fresh = false;
                $project = $this->read('输入新的项目目录（直接回车取消）');
                if ($project === null || $project === '') {
                    return $lastCode;
                }
            }
        }
    }

    /** @param list<string> $allowed */
    private function choose(string $prompt, array $allowed): ?string
    {
        while (true) {
            $value = $this->read($prompt);
            if ($value === null || $value === '') {
                return null;
            }
            if (in_array($value, $allowed, true)) {
                return $value;
            }
            fwrite(STDOUT, "选择无效，请输入 " . implode('、', $allowed) . "。\n");
        }
    }

    private function read(string $prompt): ?string
    {
        fwrite(STDOUT, $prompt . "：\n");
        $failed = false;
        $previousHandler = null;
        $previousHandler = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$failed, &$previousHandler): bool {
            if (($severity === E_WARNING || $severity === E_NOTICE)
                && $file === __FILE__ && str_starts_with($message, 'fgets():')) {
                $failed = true;
                return true;
            }
            return $previousHandler !== null ? $previousHandler($severity, $message, $file, $line) !== false : false;
        });
        try {
            $line = fgets(STDIN);
        } finally {
            restore_error_handler();
        }
        if ($line === false && $failed) {
            throw new \RuntimeException('终端输入已断开，项目流程已停止。请在终端重新运行 webman-aot guide；需要通过 Composer 启动时，请先设置 COMPOSER_PROCESS_TIMEOUT=0。');
        }
        return $line === false ? null : trim($line);
    }

    /** @param list<string> $arguments @return list<string> */
    private function launcher(array $arguments): array
    {
        $launcher = $this->bin . (PHP_OS_FAMILY === 'Windows' ? '/webman-aot.cmd' : '/webman-aot');
        if (!is_file($launcher) || is_link($launcher) || !is_dir($this->home . '/current')) {
            throw new \RuntimeException('本次安装启动器不存在，请先通过此入口安装：' . $launcher);
        }
        return PHP_OS_FAMILY === 'Windows' ? ['cmd.exe', '/d', '/c', $launcher, ...$arguments] : [$launcher, ...$arguments];
    }

    /** @return array<string,string> */
    private function installedEnvironment(): array
    {
        return ['WEBMAN_AOT_BUILDER_HOME' => $this->home];
    }

    /**
     * @param list<string> $command
     * @param array<string,string> $environment
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function execute(array $command, string $stage, ?string $cwd = null, array $environment = []): array
    {
        $result = $this->runner->run($command, $stage, $cwd, $environment);
        if ($result['code'] !== 0) {
            throw new ProcessFailure("{$stage}失败；退出码 {$result['code']}。\n" . trim(substr($result['stderr'] . $result['stdout'], -2000)), $result['code']);
        }
        return $result;
    }

    private function failure(string $message): void
    {
        $lower = strtolower($message);
        $advice = '检查所选目录和上面的原始错误，修正后通过同一入口重试。';
        if (str_contains($lower, 'could not resolve') || str_contains($lower, 'resolve host') || str_contains($lower, 'dns')) {
            $advice = '域名解析失败，请检查网络和 DNS 后重试；已校验缓存会复用。';
        } elseif (str_contains($lower, 'certificate') || str_contains($lower, 'tls') || str_contains($lower, 'ssl')) {
            $advice = '证书或 TLS 校验失败，请检查系统时间和可信证书配置，不要关闭证书验证。';
        } elseif (preg_match('/\bHTTP(?:\/[0-9.]+)?\s+(?:error\s*[:=]?\s*)?[45][0-9]{2}\b|curl:\s*\(22\)/i', $message) === 1) {
            $advice = '下载服务器返回 HTTP 错误，请根据原始状态码检查网络或稍后重试。';
        } elseif (str_contains($lower, 'permission denied') || str_contains($lower, 'for writing') || str_contains($message, '无法写入')) {
            $advice = '输出目录无法写入，请检查上述目录的写入权限及磁盘空间后重试；不会继续安装失败产物。';
        } elseif (str_contains($message, '摘要') || str_contains($lower, 'sha256') || str_contains($lower, 'checksum')) {
            $advice = '文件完整性校验失败，请重新取得可信材料；此流程不会继续使用损坏输入。';
        }
        fwrite(STDERR, "[失败] {$message}\n解决办法：{$advice}\n");
        if (isset($this->log)) {
            fwrite(STDERR, "本机日志：{$this->log}\n");
        }
        fwrite(STDERR, '仍无法解决请到 Issues 提供错误摘要（请先检查日志中个人路径）：' . self::ISSUES . "\n");
    }

    /** @return array<string,mixed> */
    private function json(string $text, string $subject): array
    {
        try {
            $value = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException($subject . '损坏：无法读取机器结果，请查看日志并重试。', previous: $exception);
        }
        if (!is_array($value)) {
            throw new \RuntimeException($subject . '格式无效，不能继续。');
        }
        return $value;
    }

    private function isAbsolute(string $path): bool
    {
        return preg_match('~^(?:/|[A-Za-z]:[\\\\/]|\\\\\\\\)~', $path) === 1;
    }

    private function absolute(string $path): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('目录不能为空。');
        }
        if (!$this->isAbsolute($path)) {
            $path = getcwd() . '/' . $path;
        }
        $path = str_replace('\\', '/', $path);
        return $path === '/' || preg_match('~^[A-Za-z]:/$~D', $path) === 1 ? $path : rtrim($path, '/');
    }
}
