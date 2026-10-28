# Webman AOT Builder

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE) [![Latest release](https://img.shields.io/github/v/release/supdger/webman-aot-builder)](https://github.com/supdger/webman-aot-builder/releases/latest) [![Downloads](https://img.shields.io/github/downloads/supdger/webman-aot-builder/total)](https://github.com/supdger/webman-aot-builder/releases) [![GitHub stars](https://img.shields.io/github/stars/supdger/webman-aot-builder)](https://github.com/supdger/webman-aot-builder)

[English](README.md) | [简体中文](README.zh-CN.md)

Webman AOT Builder 将 PHP 项目编译成 Linux amd64 全静态程序，原始 PHP 源码直接交给 TypePHP。
在 macOS Apple Silicon 或 Windows x64 开发机上安装工具、构建项目，再将生成的 `dist-aot/` 部署到 Linux，目标机无需安装 PHP。

## 实现方式

构建器枚举可读 PHP 源码，在隔离副本中保持每份 PHP 文件原样交给 TypePHP，再用 Clang 和 PHPx 静态 SDK 生成可执行程序。编译成功返回产物，失败保留 TypePHP 的原始编译错误。
原项目源码保持不变，配置、模板和静态资源按需保留为外置文件。

开发源码中的编译器兼容维护保留运行时条件、加载顺序及多文件声明。[原生回归入口](tests/native-declarations.php) 使用最小夹具与同 SDK 版本 PHP 对照原始输出、错误及退出码。这些变更尚未发布；最终冻结全套与完整项目构建仍在验证中。

## 源码与安装包

本仓库是构建工具的源码，包含命令行程序、源码输入组织、编译组件管理和安装包制作脚本。
开发或自行制作安装包可从 [最新开发源码](https://github.com/supdger/webman-aot-builder/tree/main) 开始，
具体步骤见 [源码构建指南](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source)。

维护者可用 `tools/build-minimal-component.php --compiler-source-root=PATH` 从完整且已核验的编译器源码组合组件，复用已准备的 SDK。开发源码入口校验来源、完整 Composer 权威映射及构建器现有的完整静态运行时声明契约；所需输入见源码构建指南。组件校验不能替代安装、完整项目构建或原生全套回归验收。

直接使用请下载 [最新正式版安装包](https://github.com/supdger/webman-aot-builder/releases/latest)，
选择与你的**开发机**匹配的 macOS Apple Silicon 或 Windows x64 包。
轻量包首次使用时联网准备编译组件；完整包包含组件，可在安装时离线准备。
两种包安装后都提供 `webman-aot` 命令。GitHub 的 `Source code (zip)` 是源码压缩包，不是安装包。
Windows 无法下载组件时，可直接下载名称含 `full-windows-x86_64.zip` 的完整安装包，全部解压后运行 `install.cmd`；`components.zip` 只是组件资源，没有安装入口。步骤见[Windows 完整包安装](https://github.com/supdger/webman-aot-builder/wiki/Install-Windows#windows-完整包安装)。

## 安装与使用

下载、校验和安装请按 [安装与使用指南](https://github.com/supdger/webman-aot-builder/wiki/Install) 操作。
安装后可在任意目录运行以下命令，检查开发机并准备缺失的编译组件，健康时输出 `Result: healthy`：

```sh
webman-aot doctor
```

构建时进入**自己的 PHP 项目目录**，运行：

```sh
webman-aot build
webman-aot verify
```

`build` 生成 `dist-aot/`，`verify` 检查该目录的分发结构和静态属性。
编译只需要以下四个前置条件：

1. 能读取待编译的 PHP 源码。
2. TypePHP 编译器和目标平台工具链可用。
3. 输出目录可写。
4. 要生成可执行程序时，有明确入口；编译单个文件或库时按对应模式处理。

可执行程序的入口由输入源码提供，并满足 TypePHP 的入口要求，例如全局 `main(): void`；入口和语言特性是否符合要求由 TypePHP 判定。构建器不识别框架、不检查依赖版本、不审查业务代码、不生成或改写 PHP 源码，不设置项目、框架、插件、目录布局或业务运行门槛。

## 构建结果

产物结构示意（运行资源随项目而异）：

```text
dist-aot/
├── server          # Linux amd64 全静态程序
├── start.sh        # 启动脚本
├── stop.sh         # 停止脚本
├── config/         # 外置配置
├── …               # 项目所需的模板、静态资源等
└── manifest.json   # 分发清单
```

外置资源文件应保留相对于程序的原目录布局。原生回归仅挂载程序、运行配置和显式声明的非 PHP 资源，运行容器中没有已编译的 PHP 源码。

把**整个 `dist-aot/`** 复制到 Linux amd64 目标机，按项目需要配置 `.env`、数据库等运行环境后，
在该目录启动：

```sh
./start.sh
```

后台运行用 `./start.sh --daemon`，停止用 `./stop.sh`。
目标机配置和启动后的业务检查见 [Linux 部署与验收](https://github.com/supdger/webman-aot-builder/wiki/Linux-Acceptance)。

## 实机构建与启动验证

以下是 v0.3.2 适配流程的历史记录，不代表当前原始 PHP 源码直通流程的 Windows 编译与链接结果。

2026-09-30，用户在 macOS Apple Silicon 和 Windows x64 实机构建 SaiAdmin 项目，并分别将产物复制到 Linux x86_64 服务器启动。以下为用户提供的终端关键输出摘录；macOS 和 Windows 是构建宿主，生成的程序面向 Linux。

### macOS Apple Silicon：构建并在 Linux 启动

使用 v0.3.2 轻量包安装并准备编译组件，构建 SaiAdmin 项目。以下保留安装、组件准备、编译及产物校验的关键节点，省略重复进度行：

```text
Package contents SHA-256 verified.
[成功] 校验包并安装，耗时 0.8 秒，退出码 0
webman-aot 0.3.2
[prepare] Extracted 7614/7614 verified entries
[prepare] Minimal component SHA-256 verification complete
[prepare] Minimal toolchain activated and ready.
[2063/2063] 100% extension-webman_server.cc
Successfully compiled 2063 files
[build] Compiler process completed in 529.8s; exit code 0.
[成功] 构建项目，耗时 582.3 秒，退出码 0
[成功] 校验本次项目产物，耗时 0.8 秒，退出码 0
项目构建与校验成功。
```

将这次 Mac 构建的产物复制到 Linux x86_64 服务器，在产物目录执行 `./start.sh`，报告 8 个 worker 启动成功（进程用户名已脱敏）：

```text
Workerman[main.php] start in DEBUG mode
Workerman/5.2.2         PHP/8.4.25 (JIT off)          Linux/5.10.134-16.3.al8.x86_64
event-loop  proto       user        worker      listen                 count       state
select      tcp         <用户>      webman      http://0.0.0.0:8788    8            [OK]
Press Ctrl+C to stop. Start success.
```

### Windows x64：构建并在 CentOS 7 启动

使用 v0.3.2 Windows 完整包构建 SaiAdmin 项目。现有日志保留了编译尾段、构建完成和产物校验，构建总耗时约 35 分 43 秒：

```text
[1920/2063] 94% vendor\symfony\console\Formatter\OutputFormatterStyleStack.cc
[2063/2063] 100% extension-webman_server.cc
Successfully compiled 2063 files
[build] Compiler process completed in 2083.7s; exit code 0.
[成功] 构建项目，耗时 2142.9 秒，退出码 0
[成功] 校验本次项目产物，耗时 1.3 秒，退出码 0
实际校验范围：本机构建产物结构和完整性
项目构建与校验成功。
```

将这次 Windows 构建的产物复制到另一台 CentOS 7 服务器，在产物目录执行 `./start.sh`，报告 4 个 worker 启动成功（进程用户名已脱敏）：

```text
Workerman[main.php] start in DEBUG mode
Workerman/5.2.2         PHP/8.4.25 (JIT off)          Linux/3.10.0-1160.95.1.el7.x86_64
event-loop  proto       user        worker      listen                 count       state
select      tcp         <用户>      webman      http://0.0.0.0:1717    4            [OK]
Press Ctrl+C to stop. Start success.
```

两台开发机的构建与产物校验均成功，两个 Linux 目标环境均报告服务启动成功；Mac 安装过程另有成功记录。启动仍有兼容性告警，HTTP、数据库和业务接口尚未验收。详细过程见 [实机测试记录](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source#v032-实机测试记录)。

## 文档

- [Wiki](https://github.com/supdger/webman-aot-builder/wiki/Home)：安装、使用与维护文档
- [更新日志](https://github.com/supdger/webman-aot-builder/blob/main/CHANGELOG.md)：功能、修复与升级影响

## 许可与反馈

原创代码采用 [MIT 许可证](LICENSE)，第三方组件的许可与归属见 [NOTICE](NOTICE.md)。
问题与建议请提交到 [Issues](https://github.com/supdger/webman-aot-builder/issues)。
