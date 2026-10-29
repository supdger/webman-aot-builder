# Webman AOT Builder

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE) [![Latest release](https://img.shields.io/github/v/release/supdger/webman-aot-builder)](https://github.com/supdger/webman-aot-builder/releases/latest) [![Downloads](https://img.shields.io/github/downloads/supdger/webman-aot-builder/total)](https://github.com/supdger/webman-aot-builder/releases) [![GitHub stars](https://img.shields.io/github/stars/supdger/webman-aot-builder)](https://github.com/supdger/webman-aot-builder)

[English](README.md) | [简体中文](README.zh-CN.md)

Webman AOT Builder compiles PHP projects into fully static Linux amd64 executables. Original PHP source is passed directly to TypePHP.
Install the tool and build your project on a macOS Apple Silicon or Windows x64 development machine, then deploy the generated `dist-aot/` directory to Linux. The target machine does not need PHP installed.

## How it works

The builder enumerates readable PHP sources and compiles an isolated mirror copy of each file with TypePHP, then generates an executable with Clang and the PHPx static SDK. Compilation is tiered so a single file cannot abort the build:

- Directly compilable sources are compiled ahead of time.
- Sources needing known mechanical adjustments are normalized inside the isolated mirror and then compiled.
- Sources TypePHP cannot compile are excluded from code generation, embedded in the binary, and executed at runtime by the embedded Zend interpreter. If compilation reports a file-level failure, the builder attributes it, marks that file interpreted, and retries automatically within a bounded limit.

The build finishes with a per-tier report (compiled / processed / interpreted) instead of surfacing compiler errors. Your original source code is never modified; all rewrites happen only inside the isolated build mirror. Configuration, templates, and static assets remain external files as needed.

## Source code and installation packages

This repository contains the builder's source code, including the command-line tool, source input organization, compiler component management, and installation package scripts.
For development or building your own installation packages, start with the [latest development source](https://github.com/supdger/webman-aot-builder/tree/main).
See the [source build guide](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source) for details.

Maintainers can compose a component from a complete verified compiler source using `tools/build-minimal-component.php --compiler-source-root=PATH`, while reusing a prepared SDK. The development-source entry verifies source provenance, the complete authoritative Composer map, and the builder's full-static runtime declaration contract; see the source build guide for the required inputs. This component check does not replace installation, complete-project build, or native regression acceptance.

To use the tool, download an [installation package from the latest stable release](https://github.com/supdger/webman-aot-builder/releases/latest).
Choose the macOS Apple Silicon or Windows x64 package that matches your **development machine**.
Lightweight packages download and prepare compiler components on first use; full packages include the components and can prepare them offline during installation.
Both packages provide the `webman-aot` command after installation. GitHub's `Source code (zip)` download is a source archive, not an installation package.
If Windows cannot download the components, download the full installation package whose name contains `full-windows-x86_64.zip`, extract the entire archive, and run `install.cmd`. A `components.zip` archive contains only component resources and has no installer. See [full package installation on Windows](https://github.com/supdger/webman-aot-builder/wiki/Install-Windows#windows-完整包安装).

## Installation and usage

Follow the [installation and usage guide](https://github.com/supdger/webman-aot-builder/wiki/Install) to download, verify, and install the tool.
After installation, run the following command from any directory to check your development machine and prepare missing compiler components. A healthy environment reports `Result: healthy`:

```sh
webman-aot doctor
```

To build, go to **your own PHP project directory** and run:

```sh
webman-aot build
webman-aot verify
```

`build` generates `dist-aot/`; `verify` checks its distribution structure and static properties.
Compilation has only four prerequisites:

1. The PHP source to compile is readable.
2. TypePHP and the target platform toolchain are available.
3. The output directory is writable.
4. Executable builds have an explicit entrypoint; individual files and libraries follow their corresponding compilation modes.

Standard Webman layouts need no entrypoint flag: a root `start.php` is picked up automatically, after an explicit `--entry` or a manifest `entry-source` and before Composer `bin` detection. The generated entrypoint is synthesized in the mirror so the Embed SAPI requirement is met. The builder does not check dependency versions, review business code, or impose project, framework, plugin, directory layout, or business startup requirements. Generated runtime data (`runtime/`, `node_modules/`), vendor development directories (tests, fixtures, docs, specs, benchmarks), hidden directories, and symlinks are excluded from compilation rather than failing the build.

## Build output

Example output structure (runtime resources vary by project):

```text
dist-aot/
├── server          # Fully static Linux amd64 executable
├── start.sh        # Startup script
├── stop.sh         # Shutdown script
├── config/         # External configuration
├── …               # Templates, static assets, and other project resources
└── manifest.json   # Distribution manifest
```

Keep external resource files in their original relative layout beside the executable. Native regression checks run with only the executable, runtime configuration, and explicitly declared non-PHP resources; compiled PHP source is absent from the runtime container.

Copy **the entire `dist-aot/` directory** to your Linux amd64 target machine. Configure `.env`, databases, and other runtime requirements for your project, then start the application from that directory:

```sh
./start.sh
```

Use `./start.sh --daemon` to run in the background and `./stop.sh` to stop it.
See [Linux deployment and acceptance checks](https://github.com/supdger/webman-aot-builder/wiki/Linux-Acceptance) for target configuration and business checks after startup.

## Builds and startup checks on real machines

These records describe the historical v0.3.2 adaptation workflow. They do not establish Windows compilation or linking results for the current original-source workflow.

On 2026-09-30, the user built a SaiAdmin project on macOS Apple Silicon and Windows x64 machines, then copied each build to a Linux x86_64 server and started it. The excerpts below contain key terminal output provided by the user. macOS and Windows are build hosts; the generated executables target Linux.

### macOS Apple Silicon: build and start on Linux

The v0.3.2 lightweight package was used to install the tool, prepare compiler components, and build the SaiAdmin project. The following excerpts preserve key installation, component preparation, compilation, and artifact verification steps, with repeated progress lines omitted:

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

The artifacts from this Mac build were copied to a Linux x86_64 server. Running `./start.sh` in the output directory reported that all 8 workers started successfully (the process username has been anonymized):

```text
Workerman[main.php] start in DEBUG mode
Workerman/5.2.2         PHP/8.4.25 (JIT off)          Linux/5.10.134-16.3.al8.x86_64
event-loop  proto       user        worker      listen                 count       state
select      tcp         <用户>      webman      http://0.0.0.0:8788    8            [OK]
Press Ctrl+C to stop. Start success.
```

### Windows x64: build and start on CentOS 7

The v0.3.2 Windows full package was used to build the SaiAdmin project. The available log preserves the final compilation steps, build completion, and artifact verification. The total build time was approximately 35 minutes and 43 seconds:

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

The artifacts from this Windows build were copied to another server running CentOS 7. Running `./start.sh` in the output directory reported that all 4 workers started successfully (the process username has been anonymized):

```text
Workerman[main.php] start in DEBUG mode
Workerman/5.2.2         PHP/8.4.25 (JIT off)          Linux/3.10.0-1160.95.1.el7.x86_64
event-loop  proto       user        worker      listen                 count       state
select      tcp         <用户>      webman      http://0.0.0.0:1717    4            [OK]
Press Ctrl+C to stop. Start success.
```

Builds and artifact verification succeeded on both development machines, and both Linux target environments reported successful service startup. Successful installation on the Mac was also recorded. Compatibility warnings remained during startup; HTTP, database, and business API acceptance checks have not yet been completed. See the [real-machine test records](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source#v032-实机测试记录) for details.

## Documentation

- [Wiki](https://github.com/supdger/webman-aot-builder/wiki/Home): installation, usage, and maintenance documentation
- [Changelog](https://github.com/supdger/webman-aot-builder/blob/main/CHANGELOG.md): features, fixes, and upgrade implications

## License and feedback

Original code is licensed under the [MIT License](LICENSE). See [NOTICE](NOTICE.md) for third-party component licenses and attribution.
Please submit problems and suggestions to [Issues](https://github.com/supdger/webman-aot-builder/issues).
