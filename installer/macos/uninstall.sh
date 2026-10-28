#!/bin/sh
set -eu

# Run outside the selected installation so its private PHP can also be removed.
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
source_root=$(CDPATH= cd -- "$script_dir/../.." && pwd -P)
aot_home=${WEBMAN_AOT_BUILDER_HOME:-"$HOME/Library/Application Support/webman-aot-builder"}
bin_dir="$HOME/.local/bin"
engine="$source_root/packages/composer-installer/src/Uninstaller.php"
if [ ! -f "$engine" ]; then
    engine="$script_dir/payload/app/packages/composer-installer/src/Uninstaller.php"
fi
[ -f "$engine" ] && [ ! -L "$engine" ] || { echo '[失败] 卸载引擎缺失；请使用新版源码或 Composer 入口。' >&2; exit 70; }
# Keep the documented --home/--bin-dir arguments and pass all choices to the shared engine.
parse_home=$aot_home
previous=''
for option do
    if [ "$previous" = '--home' ]; then parse_home=$option; previous=''; continue; fi
    case "$option" in
        --home) previous='--home' ;;
        --home=*) parse_home=${option#--home=} ;;
    esac
done
private_php="$parse_home/current/runtime/bin/php"
# Check lexical ancestors before resolving or executing any private runtime.
probe=$private_php
while [ "$probe" != '/' ] && [ "$probe" != '.' ]; do
    [ ! -L "$probe" ] || { echo "[失败] 运行时路径含链接：${probe}；保留安装，请改用 Composer 入口。" >&2; exit 70; }
    parent=$(dirname -- "$probe")
    [ "$parent" != "$probe" ] || break
    probe=$parent
done
engine_root=$(CDPATH= cd -- "$(dirname -- "$engine")/.." && pwd -P)

if [ -x "$private_php" ] && [ ! -L "$private_php" ]; then
    expected=$(sed -n 's/.*"macos-arm64": "\([0-9a-f]*\)".*/\1/p' "$engine_root/resources/uninstall-launchers.json")
    actual=$(/usr/bin/shasum -a 256 "$private_php" | awk '{print $1}')
    [ -n "$expected" ] && [ "$actual" = "$expected" ] || { echo '[失败] 私有 PHP 摘要不属于本入口受信运行时；保留安装，请改用 Composer 入口。' >&2; exit 70; }
    echo '[准备] 将已核对摘要的私有 PHP 复制到临时目录，以便同步卸载当前版本。'
    temporary=$(mktemp -d "${TMPDIR:-/tmp}/webman-aot-uninstall.XXXXXX")
    cleanup() { rm -rf -- "$temporary"; }
    trap cleanup EXIT
    trap 'exit 130' HUP INT TERM
    cp "$private_php" "$temporary/php"
    chmod 700 "$temporary/php"
    php_command="$temporary/php"
else
    php_command=$(command -v php || :)
    [ -n "$php_command" ] || { echo '[失败] 私有 PHP 不存在且未找到系统 PHP；请从 Composer 入口运行卸载。' >&2; exit 70; }
fi
# Load all code before deletion; the template resource remains available for the final recheck.
engine_root=$(CDPATH= cd -- "$(dirname -- "$engine")/.." && pwd -P)
if [ -z "${temporary:-}" ]; then
    temporary=$(mktemp -d "${TMPDIR:-/tmp}/webman-aot-uninstall.XXXXXX")
    cleanup() { rm -rf -- "$temporary"; }
    trap cleanup EXIT
    trap 'exit 130' HUP INT TERM
fi
mkdir -p "$temporary/src" "$temporary/resources"
cp "$engine" "$temporary/src/Uninstaller.php"
cp "$engine_root/resources/uninstall-launchers.json" "$temporary/resources/uninstall-launchers.json"
"$php_command" -n "$temporary/src/Uninstaller.php" --home="$aot_home" --bin-dir="$bin_dir" "$@"
