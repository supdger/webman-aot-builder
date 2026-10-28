#!/bin/sh
set -u
root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd) || exit 1
. "$root/tools/source-bootstrap-macos.sh"
prepare_macos_runtime "$root"
code=$?
if [ "$code" -eq 0 ]; then
    "$SOURCE_PHP" -n "$root/tools/guided.php" --mode=source "$@"
    code=$?
else
    echo "[失败] 源码入口准备失败，退出码 ${code}。请检查上方原因后重试。" >&2
    echo '问题反馈：https://github.com/supdger/webman-aot-builder/issues' >&2
fi
if [ -t 0 ] && [ -t 1 ]; then printf '按回车关闭窗口。'; read -r ignored || :; fi
exit "$code"
