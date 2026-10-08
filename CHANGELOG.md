# 更新日志

本文记录各版本的变更；正式发布状态以 GitHub Release 为准，已发布版本日期采用 Release 的 UTC 发布日期。

## [v0.4.1](https://github.com/supdger/webman-aot-builder/releases/tag/v0.4.1)

- Illuminate 时间间隔适配兼容 Laravel 12 的单位调用与 Laravel 13 的 `make()` 写法，以及已确认的等价语法；保留宏、浮点 setter 和分数行为，不改原 vendor。
- Webman、Workerman、Carbon 按源码结构与转换结果判断兼容，移除业务依赖版本上限与精确版本白名单。SaiAdmin profile 使用 Carbon 3.13.2、ThinkORM 3.0.34 的下限；Monolog 与相关 polyfill 继续核源码及输出摘要。
- 修复已确认 CarbonInterval 内部负小数符号切换的 AOT 差异；Laravel 12、13 各 75 个实际 ELF 输出与普通 PHP 基准一致。公开用户代码直接零参 `invert()` 的编译器限制仍保留，完整应用业务未因此宣称通过。
- Windows 构建镜像激活增加有界重试及系统原因，保留目标冲突和源码变化保护；原生 Windows 文件占用恢复仍需专项验证。
- 修复 DeepClone 适配将整份工具链锁摘要当作 SDK 身份的问题，改为核实际 SDK、派生证据和头文件；保留材料完整性校验。
- 修复编译器对含空格工具路径的错误引用，新增第 26 号补丁并纳入补丁清单。

## [v0.4.0](https://github.com/supdger/webman-aot-builder/releases/tag/v0.4.0)

- 原构建入口默认复用经过输入、大小和 SHA-256 校验的成功编译单元；未完成单元重新编译，每次重新链接并校验产物。
- 增加 `build --fresh` 与引导中的全量重建选择；正常重试保留有效进度。
- 各次构建写入独立目录，拒绝同项目并发构建和清理，防止残留编译进程污染下一次尝试。
- 修复工具链补丁应用器未纳入已有第 24 号补丁的问题，并增加第 25 号完整对象 checkpoint 补丁。
- Composer 入口、双平台编译组件、轻量/完整安装包和 setup 同版升级为0.4.0；Composer 完整运行时绑定来自实际新归档摘要。
- 组件由锁定0.3.2前驱逐文件校验派生，保留ABI不变的已配套032目标SDK；补丁与组件清单完整核验，不修改旧版本资产。
- Windows 原生 CI 通过实际安装、109 单元五轮恢复回归及整树强制中断后的14/109复用、95单元重编，并发安全拒绝与任务进程清理；用户Windows实机续编、PHP8.1原生链及目标Linux业务流程仍未验证，详细范围见Wiki。

## [v0.3.7](https://github.com/supdger/webman-aot-builder/releases/tag/v0.3.7)

- 普通 `composer global require supdger/webman-aot-builder` 首次接受 Composer 插件信任后，包安装与自动加载完成即进入组件和项目引导；升级、无变更重复安装均适用，不再需要组合启动命令。
- 只有交互终端中单独全局 require 本包自动启动；其他命令、局部项目、非交互、CI 和禁用插件/脚本不自动引导。Composer 2.5.3+ 的可选插件保持无人安装和普通 bin 使用，信任配置由 Composer 管理。
- 项目流程结果与包安装分别显示，后续安全审计照常执行。完整运行时仍为 0.3.2；本版只提供非 latest 轻量 Composer 包。

## [v0.3.6](https://github.com/supdger/webman-aot-builder/releases/tag/v0.3.6)

- Composer 入口无参数即可进入引导，选择自动准备或导入完整包，随后选项目、构建并自动校验；失败可重试或重选。
- 安装说明提供一次复制的安装与启动命令，自动找到 Composer 代理；帮助和交互版本查询显示下一步。
- 非交互无参仅显示启动方式，不隐式下载、安装或构建。完整运行时继续使用 0.3.2。

## [v0.3.5](https://github.com/supdger/webman-aot-builder/releases/tag/v0.3.5)

- 将 Composer 包归属更正为 `supdger/webman-aot-builder`，入口命名空间与状态所有权同步为 Supdger。SaiAdmin 是业务兼容生态，不代表该工具的包归属。
- 新入口可逐项清理已识别的旧 `saiadmin/webman-aot-builder` 状态和全局包；只卸载选中的精确包，不自动接管旧运行时。两包同用命令名，迁移先移除旧全局包再安装新包。
- 只提供 Composer 轻量 ZIP 和校验清单，运行时与 native latest 仍为 0.3.2，旧标签和资产保留。Windows 实机、PHP 8.1 验证仍未完成。

## [v0.3.4](https://github.com/supdger/webman-aot-builder/releases/tag/v0.3.4)

- Composer 入口新增 `uninstall` 与只读 `uninstall --list`，有限发现原生当前/历史版本、备份命令、PATH 入口、Composer 私有状态和全局包，显示类型、静态版本与路径，逐项默认保留确认；不运行旧命令探版本。
- 卸载当前或活动版本时撤销归属明确的公开命令，不恢复旧备份；保留项目源码、产物、共享组件与 PATH，结束重新列出残留。Composer 只移除精确的本全局包，禁用 scripts/plugins 并保留其他全局工具；状态锁保留原 inode，避免并发准备换锁。
- 修复 POSIX 路径反斜杠、末尾空格和引号被归一化后误选另一目录的风险；官方入口使用完整模板摘要识别，无证据对象保留。
- 本版仅提供 Composer 轻量 ZIP 与校验清单，继续使用固定的 0.3.2 完整运行时，不设为 latest，也不替换原生完整资产。源码中的新版原生卸载包装器未进入既有 0.3.2 完整包；Windows 原生脚本、PHP 8.1 验证仍待完成。
- 质量证据包括30项长期回归、独立路径/锁9项检查、真实Mac原生与Composer全局自卸载；这些不代表Linux业务运行或完整跨平台原生发行验收。

## [v0.3.2](https://github.com/supdger/webman-aot-builder/releases/tag/v0.3.2)

- 支持锁定的 Carbon 3.14.1，修复 SaiAdmin 项目因 Carbon 版本拒绝、生成配置选择或生成代码不兼容而中止构建的问题。
- 补齐 DeepClone 1.42.0 编译所需的闭包绑定、引用存储和反射行为，修复类被跳过及生成代码无法编译的问题；保留完整 DeepClone 适配，不通过排除依赖绕过失败。`Closure::call()` 仍不支持。
- 使用本项目从锁定官方输入重建的 PHPX 静态 SDK，记录来源、补丁、库与头文件摘要，并由编译组件和源码构建入口校验。新增衍生 SDK 素材供维护者重建；普通安装继续使用 setup。
- 本版需要更新安装包及对应编译组件，旧组件不能作为新版 SDK 使用。项目源码保持不变；编译与本机校验通过仍需另行完成 Linux 启动、数据库和业务验收。

## [v0.3.1](https://github.com/supdger/webman-aot-builder/releases/tag/v0.3.1)

- 修复 Windows 与 macOS 安装引导进入项目构建后，阶段和编译日志不能及时显示的问题。现在会持续显示准备阶段、编译器的逐文件进度与真实完成数；没有新输出时显示等待时间，完成或失败时保留耗时、原始错误和退出码。
- 增加锁定 `symfony/polyfill-deepclone v1.42.0` 对 PHP 8.4 静态目标的适配，修复其条件声明触发的 stray code 构建错误；适配只作用于隔离构建镜像，包版本或源码不匹配时拒绝应用。
- 下载说明逐项解释 setup、轻量包、完整包、编译组件、校验文件及 GitHub 自动源码归档的用途，明确首次安装的推荐入口。
- 构建进度修复已通过 Windows 和 macOS 的真实项目菜单、编译与产物校验；这不代表所有 SaiAdmin 依赖组合或 Linux 业务运行已通过验收。

## [v0.3.0](https://github.com/supdger/webman-aot-builder/releases/tag/v0.3.0)

- 新增一次启动的 CLI 引导流程：源码入口 `build.command` / `build.cmd` 自动准备材料和制作安装包；独立 setup 自动下载安装包。用户选择轻量或完整包、确认安装、选择项目目录后，工具自动完成校验、安装、新版本检查、项目构建及产物验证。
- 构建、下载和安装显示可理解的步骤与实际状态。成功显示结果及下一步，失败显示原因、恢复建议和本机日志位置；项目失败可选择重试、重选目录或结束。
- 修复 Windows 中文安装路径中的 PHP 配置和扩展加载问题，保留实际下载失败退出码；独立 Windows setup 使用 CRLF，避免 CMD 解析错误。
- 修复任意目录层级的 `.DS_Store` 导致源码快照和构建镜像误判的问题；真实源码变化仍会中止，并显示变化路径和重试建议。
- 安装和项目构建的验证针对开发机上的包身份、构建产物结构和完整性；Linux 部署及业务运行需另行验收。

## [v0.2.3](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.3) — 2026-09-28

- 修复 Windows Git 将 SaiAdmin 的 `support/bootstrap.php` 检出为 CRLF 时，构建器误判启动源码不兼容的问题。构建过程只规范化隔离构建镜像中的锁定源码，不改项目文件；真实内容差异仍会被拒绝。无需调整项目文件或全局 Git 设置。

## [v0.2.2](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.2) — 2026-09-27

- `webman-aot doctor` 可在任意目录运行，并自动下载、校验和准备编译组件；轻量包首次运行时联网准备，完整包可在安装时离线准备。
- `doctor --check` 和 `doctor --json` 仍可用于只检查状态，不触发自动准备。

## [v0.2.1](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.1) — 2026-09-27

- 安装后的命令改为简短的 `webman-aot`。升级后重新打开终端或 PowerShell，并运行 `webman-aot version` 确认使用的是新版本。
- 改进锁定编译器的路径引用与安全选择，减少宿主 `PATH` 或项目目录同名文件对编译器调用的干扰；该版验收也覆盖了带空格的安装路径。

## [v0.2.0](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.0) — 2026-09-27

- 产品更名为 Webman AOT Builder，安装后的命令为 `webman-aot-builder`；安装包和数据目录也随之更名。Linux 构建输出仍为 `dist-aot/`。v0.2.0 使用独立数据目录，不会读取或迁移 v0.1.3 的用户数据。
- v0.2.0 未提供可信的自更新清单和签名密钥；从 v0.1.3 升级时应下载并安装本版安装包，不要依赖旧版 `self-update`。
- 轻量包需联网准备组件，完整包内置锁定组件，可离线准备。

## [v0.1.3](https://github.com/supdger/webman-aot-builder/releases/tag/v0.1.3) — 2026-09-27

- 增加轻量与完整安装包。完整包内置精简编译组件，安装后无需再运行 `doctor --repair`；轻量包在首次 `doctor` 或 `build` 时获取组件。
- 两种包使用相同的按平台锁定组件，并逐文件校验；网络不便时可选择完整包。

## [v0.1.2](https://github.com/supdger/webman-aot-builder/releases/tag/v0.1.2) — 2026-09-26

- 修复 Windows 源码构建的安装包与 Release 包比较时的元数据误报。比较时可从参考包自动读取修订标识，无需手工查找或输入提交号。

## [v0.1.1](https://github.com/supdger/webman-aot-builder/releases/tag/v0.1.1) — 2026-09-26

- 改进首次准备工具链时的下载与进度提示；`doctor --repair` 会显示组件进度，安装器会自动校验并报告组件文件。
- Windows 源码构包流程可下载锁定输入、生成安装包并与已发布 ZIP 对照。
- 补齐初次安装、卸载和源码构包说明。

## [v0.1.0](https://github.com/supdger/webman-aot-builder/releases/tag/v0.1.0) — 2026-09-26

- 首个公开安装版，支持在 Apple Silicon Mac 或 Windows x64 开发机上构建 Linux amd64 musl 全静态程序。
- 提供 `doctor`、`build` 和 `verify` 命令；首次需准备锁定的编译组件，再构建并检查 `dist-aot/`。普通 Webman 项目无需安装 AOT Composer 插件。
- 支持范围和已验证依赖组合见该版 [Release 说明](https://github.com/supdger/webman-aot-builder/releases/tag/v0.1.0)。
