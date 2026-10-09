#!/bin/sh

set -eu

if [ "$#" -lt 3 ] || [ "$#" -gt 4 ]; then
    echo "Usage: build-macos-compiler-driver.sh <locked-php-source.tar.xz> <output-php> <empty-workspace> [toolchain-manifest]" >&2
    exit 64
fi

[ "$(uname -s)" = Darwin ] && [ "$(uname -m)" = arm64 ] || {
    echo "The compiler PHP driver must be built on macOS ARM64." >&2
    exit 78
}

archive=$1
output=$2
workspace=$3
repository=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
manifest=${4:-"$repository/toolchain.lock.json"}
expected=$(php -r '$m=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);foreach($m["components"] as $c){if($c["id"]==="php-source"){echo $c["sha256"];exit;}}exit(1);' "$manifest")
actual=$(shasum -a 256 "$archive" | awk '{print $1}')
[ "$actual" = "$expected" ] || {
    echo "Selected PHP source digest mismatch." >&2
    exit 78
}
[ -d "$workspace" ] && [ -z "$(ls -A "$workspace")" ] || {
    echo "Build workspace must exist and be empty." >&2
    exit 64
}
[ ! -e "$output" ] || {
    echo "Output file already exists." >&2
    exit 64
}

workspace=$(cd "$workspace" && pwd -P)
/usr/bin/tar -xf "$archive" -C "$workspace"
source_root=
for entry in "$workspace"/*; do
    [ -d "$entry" ] || continue
    [ -z "$source_root" ] || { echo "PHP source archive requires one root." >&2; exit 78; }
    source_root=$entry
done
[ -f "$source_root/configure" ] || {
    echo "Locked PHP source archive has an unexpected root." >&2
    exit 78
}
sdk=$(xcrun --show-sdk-path)
prefix=/opt/webman-aot-builder/compiler-php
export CFLAGS="-O2 -ffile-prefix-map=$source_root=/usr/src/webman-aot-builder/php-source"
export SOURCE_DATE_EPOCH=0

(
    cd "$source_root"
    ./configure \
        "--prefix=$prefix" \
        --disable-all \
        --enable-cli \
        --disable-cgi \
        --disable-phpdbg \
        --enable-bcmath \
        --enable-calendar \
        --enable-ctype \
        --enable-dom \
        --enable-filter \
        --enable-mysqlnd \
        --enable-pdo \
        --enable-phar \
        --enable-session \
        --enable-simplexml \
        --enable-tokenizer \
        --enable-xml \
        --enable-xmlreader \
        --enable-xmlwriter \
        "--with-iconv=$sdk/usr" \
        --with-libedit \
        --with-libxml \
        --with-zlib
    make -j4
)

cp "$source_root/sapi/cli/php" "$output"
chmod 700 "$output"
"$output" -n "$repository/tools/check-toolchain-capabilities.php" php "$output"
if strings "$output" | grep -F "$workspace" >/dev/null; then
    echo "Compiler PHP driver contains its private build workspace." >&2
    exit 78
fi
shasum -a 256 "$output"
