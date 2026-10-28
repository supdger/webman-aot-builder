#!/bin/sh
set -u
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd) || exit 1
. "$root/tools/source-bootstrap-macos.sh"
prepare_macos_runtime "$root"
code=$?
[ "$code" -eq 0 ] || { echo '问题反馈：https://github.com/supdger/webman-aot-builder/issues' >&2; exit "$code"; }
exec "$SOURCE_PHP" -n "$root/tools/build-macos-installer.php" "--materials=$SOURCE_MATERIALS" "$@"
