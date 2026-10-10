# Webman AOT Builder Composer 入口

通过 Composer 在同一终端选择准备组件、项目目录、构建和校验。支持 macOS Apple Silicon、Windows x64；入口 0.4.3 自动识别开发机，使用对应的 0.4.3 完整运行时。

0.4.4 入口要求 PHP 支持 match 等实际使用的 PHP 8 语法（manifest 为 >=8.0）；Composer 与插件 API 不设版本上下限，按实际公共接口、事件和调用能力检查。实际验证为 PHP 8.4.18/Composer 2.9.5/macOS，标签模拟不等于 Composer 3 或 PHP 8.0 原生已验证，Windows 真机仍未验收。私有 TypePHP 编译器取消 PHP 8.6 上限，按扩展、函数、反射、解析器 API 和语法能力判断；其源码使用 property hooks，必要语法为 PHP 8.4，与本 Composer 入口分别校验。此前候选的 Linux ELF 构建及结构/完整性 verify 属于历史验证；0.4.4 最终统一版本的原生产锁完整编译、Linux x64 静态链接及构建宿主结构/完整性验证已通过，Linux 原生应用运行、用户 Windows 实机与完整业务仍未验收。[0.4.3 Windows 原生 CI](https://github.com/supdger/webman-aot-builder/actions/runs/37933551293) 已通过两种包的构建、离线准备、隔离安装与版本自检，以及许可证、Intl 函数提供者、源码范围、生成覆盖、镜像恢复和工作区回归；0.4.1 的 CI 另有续编记录，范围见[测试与验证范围](https://github.com/supdger/webman-aot-builder/wiki/Verification)。运行 `composer global require "supdger/webman-aot-builder:*"`，首次接受 Composer 本身的插件信任询问后，包安装和自动加载完成即打开已有引导。包归属为 [supdger/webman-aot-builder（Packagist）](https://packagist.org/packages/supdger/webman-aot-builder)。

已安装时直接运行：

macOS 终端：

```sh
php "$(composer global config bin-dir --absolute)/webman-aot" guide
```

Windows PowerShell：

```powershell
$aotBin = (composer global config bin-dir --absolute).Trim()
if ($LASTEXITCODE -ne 0) { throw '无法读取 Composer 命令目录。' }
php (Join-Path $aotBin 'webman-aot') guide
```

直接 PHP 入口不受 `composer global exec` 默认 300 秒子进程超时限制，适合下载完整包及长时间交互；无需修改 Composer 全局超时。

选择开始会自动复用资源或准备当前开发机的完整包；也可导入已下载包、结束。准备成功后进入原有项目菜单，输入或拖入项目完整路径，构建成功自动校验并显示产物位置；失败可继续编译、重选目录、全量重建或结束。命令自动取得代理路径，无需修改 PATH 或先查版本。包安装与项目流程结果分别显示，Composer 后续安全审计照常执行。只有终端中单独全局 require 本包会自动引导；其他包、局部项目、其他 Composer 命令、`--no-plugins`、`--no-scripts`、`--dry-run`、`--no-update`、`--no-install`、非交互与 CI 不自动打开菜单。拒绝信任后仍可使用上方显式命令；插件不会自行更改信任配置。

已配置 Composer 命令目录的终端也可直接运行 `webman-aot`。非终端无参仅提示入口，不准备资源。显式 `guide` 只在确认为已连接控制台时恢复交互；`--non-interactive`、`COMPOSER_NO_INTERACTION=1` 或 `CI=1` 阻止恢复和隐式准备。

需要原构建参数的自动化调用仍可直接进入项目目录运行：

进入包含 `composer.json`、`composer.lock` 和 `start.php` 的 Webman 项目目录：

```sh
webman-aot build
# SaiAdmin 项目：
webman-aot build --profile=saiadmin
```

首次交互运行会下载、校验并安装对应平台的完整包，显示真实下载及安装进度；成功后在原项目目录执行原命令。私有 PHP 和工具链保存在独立 `webman-aot-composer` 用户数据目录，不覆盖原安装，不改系统 PATH。指定状态目录已有运行时或启动器而没有本入口所有权记录时会拒绝接管，需另选空目录。大型平台安装包不会放进 Composer 的 vendor 目录。

网络连续失败时，入口显示需要的**完整安装包文件名和下载链接**。下载后按回车检查常规 `Downloads` 目录，或将文件拖入终端输入完整路径。自定义下载目录无法保证自动找到；可明确指定：

```sh
webman-aot setup --archive="/完整包所在目录/对应完整安装包" --non-interactive
```

`components.zip` 只有编译资源，不能代替完整安装包。所有导入必须通过固定大小、SHA-256、平台和版本校验；不匹配的包不会执行。

非交互环境不会等待输入。首次准备可显式使用 `webman-aot setup --yes --non-interactive`，或前述本地包命令；资源已准备后直接运行构建。全局选项必须放在 `doctor`、`build` 等原命令之前；原命令后所有参数（含 `--`）原样转交构建器。`setup` 自身的选项可放在后面。`--state-dir=目录` 将运行时、缓存和启动器全部放在指定目录，适合隔离测试。`--help`、`--version` 不联网，只说明入口和目标版本，不表示构建器已经安装。

0.4.4 入口会在父 Composer 中仅为 `composer global exec -- webman-aot ...` 关闭本次进程超时，其他命令仍保留原限制。需要插件已启用且 Composer 提供相应事件接口；旧入口或禁用插件时继续使用前述直接 PHP 入口。终端读取故障会提示重新启动并失败结束；正常 EOF 安全取消，正常等待不设交互截止时间。

下载中可用 Ctrl+C 取消，未完成内容保存在状态目录的 `cache/*.part`。macOS 的引导父入口被 Composer 超时终止时，也会停止本次下载并保留进度；继续使用前述直接 PHP 入口重试。断网或下载失败会最多自动重试 2 次，每次从已有字节续传；再次选择开始或运行同一命令也会继续下载，不限总下载时长。只有连接超过 20 秒或连续 120 秒几乎没有数据才超时；服务器拒绝续传时会明确提示并重新下载。完整包通过大小与 SHA-256 校验后才解包，损坏内容会清除。准备失败时原项目命令不会运行；已完成包缓存也可复用。准备成功后会重新执行原命令。0.4.0 的项目构建默认复用输入一致且大小、SHA-256 校验通过的完整编译单元；未完成或损坏的单元从头编译。引导失败后选择 `1` 继续编译、`2` 重选目录、`3` 全量重建、`0` 结束；每轮仍重新链接并验证最终产物。

在项目根目录运行 `webman-aot build --fresh` 可让全部单元重新编译。对象缓存位于项目内 `.webman-aot-builder/cache/objects/`；失败 attempt 与缓存保留并占用磁盘，成功后清理本轮 attempt。`--fresh` 不清旧缓存或失败目录。Windows 原生 CI 已有续编记录；用户 Windows 实机和 PHP 8.1 原生续编仍未验收。

0.4.4保留普通 PHP `toArray()` 的原签名及动态返回，由编译器适配，不要求手改原项目或 `vendor/`。符合已验证 Composer 惰性加载条件的可选声明延后到实际请求，实际请求保留原始加载错误；不适用的旧转换模板也交由 TypePHP 判断。本轮原生产锁完整编译及构建宿主结构/完整性验证通过，目标 Linux 原生应用与业务未验，发布状态见 [0.4.4 Release](https://github.com/supdger/webman-aot-builder/releases/tag/v0.4.4)，材料与输出完整性保持校验。

构建器详细安装、兼容性与 Linux 部署要求见[现有 Wiki](https://github.com/supdger/webman-aot-builder/wiki)。旧安装不会自动获得 0.4.3 修复。Packagist 登记状态以[包页面](https://packagist.org/packages/supdger/webman-aot-builder)为准；没有自动镜像切换，网络不可用时使用已校验的本地完整包。

0.4.3 Composer 入口与配套完整运行时同为 0.4.3；旧入口 0.3.7 仍固定 0.3.2。根 `composer.json` 注册元数据的 bin/autoload 路径与小 ZIP 保持同样的 `packages/composer-installer/` 布局。普通 Composer 安装取得轻量 Release ZIP，`--prefer-source` 会下载完整源码仓库。

升级或修复丢失的 Composer 入口，始终使用同一条命令，无需先卸载：

```sh
composer global require "supdger/webman-aot-builder:*" --no-scripts
```

它恢复全局依赖记录、解除本包旧的固定版本约束，按当前环境和全局稳定性设置解析兼容版本（默认稳定版）；不自动打开项目菜单或下载运行时。不要用单独的 `global update` 修复丢失的依赖记录，否则 Composer 可能移除未被全局配置记录的旧入口。首次插件信任仍由 Composer 询问。

入口更新成功后，再查询入口与目标运行时版本，进入现有资源菜单：

```sh
composer global exec -- webman-aot --version
```

macOS 终端：

```sh
php "$(composer global config bin-dir --absolute)/webman-aot" guide
```

Windows PowerShell：

```powershell
$aotBin = (composer global config bin-dir --absolute).Trim()
if ($LASTEXITCODE -ne 0) { throw '无法读取 Composer 命令目录。' }
php (Join-Path $aotBin 'webman-aot') guide
```

选择“开始”准备该入口对应的运行时；已就绪则复用。升级时先校验完整包缓存和已下载完成的 `.part`，通过校验就直接使用；没有可用完整包时，若发行包支持轻量升级，会校验旧组件并复用符合新版本要求的文件，只下载程序和必要替换文件。组件缺失或校验不通过会显示原因，再取得完整包。新环境通过自检后才完成切换；失败会恢复旧环境，若恢复本身失败则停止并显示保留的备份位置。只更新入口不表示运行环境已升级，选择结束可暂不升级运行环境；升级不重建项目产物。详细步骤见[升级与卸载](https://github.com/supdger/webman-aot-builder/wiki/Upgrade-Uninstall)。

已安装的当前 Composer 入口可以直接卸载，不需要进入源码或项目目录，也不需要先安装或升级。macOS 从任意目录运行：

```sh
"$(composer global config bin-dir --absolute)/webman-aot" uninstall
```

命令先列出已识别对象的名称、版本、路径、用途和卸载影响，再逐个询问 `y/N/q`。输入 `y` 仅卸载当前询问的这一项；回车或 `n` 保留，`q` 结束，输入结束时保留未确认项。加 `--list` 只查看列表，不会询问或卸载。没有归属证据的对象显示保留原因，不要求确认。

旧 0.3.3 和原生 0.3.2 没有此命令；只有使用这些旧入口时，才需要另备支持逐项卸载的入口。

清理旧安装后改用 Composer：

```powershell
composer global require "supdger/webman-aot-builder:*" --no-scripts
if ($LASTEXITCODE -ne 0) { throw 'Composer 安装失败，未开始卸载。' }
$aotBin = (composer global config bin-dir --absolute).Trim()
& (Join-Path $aotBin 'webman-aot.bat') uninstall --list
& (Join-Path $aotBin 'webman-aot.bat') uninstall
& (Join-Path $aotBin 'webman-aot.bat') uninstall --list
& (Join-Path $aotBin 'webman-aot.bat') --version
```

此清理流程用 `--no-scripts` 先跳过自动项目菜单。按列表对旧原生版本、旧公开命令和历史备份选择 `y`；对“Composer 全局包（supdger/webman-aot-builder）”选择 `n` 保留新入口。需要清空已有 Composer 私有运行时以重新准备时，单独确认该状态项；安装锁与用户文件保留。若也选了卸载 Composer 全局包，需再次运行首行安装命令，然后重新取得命令目录。清理后在同一 PowerShell 窗口运行 `php (Join-Path $aotBin 'webman-aot') guide`，按同一菜单准备资源并选择项目。上述参数不修改用户 PATH，旧空 PATH 目录可另行核对整理。

先只读查看类型、静态版本和绝对路径，再逐项输入 `y` 卸载、回车保留或 `q` 结束；非交互环境始终保留。入口不会先下载或安装运行时。自定义状态目录使用 `webman-aot uninstall --state-dir="目录"`；自定义原生目录可用 `--home="目录"`、命令目录用 `--bin-dir="目录"`。仅清理可确认归属的选中对象，未知旧入口保留并显示精确路径；Composer 全局包由 Composer 移除这一包，其他全局工具保留。Composer 状态根的 `setup.lock` 与额外用户文件保留，避免并发安装换锁；项目产物、独立安装根的共享工具链与 PATH 保留；卸载 Composer 私有运行时会同时移除其内部工具链、日志及缓存，之后构建需重新准备。旧备份命令不会恢复；失败返回非零并列出残留。

开发检查（在原仓库根运行）：

```sh
composer validate --strict
php packages/composer-installer/tests/run.php
php packages/composer-installer/tests/guide.php
php packages/composer-installer/tests/uninstall.php
# 实际 macOS Composer PTY：先制作候选 ZIP，再传入其绝对路径；旧版 ZIP 可选。
python3 packages/composer-installer/tests/plugin.py /候选包绝对路径.zip /旧0.3.6入口包.zip
```

本地验收不需要发布。先建立一个临时 Composer 工作目录，在该目录创建 `composer.json`，其中 `url` 改成此包的绝对目录：

```json
{
  "repositories": [{"type": "path", "url": "/原仓库绝对路径", "options": {"symlink": false}}],
  "require": {"supdger/webman-aot-builder": "@dev"}
}
```

在临时目录运行 `composer install --no-plugins --no-scripts`，再执行 `vendor/bin/webman-aot --help`。准备与后续验证也使用同一个显式独立目录：

```sh
vendor/bin/webman-aot setup --state-dir="/临时目录/aot-state" --archive="/完整包路径" --non-interactive
vendor/bin/webman-aot --state-dir="/临时目录/aot-state" --non-interactive doctor
```

构建时进入自己的项目目录，以临时工作目录下 `vendor/bin/webman-aot` 的完整路径执行 `--state-dir="/临时目录/aot-state" --non-interactive build`。Windows 的 Composer 会生成对应 `.bat` 代理，使用该代理运行。以上用于验证本地修改；这套本地检查验证修改后的包布局与命令行为，公开发行与注册信息以 Release 和 Packagist 页面为准。
