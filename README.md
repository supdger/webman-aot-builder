# Webman AOT Builder

[![PHP (Composer): >=8.0](https://img.shields.io/badge/PHP%20%28Composer%29-%3E%3D8.0-8892BF.svg?logo=php)](#option-1-composer) [![Compiler: TypePHP](https://img.shields.io/badge/Compiler-TypePHP-2E9E5B.svg)](https://github.com/swoole/typephp) [![Build host: macOS ARM64 / Windows x64](https://img.shields.io/badge/Build%20host-macOS%20ARM64%20%2F%20Windows%20x64-2496ED.svg)](https://github.com/supdger/webman-aot-builder/wiki/Install) [![Target: Linux amd64](https://img.shields.io/badge/Target-Linux%20amd64-FCC624.svg?logo=linux)](https://github.com/supdger/webman-aot-builder/wiki/Linux-Acceptance) [![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

[English](README.md) | [简体中文](README.zh-CN.md)

Webman AOT Builder compiles Webman / SaiAdmin projects into fully static Linux amd64 executables.
Build on a macOS Apple Silicon or Windows x64 development machine, then deploy the entire generated `dist-aot/` directory to Linux. The target machine does not need PHP installed.

The builder applies compatibility adjustments to a copy of your project and generates an executable using TypePHP, Clang, and the PHPx static SDK.
Your original source code stays unchanged. Configuration, templates, and static assets remain external files as needed.

0.4.6 fixes temporary workspace cleanup and process cleanup for the current task; persistent external locks still cause an explicit failure. Its compatibility fixes preserve ordinary PHP method and call contracts and correct the verified transformations of switch, Goto/finally, and local values in catch blocks. Full compilation with the original production lock, Linux x64 static linking, and build-host structure and integrity checks passed in that round. Native Linux application and business checks remain unverified; successful compilation is not guaranteed for every project. See [AOT adaptation](https://github.com/supdger/webman-aot-builder/wiki/Adaptation) for compatibility scope and limits, and the [latest release](https://github.com/supdger/webman-aot-builder/releases/latest) for release and installation status.
The current release preserves the original signature, arguments, and dynamic return contract of ordinary PHP `toArray()` methods, leaving them to the compiler; no manual changes to your project or `vendor/` are required. Old transformation templates that do not apply are left unchanged. Unused optional declarations confirmed to meet Composer lazy-loading conditions are deferred; actual requests retain PHP's original loading errors. Tool materials and input/output integrity remain checked. See the [test and verification records](https://github.com/supdger/webman-aot-builder/wiki/Verification) for the actual scope of source builds, targeted checks inside both platform packages, and public consumption checks.

![Webman AOT Builder build workflow](https://raw.githubusercontent.com/wiki/supdger/webman-aot-builder/assets/build-flow-en.svg)

The current public packages are intended for development validation. Examples below record installation, builds, and artifact verification; full Linux business flows, including databases and login, still need acceptance checks in your own target environment.

## Installation and getting started

To build your own Webman / SaiAdmin project, use the [native setup](#option-2-native-installation-packages) or [Composer entry point](#option-1-composer). You do not need to download this repository. GitHub's **Code → Download ZIP** and **Source code** archives are for developers building the tool's installation packages themselves.

You can install the tool without choosing a project yet. Before building, run `composer install` as required by your project to prepare `vendor/`, then select the backend root containing `composer.json`, `composer.lock`, `start.php`, and `app/`.

### Option 1: Composer

Your development machine needs system PHP and Composer. The package declares PHP 8.0 as its minimum; the entry point also checks the PHP syntax and public Composer interface capabilities actually used. See [compatibility requirements](https://github.com/supdger/webman-aot-builder/wiki/Compatibility) for platform and environment requirements.

Run in a macOS terminal or Windows PowerShell:

```sh
composer global require "supdger/webman-aot-builder:*"
```

When Composer first asks whether to trust the plugin, enter `y`; the guide then opens automatically. Select the start option or import a full package. Once resources are ready, select the build-project option and enter or drag in the backend root directory. Interrupted downloads retain progress. If network access is blocked, obtain the matching full package using the filename and link shown by the entry point, then select the import option.

For subsequent use:

macOS terminal:

```sh
php "$(composer global config bin-dir --absolute)/webman-aot" guide
```

Windows PowerShell:

```powershell
$aotBin = (composer global config bin-dir --absolute).Trim()
if ($LASTEXITCODE -ne 0) { throw '无法读取 Composer 命令目录。' }
php (Join-Path $aotBin 'webman-aot') guide
```

Use the same command to upgrade or repair a missing Composer entry point; no uninstall is needed first:

```sh
composer global require "supdger/webman-aot-builder:*" --no-scripts
```

This restores the global dependency record, removes this package's old fixed-version constraint, and resolves a compatible version using the current environment and global stability settings (stable by default). It does not automatically open the project menu or download the runtime. Do not use `global update` alone to repair a missing dependency record: Composer may remove an old entry point that is no longer recorded in the global configuration. Composer still asks for plugin trust on first use.

After the entry point updates successfully, query the entry-point and target-runtime versions, then open the existing-resource menu:

```sh
composer global exec -- webman-aot --version
```

macOS terminal:

```sh
php "$(composer global config bin-dir --absolute)/webman-aot" guide
```

Windows PowerShell:

```powershell
$aotBin = (composer global config bin-dir --absolute).Trim()
if ($LASTEXITCODE -ne 0) { throw '无法读取 Composer 命令目录。' }
php (Join-Path $aotBin 'webman-aot') guide
```

Select the start option to prepare the runtime corresponding to that entry point; a ready runtime is reused. During upgrades, cached full packages and completed `.part` downloads are verified first and used directly when valid. If no usable full package exists and the release supports lightweight upgrades, old components are checked and files meeting the new version's requirements are reused; only the application and necessary replacement files are downloaded. Missing or invalid components produce an explanation before a full package is obtained. The switch completes only after the new environment passes its self-check. Failure restores the old environment; if restoration itself fails, the process stops and reports the retained backup location. Updating only the entry point does not upgrade the runtime environment. Select the exit option to defer the runtime upgrade. Upgrades do not rebuild project artifacts. See [upgrading and uninstalling](https://github.com/supdger/webman-aot-builder/wiki/Upgrade-Uninstall) for details.

The direct PHP entry point avoids the default 300-second child-process timeout of `composer global exec`, making it suitable for full-package downloads and long interactive sessions. No global Composer timeout change is needed.

### Option 2: Native installation packages

Download the setup matching your **development machine** from the [latest stable release](https://github.com/supdger/webman-aot-builder/releases/latest):

- macOS Apple Silicon: download the package ending in `macos-arm64-setup.zip`, extract it, and run the `.command` file.
- Windows x64: download the entry point ending in `windows-x86_64-setup.cmd` and run the `.cmd` file.

Select a lightweight or full package in the menu, review the installation location and PATH changes, and confirm installation. Lightweight packages need network access to prepare components on the first build. Full packages include compiler components; project dependencies still need separate preparation. After installation, select a project to build, or exit and run `webman-aot build` from your project root.

See the [installation guide](https://github.com/supdger/webman-aot-builder/wiki/Install) for both platforms' launch commands, entry-point checks, and complete installation steps. If macOS blocks the first launch, follow that page to verify the source and checksums, then complete the system confirmation.

### Option 3: For developers building installation packages from source

Download **Source code (zip)** from the [latest stable release](https://github.com/supdger/webman-aot-builder/releases/latest), extract the entire archive, and enter the tool source root containing `build.command` and `build.cmd`. On Mac, run `sh ./build.command`; in Windows PowerShell, run `.\build.cmd`.

The entry point prepares and verifies materials, then builds and verifies installation packages. After confirming installation, you can select a project. Source package creation and project build success are reported separately. See the [source build guide](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source) for requirements and steps.

## Building and maintenance

Run from your own project root:

```sh
webman-aot build
webman-aot verify
```

For SaiAdmin projects, use `webman-aot build --profile=saiadmin`. See [compatibility requirements](https://github.com/supdger/webman-aot-builder/wiki/Compatibility) and the [SaiAdmin guide](https://github.com/supdger/webman-aot-builder/wiki/Build-SaiAdmin) for supported source structures, dependency capabilities, and compiler limitations.

Builds show the current stage and actual compilation counts. When no new output arrives, they show process status and elapsed waiting time. Failures retain the original cause, local log location, and recovery advice. By default, retries reuse completed units whose inputs and integrity checks pass; other units are recompiled. Every run still links and verifies artifacts again. `webman-aot build --fresh` recompiles everything without clearing the old cache. See the [build guide](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source) for detailed rules.

Use the [upgrade and uninstall guide](https://github.com/supdger/webman-aot-builder/wiki/Upgrade-Uninstall) for upgrades and cleanup. The current entry point can query older installations and remove items individually once ownership is confirmed. Residue of unknown ownership is retained; complete cleanup of every old version is not guaranteed. Project source, artifacts, and PATH are preserved. Shared toolchains and logs under standalone installation roots are retained; toolchains, logs, and caches inside Composer's private runtime are removed with that runtime.

## Build output and deployment

```text
dist-aot/
├── server          # Fully static Linux amd64 executable
├── start.sh        # Startup script
├── stop.sh         # Shutdown script
├── config/         # External configuration
├── …               # Templates, static assets, and other project resources
└── manifest.json   # Distribution manifest
```

Copy **the entire `dist-aot/` directory** to your Linux amd64 target machine. Configure `.env`, databases, and other requirements for your project, then run from the output directory:

```sh
./start.sh
```

Use `./start.sh --daemon` to run in the background and `./stop.sh` to stop it. Follow [Linux deployment and acceptance checks](https://github.com/supdger/webman-aot-builder/wiki/Linux-Acceptance) to check startup and business flows; local build verification does not replace target-machine acceptance.

## Recorded operations

### Windows: build and verify a Webman project

In the PowerShell record provided by the user, the first build failed; continuing compilation then completed 216 files and artifact verification. The following excerpt contains actual output from the successful stage, omitting repeated progress lines, machine paths, and long compiler commands. The artifact location is anonymized to a project-relative path.

```text
Successfully compiled 216 files
[成功] 构建项目（自动恢复已完成单元），耗时 119.0 秒，退出码 0
[成功] 校验本次项目产物，耗时 0.9 秒，退出码 0
实际校验范围：本机构建产物结构和完整性
项目构建与校验成功。
产物位置：dist-aot/
```

This successful record establishes the Windows build and local artifact verification; it does not establish Linux deployment or business acceptance.

### macOS: build and verify a SaiAdmin project

The user's real-machine record shows build completion, artifact verification, and the `dist-aot/` location. The image below preserves the operation's result and represents local verification of that project.

![Successful macOS build and local artifact verification](https://raw.githubusercontent.com/supdger/webman-aot-builder/main/docs/images/macos-build-success.png)

Existing user records also report successful artifact startup on Linux, but HTTP, database, and business API acceptance checks remain incomplete. See the [test and verification records](https://github.com/supdger/webman-aot-builder/wiki/Verification) for Linux startup records and the scope of other platform and resumed-build checks.

## Documentation and feedback

- [Wiki](https://github.com/supdger/webman-aot-builder/wiki/Home): installation, building, maintenance, and deployment guides
- [Changelog](https://github.com/supdger/webman-aot-builder/blob/main/CHANGELOG.md): historical features, fixes, and upgrade implications
- [Issues](https://github.com/supdger/webman-aot-builder/issues): problems and suggestions

Original code is licensed under the [MIT License](LICENSE). See [NOTICE](NOTICE.md) for third-party component licenses and attribution.
