#!/bin/sh
# Shared source preparation; this file never starts the menu or a build.
source_note() {
    printf '%s\n' "$*" | tee -a "$SOURCE_BOOTSTRAP_LOG"
}
source_lock_value() {
    awk -v key="$1" '$1 == key { if (NF != 2) exit 1; count++; value=$2 } END { if (count != 1) exit 1; print value }' "$SOURCE_ROOT/tools/source-build-materials.lock"
}
source_check_digest() {
    [ -f "$1" ] && [ ! -L "$1" ] && [ "$(shasum -a 256 "$1" | awk '{print $1}')" = "$2" ]
}
prepare_macos_runtime() {
    SOURCE_ROOT=$1
    SOURCE_BOOTSTRAP_LOG=$(mktemp "${TMPDIR:-/tmp}/webman-aot-source-bootstrap.XXXXXX") || return
    source_note "本次 PHP 准备日志：$SOURCE_BOOTSTRAP_LOG"
    [ "$(uname -s)" = Darwin ] && [ "$(uname -m)" = arm64 ] || { source_note '[失败] 此源码入口需要 macOS Apple Silicon。' >&2; return 64; }
    [ "$(source_lock_value schema)" = webman-aot-builder-source-materials-v1 ] || return 65
    source_url=$(source_lock_value url) || return 65
    source_digest=$(source_lock_value sha256) || return 65
    source_size=$(source_lock_value size) || return 65
    case "$source_url" in https://*) ;; *) return 65 ;; esac
    [ "${#source_digest}" -eq 64 ] || return 65
    case "$source_digest" in *[!a-f0-9]*) return 65 ;; esac
    case "$source_size" in ''|*[!0-9]*) return 65 ;; esac
    for source_key in runtime compiler licenses source; do
        source_path=$(source_lock_value "$source_key") || return 65
        case "$source_path" in ''|/*|*..*|*[!a-zA-Z0-9_./-]*) return 65 ;; esac
    done
    SOURCE_INPUTS="$SOURCE_ROOT/dist/installer-inputs"
    [ ! -L "$SOURCE_INPUTS" ] || { source_note '[失败] 缓存目录是链接，拒绝写入。' >&2; return 65; }
    mkdir -p "$SOURCE_INPUTS" || return
    source_archive="$SOURCE_INPUTS/$(basename "$source_url")"
    source_note '[开始] 核验锁定 PHP 重链接材料；已校验缓存可以复用。'
    if source_check_digest "$source_archive" "$source_digest"; then
        source_note '[成功] 缓存归档 SHA-256 通过，跳过下载。'
    elif source_check_digest "$source_archive.partial" "$source_digest"; then
        mv -f "$source_archive.partial" "$source_archive" || return
        source_note '[成功] 完整临时下载 SHA-256 通过，已转为缓存。'
    else
        source_note "[下载] PHP 重链接材料，锁定大小 $source_size 字节。"
        source_note '下方 Received 为已下载量，Speed 为字节/秒，Time Spent 为已用时；未知总量不表示失败。'
        source_download_started=$(date +%s)
        # Explicitly override progress choices only; preserve proxy and CA settings.
        [ ! -L "$source_archive.partial" ] || { source_note '[失败] 下载临时文件是链接，拒绝写入。' >&2; return 65; }
        source_exit_file=$(mktemp "${TMPDIR:-/tmp}/webman-aot-source-download.XXXXXX") || return
        (
        curl --fail --location --no-progress-bar --no-silent --progress-meter --proto '=https' --proto-redir '=https' --connect-timeout 15 --output "$source_archive.partial" "$source_url"
        printf '%s' "$?" > "$source_exit_file"
        ) 2>&1 | tee -a "$SOURCE_BOOTSTRAP_LOG"
        source_code=$(cat "$source_exit_file")
        rm -f "$source_exit_file"
        if [ "$source_code" -ne 0 ]; then
            case "$source_code" in
                6) source_reason='DNS 无法解析服务器' ;;
                7) source_reason='无法连接服务器，请检查网络或代理' ;;
                22) source_reason='服务器返回 HTTP 错误' ;;
                28) source_reason='网络超时' ;;
                60) source_reason='TLS 证书校验失败，请检查系统信任和代理证书' ;;
                *) source_reason='下载未完成，参阅上方 curl 错误' ;;
            esac
            source_note "[失败] ${source_reason}；耗时 $(($(date +%s)-source_download_started)) 秒，退出码 ${source_code}。网络恢复后重试同一入口。" >&2
            return "$source_code"
        fi
        source_check_digest "$source_archive.partial" "$source_digest" || { source_note '[失败] 材料 SHA-256 不符，拒绝执行。重跑入口重新下载。' >&2; return 65; }
        mv -f "$source_archive.partial" "$source_archive" || return
        source_note "[成功] 下载与 SHA-256 校验完成，耗时 $(($(date +%s)-source_download_started)) 秒。"
    fi
    SOURCE_MATERIALS="$SOURCE_INPUTS/macos-materials-$source_digest"
    # Recheck actual executables each time; an old extracted generation is not proof.
    source_php_digest=$(/usr/bin/plutil -extract runtimes.macos-arm64.binarySha256 raw -o - "$SOURCE_ROOT/installer/runtime.lock.json") || return
    source_compiler_digest=$(/usr/bin/plutil -extract runtimes.macos-arm64.compilerDriver.binarySha256 raw -o - "$SOURCE_ROOT/installer/runtime.lock.json") || return
    SOURCE_PHP="$SOURCE_MATERIALS/$(source_lock_value runtime)"
    source_compiler="$SOURCE_MATERIALS/$(source_lock_value compiler)"
    if ! source_check_digest "$SOURCE_PHP" "$source_php_digest" || ! source_check_digest "$source_compiler" "$source_compiler_digest"; then
        source_stage=$(mktemp -d "$SOURCE_INPUTS/macos-extract.XXXXXX") || return
        source_note '[开始] 解压已验证的 PHP 材料。'
        if ! tar -xzf "$source_archive" -C "$source_stage"; then
            rm -rf "$source_stage"
            source_note '[失败] 已核验材料解压失败，请检查磁盘空间后重试。' >&2
            return 1
        fi
        source_check_digest "$source_stage/$(source_lock_value runtime)" "$source_php_digest" && source_check_digest "$source_stage/$(source_lock_value compiler)" "$source_compiler_digest" || { source_note '[失败] 实际 PHP/编译驱动不符合 runtime.lock，拒绝执行。' >&2; rm -rf "$source_stage"; return 65; }
        # Do not replace or delete an unknown existing extraction.
        if [ -e "$SOURCE_MATERIALS" ]; then SOURCE_MATERIALS=$source_stage; else mv "$source_stage" "$SOURCE_MATERIALS" || return; fi
        SOURCE_PHP="$SOURCE_MATERIALS/$(source_lock_value runtime)"
    fi
    source_note '[成功] 实际 PHP 和编译驱动摘要与 runtime.lock 一致，无需系统 PHP。'
}
