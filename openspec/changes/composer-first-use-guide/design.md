## Context

基线 main 为 981b91f，Composer 0.3.5 默认无参输出 help。实际 0.3.2 完整包和 tag 都包含 `tools/guided.php` 及 `src/Guided/Flow.php`；`--mode=project` 可以直接使用已经准备的私有 home/bin。

## Goals / Non-Goals

**Goals:** 开发者复制一次安装与启动命令，选择准备方式，在同一终端进入已有构建菜单；已安装者运行一个入口即可。原生命令及机器输出继续可用。

**Non-Goals:** 不写 Composer 插件或信任钩子，不声称依赖包 post-install 脚本会执行，不新建第二套项目菜单，不改原生版本和用户 PATH。

## Decisions

- 在 TTY 无参、guide/start 时先询问开始、导入或结束；取消和 EOF 在建立状态前返回。非 TTY 只提示实际入口，不隐式安装。
- 复用 Installer 的完整包下载、缓存、SHA/大小/平台校验及隔离安装，再以私有 PHP 启动 `tools/guided.php --mode=project --home=私有runtime --bin-dir=私有bin --no-path`。原生 Flow 保持不变。
- `composer global exec -- webman-aot guide` 使用官方 ExecCommand。本机 Composer 2.9.5 先读 global bin 配置，再恢复 initialWorkingDirectory，并把 bin 放在临时进程 PATH 前面；实际隔离 Composer 测试必须证明原 cwd 和旧 PATH 命令不会被误调用。
- 安装命令的 shell 顺序保证 require 成功才执行统一的 global exec guide；Windows PowerShell 5.1 用 `$LASTEXITCODE` 分支，macOS 用 `&&`，程序处理资源平台和控制台差异。无需用户查 bin 或先查版本。

- Windows 官方 Composer/Symfony 子进程使用管道；仅显式 guide/start 且没有无人标志时，以裸 CONIN$/CONOUT$（rb / r+b）确认双 stream_isatty 后重启固定本包入口，用资源描述符和数组 argv 继承控制台。Mac 同样用 /dev/tty。depth=1 环境标志防循环，无参管道和 --non-interactive/CI/COMPOSER_NO_INTERACTION 不恢复。官方 PHP 8.4.26 保留裸设备名 BC，且 stream_isatty 的 Windows 实现调用 GetConsoleMode；不使用 namespace 绕路。源码依据：https://github.com/php/php-src/blob/php-8.4.26/win32/ioutil.c#L149 和 https://github.com/php/php-src/blob/php-8.4.26/ext/standard/streamsfuncs.c#L1705 。

## Risks / Trade-offs

- [首次资源约 264MB/341MB] → 展示系统、大小、真实进度和本地导入，支持取消并复用缓存。
- [Windows 物理环境本轮不可导出材料] → 明示平台实跑缺口，参数沿用已存在 Windows 私有 bootstrap，不冒称实机通过。
- [既有代理冲突] → 推荐 Composer 官方 exec 入口，真实临时 global 验证优先级；不修改永久 PATH。
- [编译耗时和业务边界] → 复用原 Flow 的日志、失败退出、重选及实际校验范围；不以菜单通过冒称完整业务验收。

## 父事件和现读取边界

PRE_COMMAND_RUN 在父 Composer 中执行，先验证事件能力，再限定 global、exec、binary 精确 webman-aot，不能用子进程环境变量冒充关闭父计时器。旧 Composer 能力或禁用插件保持直接 PHP 代理恢复入口。

Console 沿用 STDIN 与父 FD3 的 stream_select/前后 assertParent；1 秒周期仅用于临时非阻塞探测，finally 恢复阻塞模式。false 且非 EOF 继续等待，部分行累积到换行或 EOF；自身 fgets WARNING/NOTICE 产生终端断开错误。Flow 沿用其读取边界，正常 EOF 和无数据安全取消。两处只捕获自身 fgets 错误，不吞无关告警，finally 恢复先前处理器。Installer 和 Process 保留组件复用及下载父寿命逻辑。
