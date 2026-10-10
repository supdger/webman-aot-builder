## Why

用户安装 Composer 包后只有入口版本信息，还需手动找代理位置、准备组件和猜下一步。首次使用应在一个入口完成准备并进入已有的项目构建菜单。

## What Changes

- 终端无参数或 `guide` / `start` 打开首次使用引导，选择自动准备、本地完整包或结束。
- 校验资源、隔离安装后调用固定 0.3.2 运行时已有项目菜单，沿用选项目、构建、校验和失败重试。
- 非终端无参不下载、不写状态；help/version 给明确下一步。
- 安装示例合并 require 成功与 `composer global exec -- webman-aot`，无需用户查 bin 路径。

## Capabilities

### New Capabilities

- `composer-first-use-guide`: Composer 首次使用到项目构建的连续引导与安全取消。

### Modified Capabilities

无。

## Impact

仅 Composer Installer、行为测试、轻量包元数据和受影响 README/Wiki。原生运行时保持 0.3.2，不重建完整包，不改变真实 PATH、已存在安装、项目源码或数据库。

## 父 Composer 超时与输入恢复接续

仅对已启用插件、具备实际命令事件能力的父 Composer global exec 且 binary 精确为 webman-aot 关闭本次进程超时，不改全局配置或其他命令。终端输入故障明确失败结束；正常 EOF 安全结束，开放管道与半行持续等待，不设置交互截止时间。macOS 保留恢复控制台的父 FD3 生命周期；原项目、运行时与用户 HOME 不改。
