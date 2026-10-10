#!/usr/bin/env python3
"""Real Composer lifecycle regression using only isolated HOME/global/cache and local ZIPs.

The PTY portion verifies macOS; it does not claim Windows console acceptance.
Usage: python3 packages/composer-installer/tests/plugin.py /absolute/candidate.zip [previous-0.3.6.zip]
"""
import errno
import json
import os
import pathlib
import pty
import select
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import zipfile


if sys.platform != "darwin":
    raise SystemExit("此真实 PTY 回归需 macOS；Windows 另需物理终端验收。")
timeout_only = "--guide-timeout-only" in sys.argv
arguments = [arg for arg in sys.argv[1:] if arg != "--guide-timeout-only"]
archive = pathlib.Path(arguments[0]).resolve()
previous = pathlib.Path(arguments[1]).resolve() if len(arguments) > 1 else None
with zipfile.ZipFile(archive) as z:
    metadata = json.loads(z.read("composer.json"))
version = archive.name.removeprefix("webman-aot-builder-").removesuffix("-composer.zip")
package = "supdger/webman-aot-builder"
base = pathlib.Path(tempfile.mkdtemp(prefix="aot-plugin-tests-"))
passed = 0
menu = "Webman AOT 项目构建："


def check(condition, message):
    global passed
    if not condition:
        raise AssertionError(message)
    passed += 1
    print(f"[通过] {message}", flush=True)


def fixture(name, trust=None):
    path = base / name
    for child in ["home", "global", "cache", "caller 中文"]:
        (path / child).mkdir(parents=True, exist_ok=True)
    current = dict(metadata, version=version, dist={"type": "zip", "url": archive.as_uri()})
    packages = [current, {"name": "fixture/other-tool", "version": "1.0.0", "type": "metapackage"}]
    if previous:
        with zipfile.ZipFile(previous) as z:
            packages.append(dict(json.loads(z.read("composer.json")), version="0.3.6",
                                 dist={"type": "zip", "url": previous.as_uri()}))
    seed = {"repositories": [{"type": "package", "package": packages}, {"packagist.org": False}]}
    if trust is not None:
        seed["config"] = {"allow-plugins": {package: trust}}
    (path / "global" / "composer.json").write_text(json.dumps(seed, indent=2) + "\n")
    (path / "caller 中文" / "composer.json").write_text(json.dumps({"repositories": seed["repositories"]}) + "\n")
    return path


def environment(path, extra=None):
    env = os.environ.copy()
    env.update(HOME=str(path / "home"), COMPOSER_HOME=str(path / "global"),
               COMPOSER_CACHE_DIR=str(path / "cache"), COMPOSER_DISABLE_NETWORK="1",
               COMPOSER_NO_INTERACTION="", CI="", COMPOSER_SKIP_SCRIPTS="")
    env.pop("COMPOSER", None)
    if extra:
        env.update(extra)
    return env


def invoke(path, args, answer="y", tty=True, extra=None, menu_wait=0):
    command = ["php", str(path / "global" / "vendor" / "bin" / "webman-aot"), *args[1:]] if args[0] == "@proxy" else ["composer", *args]
    print("[步骤] " + " ".join(command), flush=True)
    env = environment(path, extra)
    start = time.monotonic()
    if not tty:
        result = subprocess.run(command, cwd=path / "caller 中文", env=env,
                                stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.STDOUT, timeout=30)
        output = result.stdout.decode(errors="replace")
        print(output, end="", flush=True)
        return result.returncode, output
    pid, master = pty.fork()
    if pid == 0:
        os.chdir(path / "caller 中文")
        os.execvpe(command[0], command, env)
    output = b""
    trusted = False
    cancelled = False
    menu_seen = None
    try:
        while True:
            if time.monotonic() - start > 30:
                raise TimeoutError("隔离 Composer 夹具超时")
            if menu_seen is not None and not cancelled and time.monotonic() - menu_seen >= menu_wait:
                os.write(master, b"0\n")
                cancelled = True
            if not select.select([master], [], [], 0.2)[0]:
                continue
            try:
                chunk = os.read(master, 65536)
            except OSError as error:
                if error.errno == errno.EIO:
                    break
                raise
            if not chunk:
                break
            output += chunk
            print(chunk.decode(errors="replace"), end="", flush=True)
            if b'[y,n,d,?]' in output and not trusted:
                os.write(master, (answer + "\n").encode())
                trusted = True
            if "0 结束".encode() in output and menu_seen is None:
                menu_seen = time.monotonic()
        _, status = os.waitpid(pid, 0)
        return os.waitstatus_to_exitcode(status), output.decode(errors="replace")
    except BaseException:
        os.kill(pid, signal.SIGTERM)
        os.waitpid(pid, 0)
        raise
    finally:
        os.close(master)
        print(f"[结束] 耗时 {time.monotonic() - start:.2f}s", flush=True)


def no_menu(path, args, **kwargs):
    code, output = invoke(path, args, **kwargs)
    check(code == 0 and menu not in output, " ".join(args) + " 不自动菜单")


try:
    path = fixture("guide-process-timeout", trust=True)
    code, output = invoke(path, ["global", "require", package + ":" + version, "--no-scripts"])
    check(code == 0 and menu not in output, "超时回归隔离入口安装，不启动菜单")
    state = path / "home" / "Library" / "Application Support" / "webman-aot-composer"
    state.mkdir(parents=True)
    (state / "runtime-sentinel").write_text("keep existing runtime")
    timeout_env = {"COMPOSER_PROCESS_TIMEOUT": "1"}
    code, output = invoke(path, ["global", "exec", "--", "webman-aot", "guide"], extra=timeout_env, menu_wait=2)
    check(code != 0 and "exceeded the timeout of 1 seconds" in output,
          "缩短外层超时到1秒，真实Composer exec等待菜单被终止")
    code, output = invoke(path, ["@proxy", "guide"], extra=timeout_env, menu_wait=2)
    check(code == 0 and output.count(menu) == 1 and "exceeded the timeout" not in output,
          "相同超时环境直接PHP代理等待超过1秒仍能正常结束")
    check((state / "runtime-sentinel").read_text() == "keep existing runtime" and not (state / "runtime").exists()
          and not (state / "cache").exists(), "两条对照命令均不下载或改变已有运行环境")
    if timeout_only:
        print(f"完成：{passed} 项真实Composer超时对照检查通过（缩短超时模拟）。", flush=True)
        raise SystemExit(0)
    # The same public, version-free require repairs root metadata as well as upgrading a pinned install.
    for mode in ["pinned-root", "missing-root"]:
        path = fixture("stable-upgrade-" + mode, trust=True)
        code, output = invoke(path, ["global", "require", package + ":" + version, "fixture/other-tool", "--no-scripts"])
        check(code == 0 and menu not in output, mode + " 旧入口与其他工具隔离安装成功")
        seed_path = path / "global" / "composer.json"
        seed = json.loads(seed_path.read_text())
        target = version + ".1"  # Private test version, never published; runtime upgrade is tested separately.
        seed["repositories"][0]["package"].append(dict(metadata, version=target, dist={"type": "zip", "url": archive.as_uri()}))
        state = path / "home" / "Library" / "Application Support" / "webman-aot-composer"
        state.mkdir(parents=True)
        (state / "runtime-sentinel").write_text("existing runtime must survive entry update")
        if mode == "missing-root":
            del seed["require"][package]
            lock_path = path / "global" / "composer.lock"
            lock = json.loads(lock_path.read_text())
            lock["packages"] = [p for p in lock["packages"] if p["name"] != package]
            lock_path.write_text(json.dumps(lock) + "\n")
        seed_path.write_text(json.dumps(seed) + "\n")
        if mode == "missing-root":
            code, output = invoke(path, ["global", "update", package, "--no-scripts"])
            check(code == 0 and "Removing " + package in output and not (path / "global" / "vendor" / "bin" / "webman-aot").exists(),
                  "复现旧update文档在root/lock丢失时移除孤立入口，后续代理不存在")
        code, output = invoke(path, ["global", "require", package + ":*", "--no-scripts"])
        installed = json.loads((path / "global" / "vendor" / "composer" / "installed.json").read_text())["packages"]
        requirements = json.loads(seed_path.read_text())["require"]
        check(code == 0 and menu not in output and requirements[package] == "*"
              and any(p["name"] == package and p["version"] == target for p in installed)
              and "fixture/other-tool" in requirements and (path / "global" / "vendor" / "bin" / "webman-aot").exists()
              and (state / "runtime-sentinel").read_text() == "existing runtime must survive entry update",
              mode + " 同一版本无关require升级或恢复入口、其他工具/运行时保留且无自动菜单")
        no_menu(path, ["global", "exec", "--", "webman-aot", "--non-interactive", "--version"], tty=False)
        code, output = invoke(path, ["@proxy", "guide"])
        check(code == 0 and output.count(menu) == 1 and "目标构建器：" + version in output
              and (state / "runtime-sentinel").read_text() == "existing runtime must survive entry update",
              mode + " 入口更新后独立guide显示目标运行时，选择结束不改变原运行环境")
    path = fixture("first")
    code, output = invoke(path, ["global", "require", package])
    check(code == 0 and "Do you trust" in output and output.count(menu) == 1,
          "裸全局 require 首次官方信任后只启动一次菜单")
    check(output.index("Generating autoload") < output.index(menu),
          "菜单在安装与自动加载完成之后")
    check(not (path / "home" / "Library" / "Application Support" / "webman-aot-composer").exists(),
          "自动菜单取消不准备私有资源")
    code, output = invoke(path, ["global", "require", package])
    check(code == 0 and "Nothing to install" in output and "Do you trust" not in output
          and output.count(menu) == 1, "重复 require 无变更仍进入菜单且不重复信任")
    for args in [
        ["global", "show", package], ["global", "install"], ["global", "update", package],
        ["global", "config", "bin-dir"], ["global", "exec", "--", "webman-aot", "--version"],
        ["global", "require", "fixture/other-tool"],
        ["global", "require", package, "fixture/other-tool"],
        ["require", package],
    ]:
        no_menu(path, args)
    for option in ["--no-plugins", "--no-scripts", "--dry-run", "--no-update", "--no-install",
                   "-n", "--no-interaction"]:
        no_menu(path, ["global", "require", package, option])
    for env in [{"CI": "1"}, {"COMPOSER_NO_INTERACTION": "1"}]:
        no_menu(path, ["global", "require", package], extra=env)
    no_menu(path, ["global", "require", package], tty=False)
    code, output = invoke(path, ["global", "require", package + ":99.99.99"])
    check(code != 0 and menu not in output, "依赖解析失败不菜单")
    # Private repository-only versions exercise plugin replacement, not a public release.
    seed = json.loads((path / "global" / "composer.json").read_text())
    replacement = dict(metadata, version=version + ".1",
                       dist={"type": "zip", "url": archive.as_uri()})
    seed["repositories"][0]["package"].append(replacement)
    (path / "global" / "composer.json").write_text(json.dumps(seed) + "\n")
    code, output = invoke(path, ["global", "require", package + ":" + version + ".1"])
    check(code == 0 and "Upgrading" in output and output.count(menu) == 1,
          "已加载插件在更新中被替换后，新实例仍只打开一次菜单")
    bad = path / "bad-package.zip"
    bad.write_text("invalid archive")
    seed["repositories"][0]["package"].append(
        dict(metadata, version=version + ".2", dist={"type": "zip", "url": bad.as_uri()}))
    (path / "global" / "composer.json").write_text(json.dumps(seed) + "\n")
    code, output = invoke(path, ["global", "require", package + ":" + version + ".2"])
    check(code != 0 and menu not in output, "已加载插件的升级归档解压失败不菜单")
    no_menu(path, ["global", "remove", package])
    for answer in ["n", "d"]:
        path = fixture("decline-" + answer)
        code, output = invoke(path, ["global", "require", package], answer=answer)
        check(code == 0 and "Do you trust" in output and menu not in output,
              "官方信任选择 " + answer + " 保留普通入口且不菜单")
    path = fixture("unattended")
    no_menu(path, ["global", "require", package, "-n"], tty=False)
    config = json.loads((path / "global" / "composer.json").read_text())
    check("allow-plugins" not in config.get("config", {}),
          "无人首次安装 optional 插件不预写信任配置")
    no_menu(path, ["global", "exec", "--", "webman-aot", "--version"], tty=False)
    for name, platform in [
        ("low-version-capable", {"composer": "0.0.1", "composer-plugin-api": "0.0.1"}),
        ("future-version-capable", {"composer": "999.0.0", "composer-plugin-api": "999.0.0"}),
    ]:
        path = fixture(name)
        seed = json.loads((path / "global" / "composer.json").read_text())
        seed["config"] = {"platform": platform}
        (path / "global" / "composer.json").write_text(json.dumps(seed) + "\n")
        code, output = invoke(path, ["global", "require", package + ":" + version, "-n"], tty=False)
        check(code == 0 and menu not in output,
              "实际 solver 接受 " + name + " 平台标签；实际执行仍为当前 Composer")
    path = fixture("unsupported-php-syntax")
    seed = json.loads((path / "global" / "composer.json").read_text())
    seed["config"] = {"platform": {"php": "7.4.0"}}
    (path / "global" / "composer.json").write_text(json.dumps(seed) + "\n")
    code, output = invoke(path, ["global", "require", package + ":" + version, "-n"], tty=False)
    check(code != 0 and "php >=8.0" in output and menu not in output,
          "不支持 match 语法的 PHP 平台明确拒绝")
    if previous:
        path = fixture("upgrade")
        code, output = invoke(path, ["global", "require", package + ":0.3.6"])
        check(code == 0 and menu not in output, "旧 0.3.6 安装行为保持")
        code, output = invoke(path, ["global", "require", package + ":" + version])
        check(code == 0 and "Upgrading" in output and "Do you trust" in output
              and output.count(menu) == 1, "真实 0.3.6 升级新插件，信任后自动菜单")
    print(f"完成：{passed} 项真实 Composer 生命周期检查通过。", flush=True)
finally:
    shutil.rmtree(base)
    print("本次 HOME/global/cache/项目夹具已清理：" + str(not base.exists()), flush=True)
