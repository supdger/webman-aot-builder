<?php
declare(strict_types=1);

namespace Supdger\WebmanAotInstaller;

/** Shared by the lightweight Composer entry and the native uninstallers; PHP 8.1+. */
final class Uninstaller
{
    private const PACKAGES = ['supdger/webman-aot-builder', 'saiadmin/webman-aot-builder'];
    private array $items = [];
    private array $roots = [];
    private array $bins = [];
    private bool $windows;
    private array $templates;

    public function __construct(private ?bool $interactive = null)
    {
        $this->windows = PHP_OS_FAMILY === 'Windows';
        $this->templates = $this->json(dirname(__DIR__) . '/resources/uninstall-launchers.json');
        $this->interactive ??= stream_isatty(STDIN) && stream_isatty(STDOUT);
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $started = microtime(true);
        try {
            $options = $this->options($arguments);
            $this->discover($options);
            fwrite(STDOUT, "[检查] 找到 " . count($this->items) . " 项；版本来自文件，不运行旧命令。\n");
            $list = isset($options['list']);
            foreach ($this->items as $index => $item) {
                fwrite(STDOUT, sprintf("%d. %s | 版本 %s\n   路径：%s\n   用途：%s\n   卸载影响：%s\n   %s\n", $index + 1,
                    $item['type'], $item['version'], $item['path'], $item['purpose'], $item['impact'], $item['reason']));
            }
            if ($list || !$this->interactive) {
                fwrite(STDOUT, $list ? "[完成] 仅列出，没有更改。\n" : "[保留] 非交互环境不卸载；在终端运行 webman-aot uninstall 逐项确认。\n");
                return 0;
            }
            $removed = 0;
            $failed = 0;
            foreach ($this->items as $item) {
                if (!$item['owned']) { continue; }
                // Another selected item may already have removed an associated launcher.
                if (!file_exists($item['path']) && !is_link($item['path'])) { continue; }
                while (true) {
                    fwrite(STDOUT, "是否卸载 {$item['type']} {$item['version']}？\n路径：{$item['path']}\n卸载影响：{$item['impact']}\n[y/N/q，回车保留，q结束]：");
                    $answer = fgets(STDIN);
                    if ($answer === false || strtolower(trim($answer)) === 'q') {
                        fwrite(STDOUT, "\n[结束] 未确认的项目已保留。\n");
                        break 2;
                    }
                    $answer = strtolower(trim($answer));
                    if ($answer === '' || $answer === 'n') { break; }
                    if ($answer === 'y') {
                        try {
                            $this->remove($item);
                            $removed++;
                            fwrite(STDOUT, "[已卸载] {$item['path']}\n");
                        } catch (\Throwable $error) {
                            $failed++;
                            fwrite(STDERR, "[失败] {$item['path']}：{$error->getMessage()}；请检查残留后重试。\n");
                        }
                        break;
                    }
                    fwrite(STDOUT, "请输入 y、n 或 q；默认保留。\n");
                }
            }
            fwrite(STDOUT, sprintf("[结果] 卸载 %d 项，失败 %d 项；其余保留。耗时 %.1f 秒。\nPATH 与项目产物未修改；独立安装根的共享工具链和日志保留。Composer 私有运行时项包含其中的工具链、日志与缓存。旧备份命令不会恢复。\n",
                $removed, $failed, microtime(true) - $started));
            $this->discover($options);
            fwrite(STDOUT, "[剩余] 重新检查到 " . count($this->items) . " 项：\n");
            foreach ($this->items as $remaining) {
                fwrite(STDOUT, "  {$remaining['type']} | {$remaining['version']} | {$remaining['path']}\n");
            }
            return $failed === 0 ? 0 : 70;
        } catch (\Throwable $error) {
            fwrite(STDERR, "[失败] {$error->getMessage()}\n");
            return 70;
        }
    }

    private function options(array $arguments): array
    {
        $options = [];
        for ($i = 0; $i < count($arguments); $i++) {
            $argument = $arguments[$i];
            if ($argument === '--list' || $argument === '--non-interactive' || $argument === '--no-path') {
                $options[substr($argument, 2)] = true;
                if ($argument === '--non-interactive') { $this->interactive = false; }
            } elseif (preg_match('/^--(home|bin-dir|state-dir)(?:=(.*))?$/D', $argument, $match)) {
                $value = $match[2] ?? ($arguments[++$i] ?? '');
                if ($value === '' || str_contains($value, "\0")) { throw new \InvalidArgumentException('缺少目录参数：' . $match[1]); }
                $options[$match[1]][] = $value;
            } else {
                throw new \InvalidArgumentException('未知卸载参数：' . $argument . '；可用 --list、--home、--bin-dir、--state-dir。不提供免确认全删。');
            }
        }
        return $options;
    }

    private function discover(array $options): void
    {
        $this->items = [];
        $home = (string) getenv('HOME');
        $local = (string) getenv('LOCALAPPDATA');
        $base = $this->windows ? $local : ($home !== '' ? $home . '/Library/Application Support' : '');
        $roots = $options['home'] ?? [];
        if ($base !== '') { $roots[] = $base . '/webman-aot-builder'; $roots[] = $base . '/webman-aot'; }
        $override = getenv('WEBMAN_AOT_BUILDER_HOME');
        if (is_string($override) && $override !== '') { $roots[] = $override; }
        $legacyOverride = getenv('WEBMAN_AOT_HOME');
        if (is_string($legacyOverride) && $legacyOverride !== '') { $roots[] = $legacyOverride; }
        $this->roots = $this->uniquePaths($roots);
        $bins = $options['bin-dir'] ?? [];
        if ($this->windows && $local !== '') {
            $bins[] = $local . '/webman-aot-builder/bin';
            $bins[] = $local . '/webman-aot/bin';
        } elseif ($home !== '') { $bins[] = $home . '/.local/bin'; }
        $path = (string) getenv('PATH');
        foreach (explode($this->windows ? ';' : ':', $path) as $bin) {
            $bin = $this->windows ? trim($bin, ' "') : $bin;
            if ($bin !== '') { $bins[] = $bin; }
        }
        $this->bins = $this->uniquePaths($bins);
        foreach ($this->roots as $root) {
            $generations = $this->children($root . '/versions');
            rsort($generations, SORT_STRING);
            $active = null;
            foreach ($generations as $generation) {
                if (($this->json($generation . '/manifest.json')['schema'] ?? '') === 'webman-aot-builder-cli-generation-v1'
                    && $this->nativeVersion($generation) !== null) { $active = $generation; break; }
            }
            $this->native($root . '/current', $root, '独立安装（当前）', true);
            foreach ($generations as $generation) {
                if (basename($generation) === 'rolled-back') {
                    foreach ($this->children($generation) as $rollback) { $this->native($rollback, $root, '独立安装（回滚版本）', false); }
                } else { $this->native($generation, $root, '独立安装（版本代次）', $generation === $active); }
            }
            foreach ($this->children($root . '/.install-backups') as $backup) {
                if (is_dir($backup . '/app')) { $this->native($backup, $root, '独立安装（旧版历史备份）', false); }
                $this->native($backup . '/current', $root, '独立安装（历史备份）', false);
                foreach ($this->children($backup . '/versions') as $generation) { $this->native($generation, $root, '独立安装（备份代次）', false); }
                $this->launcher($backup . '/' . ($this->windows ? 'webman-aot.cmd' : 'webman-aot'), '备份命令');
            }
            $this->launcher($root . '/.previous-launcher/' . ($this->windows ? 'webman-aot.cmd' : 'webman-aot'), '旧版备份命令');
        }
        $states = $options['state-dir'] ?? [];
        if ($base !== '') { $states[] = $base . '/webman-aot-composer'; }
        foreach ($this->uniquePaths($states) as $state) {
            if (!file_exists($state) && !is_link($state)) { continue; }
            $owner = $this->json($state . '/owner.json');
            $owned = ($owner['schema'] ?? null) === 1 && in_array($owner['package'] ?? '', self::PACKAGES, true) && $this->safe($state);
            $version = $this->json($state . '/ready.json')['version'] ?? '未知';
            $this->add(['path' => $state, 'package' => (string) ($owner['package'] ?? ''), 'type' => 'Composer 私有运行时/缓存（' . ($owner['package'] ?? '未知') . '）', 'version' => (string) $version,
                'owned' => $owned, 'reason' => $owned ? '仅卸载本入口所有权白名单，保留其他文件。' : '归属或路径无法确认，保留。', 'kind' => 'state']);
        }
        foreach ($this->bins as $bin) {
            foreach ($this->windows ? ['webman-aot.cmd', 'webman-aot.bat', 'webman-aot', 'webman-aot-builder.cmd'] : ['webman-aot', 'webman-aot-builder'] as $name) {
                $this->launcher($bin . '/' . $name, 'PATH/标准目录命令');
            }
        }
        // Composer owns its proxies. Global package action is last; never unlink its proxies ourselves.
        $globals = [];
        foreach (['COMPOSER_HOME' => '', 'APPDATA' => '/Composer', 'XDG_CONFIG_HOME' => '/composer'] as $env => $suffix) {
            $value = getenv($env);
            if (is_string($value) && $value !== '') { $globals[] = $value . $suffix; }
        }
        if (!$this->windows && $home !== '') { $globals[] = $home . '/.composer'; $globals[] = $home . '/.config/composer'; }
        foreach ($this->uniquePaths($globals) as $global) {
            $manifest = $this->json($global . '/composer.json');
            foreach (self::PACKAGES as $packageName) {
                if (!isset($manifest['require'][$packageName])) { continue; }
                $version = '未知';
                foreach ($this->json($global . '/composer.lock')['packages'] ?? [] as $package) {
                    if (($package['name'] ?? '') === $packageName) { $version = (string) ($package['version'] ?? '未知'); }
                }
                $owned = $this->safe($global) && $this->composerCommand() !== null;
                $this->add(['path' => $global, 'package' => $packageName, 'type' => 'Composer 全局包（' . $packageName . '）', 'version' => $version, 'owned' => $owned,
                    'reason' => $owned ? 'Composer 仅移除 ' . $packageName . '；其他全局工具保留，scripts/plugins 禁用。'
                        : '无法安全调用 Composer；保留。可在核对 global home 后运行 composer global remove ' . $packageName . '。', 'kind' => 'composer']);
            }
        }
    }

    private function native(string $path, string $root, string $type, bool $active): void
    {
        if (!file_exists($path) && !is_link($path)) { return; }
        $version = $this->nativeVersion($path);
        $owned = $version !== null && $this->safe($root) && $this->safe($path) && $this->nativeContents($path);
        $this->add(['path' => $path, 'type' => $type, 'version' => $version ?? '未知', 'owned' => $owned,
            'reason' => $owned ? ($active ? '卸载此版本同时撤销归属明确的公开命令，保留版本不会自动启用。' : '仅卸载此版本。')
                : '版本归属、目录内容或路径无法安全确认，保留。', 'kind' => 'native', 'root' => $root, 'active' => $active]);
    }

    private function nativeVersion(string $path): ?string
    {
        $version = $this->text($path . '/app/src/Version.php');
        $legacy = str_contains($version, 'namespace WebmanAot;');
        $namespace = $legacy ? 'WebmanAot' : 'WebmanAotBuilder';
        $entry = $this->text($path . '/app/bin/' . ($legacy ? 'webman-aot.php' : 'webman-aot-builder.php'));
        if (!str_contains($version, 'namespace ' . $namespace . ';') || !str_contains($entry, $namespace . '\\Cli\\')) { return null; }
        $entryHash = hash('sha256', str_replace("\r\n", "\n", $entry));
        if (($this->templates['entries'][$entryHash] ?? null) !== ($legacy ? 'legacy' : 'builder')) { return null; }
        if (!preg_match("/public\\s+const\\s+VALUE\\s*=\\s*'([0-9]+\\.[0-9]+\\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?)'\\s*;/", $version, $matches)) { return null; }
        $expected = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nfinal class Version\n{\n    public const VALUE = '{$matches[1]}';\n}\n";
        return str_replace("\r\n", "\n", $version) === $expected ? $matches[1] : null;
    }

    private function nativeContents(string $path): bool
    {
        // A version directory is package-owned; extra siblings require human inspection.
        foreach (scandir($path) ?: [] as $name) {
            if (!in_array($name, ['.', '..', 'app', 'runtime', 'manifest.json'], true)) { return false; }
        }
        return is_dir($path . '/app') && $this->treeSafe($path);
    }

    private function launcher(string $path, string $type): void
    {
        if (!file_exists($path) && !is_link($path)) { return; }
        $text = $this->text($path);
        $owned = $this->publicLauncher($text) && $this->safe($path);
        $family = $this->launcherFamily($text);
        $base = $this->windows ? (string) getenv('LOCALAPPDATA') : (string) getenv('HOME') . '/Library/Application Support';
        $root = getenv($family === 'legacy' ? 'WEBMAN_AOT_HOME' : 'WEBMAN_AOT_BUILDER_HOME') ?: $base . '/' . ($family === 'legacy' ? 'webman-aot' : 'webman-aot-builder');
        $version = $family !== null ? ($this->nativeVersion($root . '/current') ?? '未知') : '未知';
        // Composer proxies deliberately remain managed by Composer, including .bat/extensionless pairs.
        $composer = str_contains($text, 'supdger/webman-aot-builder/') || str_contains($text, 'saiadmin/webman-aot-builder/') || str_contains($text, 'composer-installer/bin/webman-aot');
        $this->add(['path' => $path, 'type' => $type, 'version' => $version, 'owned' => $owned,
            'reason' => $owned ? '仅移除此已确认归属的命令文件；不卸载工具链，不恢复备份。'
                : ($composer ? 'Composer 代理；由全局包卸载处理，单独保留。' : '无足够所有权证据，保留；请核对来源后处理此精确路径。'), 'kind' => 'launcher']);
    }

    private function publicLauncher(string $text): bool
    {
        return $this->launcherFamily($text) !== null;
    }

    private function launcherFamily(string $text): ?string
    {
        $hash = hash('sha256', str_replace("\r\n", "\n", $text));
        $templates = $this->templates['launchers'] ?? [];
        return isset($templates[$hash]) && is_string($templates[$hash]) ? $templates[$hash] : null;
    }

    private function remove(array $item): void
    {
        $path = $item['path'];
        if (!$this->safe($path)) { throw new \RuntimeException('路径已改变或位于保护范围'); }
        if ($item['kind'] === 'composer') {
            $packageName = $item['package'] ?? '';
            if (!in_array($packageName, self::PACKAGES, true)) { throw new \RuntimeException('未知全局包身份'); }
            if (!isset($this->json($path . '/composer.json')['require'][$packageName])) { throw new \RuntimeException('全局包记录已改变'); }
            $command = $this->composerCommand();
            if ($command === null) { throw new \RuntimeException('找不到可安全调用的 Composer'); }
            $environment = getenv();
            $environment['COMPOSER_HOME'] = $path;
            $command = array_merge($command, ['--no-plugins', '--no-scripts', 'global', 'remove', '--no-interaction', $packageName]);
            fwrite(STDOUT, "[卸载] Composer 全局包；保留其他包…\n");
            $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $path, $environment, ['bypass_shell' => true]);
            if (!is_resource($process)) { throw new \RuntimeException('Composer 未能启动'); }
            $code = proc_close($process);
            if ($code !== 0) { throw new \RuntimeException('Composer remove 退出码 ' . $code); }
            if (isset($this->json($path . '/composer.json')['require'][$packageName])) { throw new \RuntimeException('Composer 结束后包记录仍在'); }
            return;
        }
        if ($item['kind'] === 'state') {
            $owner = $this->json($path . '/owner.json');
            if (($owner['schema'] ?? null) !== 1 || ($owner['package'] ?? '') !== ($item['package'] ?? '') || !in_array($owner['package'] ?? '', self::PACKAGES, true)) { throw new \RuntimeException('Composer 状态所有权已改变'); }
            $lockFile = $path . '/setup.lock';
            if (is_link($lockFile)) { throw new \RuntimeException('状态锁是链接'); }
            $lock = fopen($lockFile, 'c+');
            if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('资源正在安装，稍后重试'); }
            try {
                $owner = $this->json($path . '/owner.json');
                if (($owner['schema'] ?? null) !== 1 || ($owner['package'] ?? '') !== ($item['package'] ?? '') || !in_array($owner['package'] ?? '', self::PACKAGES, true)) { throw new \RuntimeException('取得安装锁后状态所有权已改变'); }
                foreach (['runtime', 'bin', 'cache', 'ready.json'] as $name) {
                    $target = $path . '/' . $name;
                    if (file_exists($target) || is_link($target)) { $this->assertTree($target); }
                }
                foreach (['runtime', 'bin', 'cache', 'ready.json'] as $name) { $this->erase($path . '/' . $name); }
                // Owner remains until all payload removal has succeeded, enabling a safe retry.
                $this->erase($path . '/owner.json');
            } finally { flock($lock, LOCK_UN); fclose($lock); }
            // Keep the same lock inode: a concurrent setup may acquire it as soon as we release.
            // Removing it here would let another setup create a second, independent lock.
            fwrite(STDOUT, "[保留数据] {$path}：保留安装锁 setup.lock 与额外用户文件，避免并发安装使用两把锁。\n");
            return;
        }
        if ($item['kind'] === 'launcher') {
            if (!$this->publicLauncher($this->text($path))) { throw new \RuntimeException('命令归属已改变'); }
            $this->erase($path);
            return;
        }
        if ($this->nativeVersion($path) !== $item['version'] || !$this->nativeContents($path)) { throw new \RuntimeException('版本或目录所有权已改变'); }
        if ($this->inside(PHP_BINARY, $path)) { throw new \RuntimeException('正在使用此运行时；请从安装包的 uninstall.ps1 启动，或使用 Composer 卸载入口'); }
        if ($item['active']) {
            foreach ($this->bins as $bin) {
                foreach ($this->windows ? ['webman-aot.cmd', 'webman-aot-builder.cmd'] : ['webman-aot', 'webman-aot-builder'] as $name) {
                $launcher = $bin . '/' . $name;
                if ($this->safe($launcher) && $this->boundLauncher($launcher, $item['root'])) {
                    $this->erase($launcher);
                    fwrite(STDOUT, "[已撤销命令] {$launcher}；保留版本不会自动启用。\n");
                }
                }
            }
        }
        $this->erase($path);
    }

    private function boundLauncher(string $path, string $root): bool
    {
        $text = $this->text($path);
        if (!$this->publicLauncher($text)) { return false; }
        $legacy = $this->launcherFamily($text) === 'legacy';
        $name = $legacy ? 'webman-aot' : 'webman-aot-builder';
        $default = $this->windows ? (string) getenv('LOCALAPPDATA') . '/' . $name
            : (string) getenv('HOME') . '/Library/Application Support/' . $name;
        // Shipped public launchers use a standard root or the current explicit environment override.
        $effective = getenv($legacy ? 'WEBMAN_AOT_HOME' : 'WEBMAN_AOT_BUILDER_HOME') ?: $default;
        return $this->key($effective) === $this->key($root);
    }

    private function composerCommand(): ?array
    {
        foreach ($this->bins as $bin) {
            $phar = $bin . '/composer.phar';
            if (is_file($phar) && $this->safe($phar)) { return [PHP_BINARY, $phar]; }
            if (!$this->windows) {
                $command = $bin . '/composer';
                $real = realpath($command);
                if (is_string($real) && is_file($real) && is_executable($real)) { return [$real]; }
            }
        }
        return null;
    }

    private function add(array $item): void
    {
        $item['purpose'] = match ($item['kind']) {
            'state' => '供 Composer 入口运行和构建使用的私有 PHP、构建器、工具链及下载缓存。',
            'native' => '此独立安装的构建器与私有 PHP；历史版本可供回滚。',
            'composer' => '提供 webman-aot 命令的 Composer 全局入口包。',
            'launcher' => '启动 Webman AOT 的命令文件或历史命令备份。',
        };
        $item['impact'] = !$item['owned'] ? '此项不会卸载；下方说明保留原因。' : match ($item['kind']) {
            'state' => '移除本项私有 PHP、构建器、工具链、内部日志及缓存；再次构建需重新准备。保留安装锁、额外用户文件及其他 Composer 包。',
            'native' => $item['active'] ? '此版本无法再运行，并撤销可确认绑定的公开命令；其他版本、共享工具链和项目产物保留。' : '移除此历史版本，无法再用它回滚；当前版本、共享工具链和项目产物保留。',
            'composer' => '由 Composer 移除此入口包及不再需要的专属依赖、代理命令；其他全局工具和私有运行时保留。',
            'launcher' => '移除此命令文件；对应版本和工具链保留，不恢复备份命令。',
        };
        $key = $this->key($item['path']);
        foreach ($this->items as $existing) {
            if ($this->key($existing['path']) === $key && ($existing['package'] ?? '') === ($item['package'] ?? '')) { return; }
        }
        $this->items[] = $item;
    }

    private function key(string $path): string
    {
        if ($this->windows) { $path = trim($path, ' "'); }
        $real = realpath($path);
        $path = $real === false ? $path : $real;
        if ($this->windows) { $path = str_replace('\\', '/', $path); }
        return $this->windows ? strtolower(rtrim($path, '/')) : rtrim($path, '/');
    }

    private function uniquePaths(array $paths): array
    {
        $result = [];
        foreach ($paths as $path) {
            if (!preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $path)) { $path = (string) getcwd() . '/' . $path; }
            $key = $this->key($path);
            $literal = $this->windows ? trim($path, ' "') : $path;
            $result[$key] = $this->windows ? str_replace('\\', '/', $literal) : $literal;
        }
        return array_values($result);
    }

    private function inside(string $path, string $root): bool
    {
        $path = $this->key($path); $root = $this->key($root);
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function safe(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || is_link($path)) { return false; }
        $key = $this->key($path);
        if ($key === '' || preg_match('~^[a-z]:$~i', $key) || dirname($key) === $key) { return false; }
        foreach (['HOME', 'USERPROFILE', 'LOCALAPPDATA', 'APPDATA'] as $name) {
            $protected = getenv($name);
            if (is_string($protected) && $protected !== '' && $this->key($protected) === $key) { return false; }
        }
        $cwd = getcwd();
        if (is_string($cwd)) {
            $project = is_file($cwd . '/composer.json') || is_file($cwd . '/start.php') || file_exists($cwd . '/.git');
            if ($this->key($cwd) === $key || $this->inside($cwd, $path) || ($project && $this->inside($path, $cwd))) { return false; }
        }
        // Refuse linked ancestors, including Windows junctions where realpath resolves a different directory.
        for ($probe = $path; dirname($probe) !== $probe; $probe = dirname($probe)) {
            if (is_link($probe)) { return false; }
            if ($this->windows && file_exists($probe)) {
                $real = realpath($probe);
                $literal = str_replace('\\', '/', $probe);
                if (!is_string($real) || strcasecmp(rtrim(str_replace('\\', '/', $real), '/'), rtrim($literal, '/')) !== 0) { return false; }
            }
        }
        return true;
    }

    private function treeSafe(string $path): bool
    {
        if (!$this->safe($path)) { return false; }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..' && !$this->treeSafe($path . '/' . $name)) { return false; }
            }
        }
        return true;
    }

    private function assertTree(string $path): void
    {
        if (!$this->treeSafe($path)) { throw new \RuntimeException('目录含受保护路径、链接或 reparse point：' . $path); }
    }

    private function erase(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) { return; }
        $this->assertTree($path);
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') { $this->erase($path . '/' . $name); }
            }
            if (!rmdir($path)) { throw new \RuntimeException('目录未删除：' . $path); }
        } elseif (!unlink($path)) { throw new \RuntimeException('文件未删除：' . $path); }
    }

    private function children(string $path): array
    {
        if (!$this->safe($path) || !is_dir($path)) { return []; }
        $result = [];
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && (is_dir($path . '/' . $name) || is_link($path . '/' . $name))) { $result[] = $path . '/' . $name; }
        }
        return $result;
    }

    private function text(string $path): string
    {
        if (!is_file($path) || is_link($path) || filesize($path) > 262144) { return ''; }
        return (string) file_get_contents($path);
    }

    private function json(string $path): array
    {
        $data = json_decode($this->text($path), true);
        return is_array($data) ? $data : [];
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit((new Uninstaller())->run(array_slice($argv, 1)));
}
