<?php
declare(strict_types=1);

namespace Supdger\WebmanAotInstaller;

final class Installer
{
    public const VERSION = '0.4.1';
    private array $release;
    private bool $interactive;
    private bool $consoleRecoveryAllowed;

    public function __construct(?array $release = null, ?bool $interactive = null)
    {
        $this->release = $release ?? json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/resources/releases.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->interactive = $interactive ?? (stream_isatty(STDIN) && stream_isatty(STDOUT));
        $this->consoleRecoveryAllowed = $interactive === null;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        try {
            [$arguments, $options] = $this->parse(array_slice($argv, 1));
            if (($options['non-interactive'] ?? false) === true) {
                $this->interactive = false;
            }
            $command = $arguments[0] ?? 'guide';
            $guided = in_array($command, ['guide', 'start'], true);
            if ($guided) {
                require_once __DIR__ . '/Console.php';
                if (Console::unattended()) { $this->interactive = false; }
            }
            if ($command === 'uninstall') {
                require_once __DIR__ . '/Uninstaller.php';
                $uninstallArguments = array_slice($arguments, 1);
                foreach (['state-dir', 'non-interactive'] as $key) {
                    if (isset($options[$key])) {
                        $uninstallArguments[] = $key === 'state-dir' ? '--state-dir=' . $options[$key] : '--non-interactive';
                    }
                }
                if (isset($options['yes']) || isset($options['archive'])) {
                    throw new \InvalidArgumentException('卸载须逐项确认；不接受 --yes 或 --archive。');
                }
                return (new Uninstaller($this->interactive))->run($uninstallArguments);
            }
            if (in_array($command, ['help', '--help', '-h'], true)) {
                $this->help();
                return 0;
            }
            if (in_array($command, ['version', '--version', '-V'], true)) {
                $this->say('Composer 入口 ' . self::VERSION . '；目标 Webman AOT Builder ' . $this->release['version']);
                if ($this->interactive) {
                    $this->nextStep();
                }
                return 0;
            }
            if ($guided && !$this->interactive) {
                if (isset($arguments[0]) && $this->consoleRecoveryAllowed && !isset($options['non-interactive'])) {
                    $code = Console::restore($argv);
                    if ($code !== null) { return $code; }
                }
                $this->say('项目菜单需要交互终端；本次未下载、安装或构建。');
                $this->nextStep();
                return 0;
            }
            $host = self::host();
            if ($guided && !$this->guideOptions($host, $options)) {
                $this->say('流程已结束；未准备资源或构建项目。');
                return 0;
            }
            $state = $options['state-dir'] ?? $this->defaultState($host);
            $state = $this->state((string) $state);
            $originalDirectory = getcwd();
            if (!is_string($originalDirectory)) {
                throw new \RuntimeException('无法读取项目当前目录。');
            }
            $this->prepare($state, $host, $options);
            if ($command === 'setup') {
                $this->say('准备成功。');
                $this->nextStep();
                return 0;
            }
            if ($guided) {
                $this->say('[就绪] 下面选择“1 构建项目”，再输入项目目录；构建后会自动校验并显示产物位置。');
                return $this->forward($state, $host, [
                    '--mode=project', '--home=' . $state . '/runtime',
                    '--bin-dir=' . $state . '/bin', '--no-path',
                ], $originalDirectory, 'tools/guided.php');
            }
            return $this->forward($state, $host, $arguments, $originalDirectory);
        } catch (\Throwable $error) {
            fwrite(STDERR, '[失败] ' . $error->getMessage() . PHP_EOL);
            return 70;
        }
    }

    public static function host(): string
    {
        if (PHP_INT_SIZE !== 8) {
            throw new \RuntimeException('此入口需要 64 位系统 PHP。');
        }
        if (PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64') {
            return 'macos-arm64';
        }
        if (PHP_OS_FAMILY === 'Windows'
            && (strtoupper((string) getenv('PROCESSOR_ARCHITECTURE')) === 'AMD64'
                || strtoupper((string) getenv('PROCESSOR_ARCHITEW6432')) === 'AMD64')) {
            return 'windows-x86_64';
        }
        throw new \RuntimeException('仅支持 macOS Apple Silicon / Windows x64 构建宿主；检测到 ' . PHP_OS_FAMILY . ' ' . php_uname('m'));
    }

    /** @return array{list<string>,array<string,string|bool>} */
    private function parse(array $arguments): array
    {
        $options = [];
        for ($i = 0; $i < count($arguments); $i++) {
            $argument = $arguments[$i];
            if ($argument === '--') {
                $i++;
                break;
            }
            if (in_array($argument, ['--yes', '--non-interactive'], true)) {
                $options[substr($argument, 2)] = true;
                continue;
            }
            if (preg_match('/^--(state-dir|archive)(?:=(.*))?$/D', $argument, $match)) {
                $value = $match[2] ?? ($arguments[++$i] ?? '');
                if ($value === '' || isset($options[$match[1]])) {
                    throw new \InvalidArgumentException('缺少参数值或重复参数：' . $match[1]);
                }
                $options[$match[1]] = $value;
                continue;
            }
            break;
        }
        $forward = array_slice($arguments, $i);
        if (in_array($forward[0] ?? '', ['setup', 'guide', 'start'], true)) {
            $command = $forward[0];
            [$tail, $setupOptions] = $this->parse(array_slice($forward, 1));
            if ($tail !== []) {
                throw new \InvalidArgumentException($command . ' 不接受项目参数；使用 --archive、--state-dir、--yes 或 --non-interactive。');
            }
            foreach ($setupOptions as $key => $value) {
                if (isset($options[$key])) {
                    throw new \InvalidArgumentException('重复参数：' . $key);
                }
                $options[$key] = $value;
            }
            $forward = [$command];
        }
        return [$forward, $options];
    }

    private function help(): void
    {
        $this->say("supdger/webman-aot-builder Composer 入口 " . self::VERSION . "\n目标构建器：" . $this->release['version']
            . "\n\n开始使用：\n  composer global exec -- webman-aot guide"
            . "\n自动识别当前系统并打开准备与项目构建菜单；已配置 Composer bin 到 PATH 时，也可直接运行 webman-aot。\n\n用法：\n  webman-aot guide\n  webman-aot build [原构建参数]\n  webman-aot doctor\n  webman-aot uninstall [--list]\n  webman-aot setup --yes\n  webman-aot setup --archive=完整安装包路径 --non-interactive"
            . "\n\n菜单可选择自动准备或导入完整包，然后选择项目目录、构建并校验产物。\n首次 build/doctor 需准备完整包；help/version/uninstall 不准备资源。非交互需 --yes 或 --archive。\n--state-dir=目录 指定独立安装和缓存目录，不修改旧安装或 PATH。\n全局选项放在 doctor/build 等原命令之前；setup/guide 的选项可放后面。\n支持 macOS ARM64 / Windows x64，产物运行在 Linux x86_64。\nhelp/version 只说明入口，不表示原构建器已安装。失败后可从同一菜单继续编译：复用校验通过的已完成单元，未完成单元从头编译；每次重新链接并校验产物。需要全部重编时运行 webman-aot build --fresh，或在失败菜单选择全量重建。");
    }

    private function nextStep(): void
    {
        $this->say('下一步：在终端运行 composer global exec -- webman-aot guide，选择准备方式和项目目录。');
    }

    /** @param array<string,string|bool> $options */
    private function guideOptions(string $host, array &$options): bool
    {
        $package = $this->release['packages'][$host];
        $this->say('Webman AOT 项目构建：准备组件 → 选择项目 → 构建并校验产物。');
        $this->say('当前系统：' . $host . '；目标构建器：' . $this->release['version']);
        if (isset($options['archive'])) {
            $this->say('使用指定的本地完整包；校验通过后进入项目菜单。');
            return true;
        }
        $this->say('1 开始（已准备资源自动复用；首次下载完整包，' . sprintf('%.1f MB', $package['size'] / 1000000) . '）'
            . "\n2 导入已下载的完整包\n0 结束");
        while (($line = fgets(STDIN)) !== false) {
            $choice = trim($line);
            if ($choice === '' || $choice === '0') {
                return false;
            }
            if ($choice === '1') {
                return true;
            }
            if ($choice === '2') {
                $this->say("所需完整包：\n" . $package['filename'] . "\n" . $package['url']);
                $archive = $this->offline($package);
                if ($archive === null) {
                    return false;
                }
                $options['archive'] = $archive;
                return true;
            }
            $this->say('选择无效，请输入 1、2 或 0。');
        }
        return false;
    }

    private function defaultState(string $host): string
    {
        $base = $host === 'macos-arm64' ? getenv('HOME') : getenv('LOCALAPPDATA');
        if (!is_string($base) || $base === '') {
            throw new \RuntimeException('无法读取个人数据目录；请用 --state-dir 指定。');
        }
        return $host === 'macos-arm64'
            ? $base . '/Library/Application Support/webman-aot-composer'
            : $base . '/webman-aot-composer';
    }

    private function state(string $path): string
    {
        if (!preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $path)) {
            $path = (string) getcwd() . '/' . $path;
        }
        $probe = $path;
        while (!is_dir($probe)) {
            if (is_link($probe) || file_exists($probe)) {
                throw new \RuntimeException('状态目录不能是文件或链接。');
            }
            $parent = dirname($probe);
            if ($parent === $probe) {
                throw new \RuntimeException('状态目录无法解析。');
            }
            $probe = $parent;
        }
        if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700, true))) {
            throw new \RuntimeException('无法创建独立状态目录。');
        }
        $resolved = realpath($path);
        if (!is_string($resolved) || dirname($resolved) === $resolved) {
            throw new \RuntimeException('状态目录不能是系统根目录。');
        }
        foreach (['runtime', 'bin', 'cache', 'ready.json', 'setup.lock', 'owner.json'] as $name) {
            if (is_link($resolved . '/' . $name)) {
                throw new \RuntimeException('状态子目录不能是链接：' . $name);
            }
        }
        $this->assertOwnership($resolved);
        return $resolved;
    }

    private function assertOwnership(string $state): void
    {
        $file = $state . '/owner.json';
        if (is_file($file)) {
            $owner = json_decode((string) file_get_contents($file), true);
            if (!is_array($owner) || ($owner['schema'] ?? null) !== 1
                || ($owner['package'] ?? '') !== 'supdger/webman-aot-builder') {
                $legacy = is_array($owner) && ($owner['schema'] ?? null) === 1 && ($owner['package'] ?? '') === 'saiadmin/webman-aot-builder';
                throw new \RuntimeException($legacy
                    ? '状态目录属于旧 saiadmin/webman-aot-builder；不会接管。请从新 Composer 代理完整路径运行 uninstall --state-dir="' . $state . '" 逐项确认清理，再重新准备；也可选择独立空目录。'
                    : '状态目录不属于 supdger/webman-aot-builder；不会接管或覆盖。请核对来源或选择独立空目录。');
            }
            return;
        }
        foreach (['runtime', 'bin'] as $name) {
            $path = $state . '/' . $name;
            if (file_exists($path) && (!is_dir($path) || count(scandir($path) ?: []) > 2)) {
                throw new \RuntimeException('状态目录包含无本入口所有权标记的已有 ' . $name . '；不会覆盖，请另选空目录。');
            }
        }
        if (file_exists($state . '/ready.json')) {
            throw new \RuntimeException('已有就绪记录没有本入口所有权；不会接管，请另选空目录。');
        }
    }

    private function ready(string $state, string $host): bool
    {
        $marker = $state . '/ready.json';
        $data = is_file($marker) && !is_link($marker) ? json_decode((string) file_get_contents($marker), true) : null;
        $php = $state . '/runtime/current/runtime/' . ($host === 'macos-arm64' ? 'bin/php' : 'php.exe');
        return is_array($data) && ($data['version'] ?? '') === $this->release['version']
            && ($data['host'] ?? '') === $host && is_file($php) && !is_link($php)
            && is_file($state . '/runtime/current/app/bin/webman-aot-builder.php');
    }

    private function prepare(string $state, string $host, array $options): void
    {
        if ($this->ready($state, $host)) {
            return;
        }
        if (!isset($options['archive']) && !isset($options['yes']) && !$this->interactive) {
            throw new \RuntimeException('尚未准备运行时。非交互模式不会等待输入；运行 webman-aot setup --yes 或 setup --archive=完整包路径 --non-interactive。');
        }
        $lockFile = $state . '/setup.lock';
        if (is_link($lockFile)) {
            throw new \RuntimeException('安装锁不能是链接。');
        }
        $lock = fopen($lockFile, 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('另一个入口正在准备资源；请稍后重试。');
        }
        $started = microtime(true);
        $extract = null;
        try {
            $this->assertOwnership($state);
            if (!is_file($state . '/owner.json')
                && file_put_contents($state . '/owner.json', json_encode([
                    'schema' => 1, 'package' => 'supdger/webman-aot-builder',
                ], JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('无法写入隔离状态所有权。');
            }
            if ($this->ready($state, $host)) {
                return;
            }
            $package = $this->release['packages'][$host];
            $this->say('[准备] ' . $this->release['version'] . ' / ' . $host . ' 完整包，' . $package['size'] . ' 字节。');
            $archive = isset($options['archive']) ? self::inputPath((string) $options['archive']) : null;
            if ($archive === null) {
                try {
                    $archive = $this->download($state, $package, $host);
                } catch (\Throwable $failure) {
                    $this->say('在线准备失败：' . $failure->getMessage());
                    $this->say("请下载这个完整安装包：\n" . $package['filename'] . "\n" . $package['url']);
                    if (!$this->interactive) {
                        throw new \RuntimeException('网络失败；下载后使用 setup --archive=完整路径 --non-interactive 导入。');
                    }
                    $archive = $this->offline($package);
                    if ($archive === null) {
                        throw new \RuntimeException('已取消资源准备；原项目命令尚未运行。');
                    }
                }
            }
            Archive::verify($archive, $package);
            $this->say('[校验] 大小与 SHA-256 通过。');
            $extract = $state . '/cache/extract-' . bin2hex(random_bytes(8));
            $this->say('[解包] 检查安全路径并解包原始完整安装包。');
            Archive::extract($archive, $extract, $host);
            Archive::identity($extract, $host, $this->release['version']);
            $this->say('[安装] 安装到独立目录，不改原安装与 PATH。');
            $command = $host === 'macos-arm64'
                ? ['/bin/sh', $extract . '/install.sh', '--home', $state . '/runtime', '--bin-dir', $state . '/bin', '--no-path']
                : ['powershell.exe', '-NoLogo', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $extract . '/install.ps1',
                    '-InstallRoot', $state . '/runtime', '-BinDir', $state . '/bin', '-NoPath'];
            $code = Process::run($command, $extract);
            if ($code !== 0) {
                throw new \RuntimeException('原安装器失败，退出码 ' . $code . '。已保留包缓存，修复后可重试。');
            }
            $code = $this->forward($state, $host, ['version'], (string) getcwd());
            if ($code !== 0) {
                throw new \RuntimeException('私有运行时自检失败，退出码 ' . $code);
            }
            $marker = json_encode(['version' => $this->release['version'], 'host' => $host], JSON_THROW_ON_ERROR);
            if (file_put_contents($state . '/ready.json', $marker . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('无法记录就绪状态。');
            }
            $this->say(sprintf('[成功] 资源准备完成，耗时 %.1f 秒。', microtime(true) - $started));
        } finally {
            if (is_string($extract)) {
                $this->removeTemporary($extract);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function download(string $state, array $package, string $host): string
    {
        $cache = $state . '/cache';
        if (!is_dir($cache) && !mkdir($cache, 0700, true)) {
            throw new \RuntimeException('无法创建资源缓存。');
        }
        $archive = $cache . '/' . $package['filename'];
        if (is_file($archive)) {
            try {
                Archive::verify($archive, $package);
                $this->say('[缓存] 复用已经校验的完整包。');
                return $archive;
            } catch (\Throwable) {
                $this->say('[缓存] 旧缓存无效，将取得新的完整包。');
            }
        }
        $partial = $cache . '/download-' . bin2hex(random_bytes(8));
        $curl = $host === 'macos-arm64' ? '/usr/bin/curl' : ((string) getenv('SystemRoot')) . '\\System32\\curl.exe';
        $this->say('[下载] ' . $package['url'] . "\n保留实际下载进度，最多重试 2 次；Ctrl+C 可取消。");
        try {
            $code = Process::run([$curl, '--fail', '--location', '--proto', '=https', '--proto-redir', '=https',
                '--retry', '2', '--retry-all-errors', '--retry-delay', '2', '--connect-timeout', '20',
                '--max-time', '1800', '--output', $partial, $package['url']]);
            if ($code !== 0) {
                throw new \RuntimeException('curl 退出码 ' . $code);
            }
            Archive::verify($partial, $package);
            if (is_link($archive) || !rename($partial, $archive)) {
                throw new \RuntimeException('无法保存已校验完整包。');
            }
        } finally {
            if (is_file($partial) && !is_link($partial)) {
                unlink($partial);
            }
        }
        return $archive;
    }

    private function offline(array $package): ?string
    {
        while (true) {
            $this->say('下载后按回车检查常规 Downloads 目录，或输入/拖入完整安装包路径；输入 0 取消。');
            $line = fgets(STDIN);
            if ($line === false || trim($line) === '0') {
                return null;
            }
            if (trim($line) !== '') {
                $path = self::inputPath($line);
                try {
                    Archive::verify($path, $package);
                    return $path;
                } catch (\Throwable $error) {
                    $this->say($error->getMessage());
                    continue;
                }
            }
            $home = PHP_OS_FAMILY === 'Windows' ? getenv('USERPROFILE') : getenv('HOME');
            $path = is_string($home) ? $home . '/Downloads/' . $package['filename'] : '';
            if ($path !== '' && is_file($path)) {
                try {
                    Archive::verify($path, $package);
                    $this->say('[本地] 找到并校验：' . $path);
                    return $path;
                } catch (\Throwable $error) {
                    $this->say($error->getMessage());
                }
            }
            $this->say('常规 Downloads 目录未找到匹配包。浏览器自定义目录请直接输入完整路径。');
        }
    }

    public static function inputPath(string $input): string
    {
        $input = trim($input);
        if (strlen($input) >= 2 && in_array($input[0], ['"', "'"], true)
            && substr($input, -1) === $input[0]) {
            $input = substr($input, 1, -1);
        } elseif (PHP_OS_FAMILY !== 'Windows') {
            $input = preg_replace('/\\\\([ \'"()])/', '$1', $input) ?? $input;
        }
        if ($input === '' || str_contains($input, "\0")) {
            throw new \InvalidArgumentException('完整包路径为空或无效。');
        }
        return $input;
    }

    private function forward(string $state, string $host, array $arguments, string $cwd, string $script = 'bin/webman-aot-builder.php'): int
    {
        $runtime = $state . '/runtime/current/runtime';
        $entry = $state . '/runtime/current/app/' . $script;
        if (!is_file($entry) || is_link($entry)) {
            throw new \RuntimeException('私有运行时缺少所需入口：' . $script . '；请重新准备完整包。');
        }
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['WEBMAN_AOT_BUILDER_HOME'] = $state . '/runtime';
        if ($host === 'windows-x86_64') {
            $environment['WEBMAN_AOT_CALLER_CWD'] = $cwd;
            return Process::run([$runtime . '/php.exe', '-c', 'php.ini', '-d', 'extension_dir=ext',
                $state . '/runtime/current/app/tools/windows-php-bootstrap.php', $entry, ...$arguments],
                $runtime, $environment);
        }
        return Process::run([$runtime . '/bin/php', '-n', $entry, ...$arguments], $cwd, $environment);
    }

    private function say(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    private function removeTemporary(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
