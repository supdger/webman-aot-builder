#!/bin/sh
package_root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd) || exit 1
started_at=$(date +%s)
if [ "$(uname -s)" != Darwin ] || [ "$(uname -m)" != arm64 ]; then
    echo '此安装包需要 macOS ARM64。' >&2
    status=78
elif [ ! -x "$package_root/payload/runtime/bin/php" ]; then
    echo '包内 PHP 缺失或无法执行；请重新下载完整安装包。' >&2
    status=66
else
    "$package_root/payload/runtime/bin/php" "$package_root/payload/app/tools/guided.php" --mode=install "--package-root=$package_root" "$@"
    status=$?
fi
if [ "$status" -ne 0 ]; then
    echo "安装入口失败（退出码 ${status}，耗时 $(($(date +%s) - started_at)) 秒）。" >&2
    echo '反馈问题：https://github.com/supdger/webman-aot-builder/issues' >&2
fi
if [ -t 0 ] && [ -t 1 ]; then
    printf '\n按回车关闭此窗口… '
    IFS= read -r answer || :
fi
exit "$status"
