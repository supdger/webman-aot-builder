<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

/** Held across generation, compilation and publication; never unlink a live lock. */
final class ProjectBuildLease
{
    private mixed $handle = null;

    public function __construct(string $project)
    {
        $root = $project . '/.webman-aot-builder';
        if (is_link($root) || (file_exists($root) && !is_dir($root))) {
            throw new ConfigurationException('project workspace is unsafe');
        }
        if (!is_dir($root) && !mkdir($root, 0700) && !is_dir($root)) {
            throw new ConfigurationException('cannot create project workspace');
        }
        $path = $root . '/build.lock';
        if (is_link($path)) {
            throw new ConfigurationException('project build lock cannot be a symlink');
        }
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new ConfigurationException('cannot open project build lock');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new ConfigurationException('此项目正在构建；请等待当前构建结束后重试。已有编译进度已保留。');
        }
        $this->handle = $handle;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}
