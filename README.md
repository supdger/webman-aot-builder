# Webman AOT Builder

[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Webman AOT Builder 将 Webman / SaiAdmin 项目编译成 Linux amd64 全静态程序。
在 macOS Apple Silicon 或 Windows x64 开发机上构建，再把生成的整个 `dist-aot/` 部署到 Linux；目标机无需安装 PHP。

构建器在项目副本中做兼容适配，通过 TypePHP、Clang 和 PHPx 静态 SDK 生成可执行程序。
原项目源码保持不变，配置、模板和静态资源按需保留为外置文件。

![Webman AOT Builder 构建流程](https://raw.githubusercontent.com/wiki/supdger/webman-aot-builder/assets/build-flow.svg)

当前公开包用于开发验证。安装、构建与产物校验的记录见下方示例；Linux 数据库、登录等完整业务仍需在自己的目标环境验收。

## 安装与开始使用

只安装工具时可以暂不选择项目。要构建时，先按项目要求执行 `composer install`，准备 `vendor/`，再选择含 `composer.json`、`composer.lock`、`start.php`、`app/` 的后端根目录。

### 方式一：Composer

开发机需系统 PHP 和 Composer。包的 PHP 声明下限为 8.0；入口另按实际使用的 PHP 语法与 Composer 公共接口能力检查。平台及环境要求见[兼容说明](https://github.com/supdger/webman-aot-builder/wiki/Compatibility)。

在 macOS 终端或 Windows PowerShell 运行：

```sh
composer global require "supdger/webman-aot-builder:*"
```

首次 Composer 询问插件信任时输入 `y`，随后自动进入引导。选开始或导入完整包，资源准备完成后选择构建项目，输入或拖入后端根目录。下载中断会保留进度；网络受阻时按入口显示的文件名和链接取得匹配的完整包，再选择导入。

下次使用：

```sh
composer global exec -- webman-aot guide
```

### 方式二：原生安装包

从[最新正式版](https://github.com/supdger/webman-aot-builder/releases/latest)取得与你的**开发机**匹配的 setup：

- macOS Apple Silicon：下载名称以 `macos-arm64-setup.zip` 结尾的包，解压后运行 `.command`。
- Windows x64：下载名称以 `windows-x86_64-setup.cmd` 结尾的入口，运行 `.cmd`。

按菜单选轻量包或完整包，核对安装位置与 PATH 影响并确认安装。轻量包首次构建需联网准备组件；完整包包含编译组件，项目依赖仍需另行准备。安装后可选择构建项目，或结束后在项目根目录运行 `webman-aot build`。

两平台的启动命令、入口核对和完整安装步骤见[安装指南](https://github.com/supdger/webman-aot-builder/wiki/Install)。macOS 首次打开被拦截时，按该页说明核对来源和校验值，再完成系统确认。

### 方式三：从源码制作安装包

从[最新正式版](https://github.com/supdger/webman-aot-builder/releases/latest)下载 **Source code (zip)**，完整解压后进入含 `build.command`、`build.cmd` 的工具源码根目录。Mac 运行 `sh ./build.command`，Windows PowerShell 运行 `.\build.cmd`。

入口准备并核验材料、制作和校验安装包，确认安装后可选择项目。源码构包成功与项目构建成功分别报告；所需环境及操作见[源码构建指南](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source)。

## 构建与维护

在自己的项目根目录运行：

```sh
webman-aot build
webman-aot verify
```

SaiAdmin 项目使用 `webman-aot build --profile=saiadmin`。适用源码形态、依赖能力和编译器限制见[兼容说明](https://github.com/supdger/webman-aot-builder/wiki/Compatibility)及[SaiAdmin 指南](https://github.com/supdger/webman-aot-builder/wiki/Build-SaiAdmin)。

构建会显示当前阶段和真实编译计数；没有新输出时显示进程状态和已等待时间。失败保留原始原因、本机日志位置及恢复建议。重试默认复用输入与完整性校验通过的已完成单元，其余单元重新编译；每次仍重新链接并校验产物。`webman-aot build --fresh` 全量重编，不清空旧缓存。详细规则见[构建指南](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source)。

升级和清理使用[升级与卸载指南](https://github.com/supdger/webman-aot-builder/wiki/Upgrade-Uninstall)。当前入口能查询旧安装，确认归属的项目可逐项删除；未知归属残留会保留，不能承诺所有旧版都清干净。项目源码、产物、共享工具链、日志和 PATH 保留。

## 构建结果与部署

```text
dist-aot/
├── server          # Linux amd64 全静态程序
├── start.sh        # 启动脚本
├── stop.sh         # 停止脚本
├── config/         # 外置配置
├── …               # 项目需要的模板、静态资源等
└── manifest.json   # 分发清单
```

把**整个 `dist-aot/`** 复制到 Linux amd64 目标机，按项目需要配置 `.env`、数据库等环境，再在产物目录运行：

```sh
./start.sh
```

后台运行用 `./start.sh --daemon`，停止用 `./stop.sh`。按[Linux 部署与验收](https://github.com/supdger/webman-aot-builder/wiki/Linux-Acceptance)检查启动及业务；本机构建校验不代替目标机验收。

## 实际操作记录

### Windows：构建并校验 Webman 项目

用户提供的 PowerShell 记录中，首次构建失败后选择继续编译，随后完成 216 个文件的编译和产物校验。以下选取成功阶段的真实输出，省略重复进度、机器路径及长编译命令；产物位置已脱敏为项目相对路径。

```text
Successfully compiled 216 files
[成功] 构建项目（自动恢复已完成单元），耗时 119.0 秒，退出码 0
[成功] 校验本次项目产物，耗时 0.9 秒，退出码 0
实际校验范围：本机构建产物结构和完整性
项目构建与校验成功。
产物位置：dist-aot/
```

这次成功记录证明 Windows 构建及本机产物校验，不代表 Linux 部署或业务运行验收。

### macOS：构建并校验 SaiAdmin 项目

用户实机记录展示了构建完成、产物校验及 `dist-aot/` 位置。下图保留操作结果；它代表这次项目的本机验证。

![macOS 实机构建与本机产物校验成功](docs/images/macos-build-success.png)

已有用户记录也报告产物在 Linux 启动成功，但 HTTP、数据库和业务接口仍未验收。Linux 启动记录、其他平台与续编的测试范围见[测试与验证记录](https://github.com/supdger/webman-aot-builder/wiki/Verification)。

## 文档与反馈

- [Wiki](https://github.com/supdger/webman-aot-builder/wiki/Home)：安装、构建、维护与部署指南
- [更新日志](https://github.com/supdger/webman-aot-builder/blob/main/CHANGELOG.md)：历史功能、修复与升级影响
- [Issues](https://github.com/supdger/webman-aot-builder/issues)：问题与建议

原创代码采用 [MIT 许可证](LICENSE)，第三方组件的许可与归属见 [NOTICE](NOTICE.md)。
