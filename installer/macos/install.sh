#!/bin/sh

set -eu
started_at=$(date +%s)

package_root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
aot_home="${HOME}/Library/Application Support/webman-aot-builder"
bin_dir="${HOME}/.local/bin"
update_path=1

while [ "$#" -gt 0 ]; do
    case "$1" in
        --home)
            shift
            [ "$#" -gt 0 ] || { echo "Missing value for --home" >&2; exit 64; }
            aot_home=$1
            ;;
        --bin-dir)
            shift
            [ "$#" -gt 0 ] || { echo "Missing value for --bin-dir" >&2; exit 64; }
            bin_dir=$1
            ;;
        --no-path)
            update_path=0
            ;;
        *)
            echo "Unknown installer option: $1" >&2
            exit 64
            ;;
    esac
    shift
done

[ "$(uname -s)" = "Darwin" ] && [ "$(uname -m)" = "arm64" ] || {
    echo "This package requires macOS ARM64." >&2
    exit 78
}
case "$aot_home" in
    /|"$HOME"|"$package_root")
        echo "Unsafe Webman AOT Builder installation directory: $aot_home" >&2
        exit 64
        ;;
esac
[ ! -L "$aot_home" ] || {
    echo "Webman AOT Builder installation directory must not be a symbolic link." >&2
    exit 64
}

(
    cd "$package_root"
    shasum -a 256 -c payload-manifest.sha256
)
echo "Package contents SHA-256 verified."

candidate="${aot_home}/.install-candidates/install-$$"
backup_root=''
new_current=0
new_toolchains=0
new_launcher=0
legacy_launcher_moved=0
profile_modified=0
profile_new=0
complete=0
full=0
if [ -f "$package_root/payload/minimal-toolchain/component.zip" ]; then
    full=1
fi
cleanup() {
    if [ "$complete" -eq 0 ] && [ -n "$backup_root" ]; then
        echo "Installation did not complete; restoring the previous installation." >&2
        if [ "$profile_modified" -eq 1 ]; then
            if [ "$profile_new" -eq 1 ]; then
                rm -f -- "$profile"
            else
                mv "$backup_root/profile" "$profile"
            fi
        fi
        [ "$new_launcher" -eq 0 ] || rm -f -- "$bin_dir/webman-aot"
        [ "$new_toolchains" -eq 0 ] || rm -rf -- "$aot_home/toolchains"
        [ "$new_current" -eq 0 ] || rm -rf -- "$aot_home/current"
        [ ! -f "$backup_root/webman-aot" ] || mv "$backup_root/webman-aot" "$bin_dir/webman-aot"
        [ ! -d "$backup_root/current" ] || mv "$backup_root/current" "$aot_home/current"
        [ ! -d "$backup_root/toolchains" ] || mv "$backup_root/toolchains" "$aot_home/toolchains"
        [ ! -d "$backup_root/versions" ] || mv "$backup_root/versions" "$aot_home/versions"
    fi
    if [ "$complete" -eq 0 ] && [ "$legacy_launcher_moved" -eq 1 ]; then
        mv "$aot_home/.previous-launcher/webman-aot" "$bin_dir/webman-aot"
    fi
    if [ -d "$candidate" ]; then
        rm -rf -- "$candidate"
    fi
}
trap cleanup EXIT HUP INT TERM

mkdir -p "$candidate/current" "$aot_home/.install-backups" "$bin_dir"
if [ "$full" -eq 1 ]; then
    free_kib=$(df -Pk "$aot_home" | awk 'NR == 2 { print $4 }')
    if [ -z "$free_kib" ] || [ "$free_kib" -lt 4194304 ]; then
        echo "Full installation needs at least 4 GiB free at $aot_home; choose a larger volume." >&2
        exit 70
    fi
    echo "[install] Complete package: installing the locked minimal toolchain offline (no downloads)."
fi
cp -R "$package_root/payload/app" "$candidate/current/app"
cp -R "$package_root/payload/runtime" "$candidate/current/runtime"
chmod 700 "$candidate/current/runtime/bin/php"

WEBMAN_AOT_BUILDER_HOME="$candidate" \
    "$candidate/current/runtime/bin/php" -n \
    "$candidate/current/app/bin/webman-aot-builder.php" --version >/dev/null

launcher="$bin_dir/webman-aot"
if [ -e "$launcher" ] || [ -L "$launcher" ]; then
    [ ! -L "$launcher" ] && [ -f "$launcher" ] || {
        echo "Cannot replace non-regular command: $launcher" >&2
        exit 70
    }
    if ! grep -F 'WEBMAN_AOT_BUILDER_PUBLIC_LAUNCHER' "$launcher" >/dev/null 2>&1; then
        [ ! -e "$aot_home/.previous-launcher/webman-aot" ] || {
            echo "A previous webman-aot command is already backed up; refusing to overwrite: $launcher" >&2
            exit 70
        }
        mkdir -p "$aot_home/.previous-launcher"
        mv "$launcher" "$aot_home/.previous-launcher/webman-aot"
        legacy_launcher_moved=1
        echo "Previous webman-aot command saved in: $aot_home/.previous-launcher/webman-aot"
    fi
fi

reuse=0
if [ "$full" -eq 0 ] && [ -n "${WEBMAN_AOT_REUSE_HOME:-}" ]; then
    WEBMAN_AOT_BUILDER_HOME="$candidate" \
        "$candidate/current/runtime/bin/php" -n \
        "$candidate/current/app/installer/offline-prepare.php" --reuse
    reuse=1
fi
if [ "$full" -eq 1 ] || [ "$reuse" -eq 1 ]; then
    if [ "$full" -eq 1 ]; then
    WEBMAN_AOT_BUILDER_HOME="$candidate" \
        "$candidate/current/runtime/bin/php" -n \
        "$candidate/current/app/installer/offline-prepare.php" \
        "$package_root/payload/minimal-toolchain/component.zip"
    fi
    backup_root="${aot_home}/.install-backups/full-$(date -u +%Y%m%dT%H%M%SZ)-$$"
    mkdir -p "$backup_root"
    [ ! -d "$aot_home/current" ] || mv "$aot_home/current" "$backup_root/current"
    [ ! -d "$aot_home/toolchains" ] || mv "$aot_home/toolchains" "$backup_root/toolchains"
    [ ! -d "$aot_home/versions" ] || mv "$aot_home/versions" "$backup_root/versions"
    [ ! -f "$launcher" ] || mv "$launcher" "$backup_root/webman-aot"
    mv "$candidate/current" "$aot_home/current"
    new_current=1
    mv "$candidate/toolchains" "$aot_home/toolchains"
    new_toolchains=1
    cp "$package_root/payload/launcher/webman-aot" "$launcher"
    new_launcher=1
    chmod 700 "$launcher"
    if [ "$full" -eq 1 ]; then
    WEBMAN_AOT_BUILDER_HOME="$aot_home" \
        "$aot_home/current/runtime/bin/php" -n \
        "$aot_home/current/app/installer/offline-prepare.php" \
        "$package_root/payload/minimal-toolchain/component.zip"
    fi
else
    backup_root="${aot_home}/.install-backups/current-$(date -u +%Y%m%dT%H%M%SZ)-$$"
    mkdir -p "$backup_root"
    [ ! -d "$aot_home/current" ] || mv "$aot_home/current" "$backup_root/current"
    [ ! -d "$aot_home/versions" ] || mv "$aot_home/versions" "$backup_root/versions"
    [ ! -f "$launcher" ] || mv "$launcher" "$backup_root/webman-aot"
    mv "$candidate/current" "$aot_home/current"
    new_current=1
    cp "$package_root/payload/launcher/webman-aot" "$launcher"
    new_launcher=1
    chmod 700 "$launcher"
fi

if [ "$update_path" -eq 1 ]; then
    profile="${HOME}/.zprofile"
    marker_begin='# >>> webman-aot-builder >>>'
    if ! grep -F "$marker_begin" "$profile" >/dev/null 2>&1; then
        if [ -f "$profile" ]; then
            cp "$profile" "$backup_root/profile"
        else
            profile_new=1
        fi
        profile_modified=1
        if {
            printf '\n%s\n' "$marker_begin"
            printf 'export PATH="%s:$PATH"\n' "$bin_dir"
            printf '%s\n' '# <<< webman-aot-builder <<<'
        } >>"$profile"; then
            :
        else
            echo "Unable to update shell PATH in $profile." >&2
            exit 70
        fi
    fi
fi

complete=1
trap - EXIT HUP INT TERM
cleanup
obsolete_launcher="$bin_dir/webman-aot-builder"
if [ -f "$obsolete_launcher" ] && grep -F 'WEBMAN_AOT_BUILDER_HOME' "$obsolete_launcher" >/dev/null 2>&1; then
    rm -f -- "$obsolete_launcher"
fi
echo "Webman AOT Builder installed in: $aot_home"
echo "Command installed as: $launcher"
if [ "$full" -eq 1 ]; then
    echo "Complete offline toolchain ready. Enter a Webman project and run webman-aot build."
fi
echo "Installation completed in $(($(date +%s) - started_at)) seconds."
