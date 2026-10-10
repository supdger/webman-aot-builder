<?php
declare(strict_types=1);

namespace Supdger\WebmanAotInstaller;

final class Archive
{
    /** @param array{filename:string,url:string,size:int,sha256:string} $package */
    public static function verify(string $path, array $package): void
    {
        if (!is_file($path) || is_link($path) || filesize($path) !== $package['size']
            || !hash_equals($package['sha256'], (string) hash_file('sha256', $path))) {
            throw new \RuntimeException('安装包大小或 SHA-256 不匹配。需要：' . $package['filename'] . '；组件资源 ZIP 不能代替完整安装包。');
        }
    }

    public static function extract(string $path, string $destination, string $host): void
    {
        if (file_exists($destination) || !mkdir($destination, 0700, true)) {
            throw new \RuntimeException('无法创建新的安全解包目录。');
        }
        if ($host === 'windows-x86_64') {
            $script = dirname(__DIR__) . '/resources/extract-windows.ps1';
            $code = Process::run(['powershell.exe', '-NoLogo', '-NoProfile', '-ExecutionPolicy', 'Bypass',
                '-File', $script, '-Archive', $path, '-Destination', $destination]);
        } else {
            $names = preg_split('/\r?\n/', trim(Process::output(['/usr/bin/tar', '-tzf', $path])));
            $seen = [];
            foreach ($names ?: [] as $name) {
                if (!preg_match('~^[A-Za-z0-9_.][A-Za-z0-9_./-]*$~D', $name)
                    || preg_match('~(^|/)\.\.?(/|$)~', $name) || isset($seen[$name])) {
                    throw new \RuntimeException('归档路径不安全或条目重复；未执行包内代码。');
                }
                $seen[$name] = true;
            }
            foreach (preg_split('/\r?\n/', trim(Process::output(['/usr/bin/tar', '-tvzf', $path]))) ?: [] as $entry) {
                if (!str_starts_with($entry, '-')) {
                    throw new \RuntimeException('归档包含链接或特殊文件；未执行包内代码。');
                }
            }
            $code = Process::run(['/usr/bin/tar', '-xzf', $path, '-C', $destination]);
        }
        if ($code !== 0) {
            throw new \RuntimeException('解包失败，退出码 ' . $code . '；没有安装。');
        }
    }

    public static function identity(string $directory, string $host, string $version, bool $complete = true): void
    {
        $file = $directory . '/package.json';
        $identity = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($identity) || ($identity['schema'] ?? '') !== 'webman-aot-builder-installer-package-v1'
            || ($identity['version'] ?? '') !== $version || ($identity['platform'] ?? '') !== $host
            || ($identity['flavor'] ?? '') !== ($complete ? 'complete' : 'small')
            || !is_file($directory . '/payload-manifest.sha256')
            || ($complete ? !is_file($directory . '/payload/minimal-toolchain/component.zip')
                : !is_file($directory . '/payload/app/toolchain/minimal-upgrade/minimal-component.json'))) {
            throw new \RuntimeException('完整安装包身份、平台或版本不匹配；没有执行安装器。');
        }
    }
}
