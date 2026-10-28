# Source licensing and upstream attribution

The Webman AOT Builder application code is provided under this repository's
[MIT license](LICENSE).

The files in `toolchain/patches/typephp/0.9.5/` are diffs against
[TypePHP v0.9.5](https://github.com/swoole/typephp/tree/v0.9.5).
That version declares `GPL-3.0-only` in its `composer.json`, and the diffs
include context from TypePHP source files. The repository's MIT license does
not relicense that upstream material. The patch manifest records the exact
upstream file hashes expected before each modification. The modifications
prepared by this project as of 2026-09-26 are supplied under `GPL-3.0-only`.

Toolchains downloaded by the CLI, locally assembled installer archives, and
generated Linux executables contain additional third-party components with
their own license terms. The v0.1.3 Mac installer includes PHP 8.4.25 built
with static-php-cli 2.8.5 and its collected runtime notices; its separate
compiler-driver uses macOS system libraries. PHP's bundled `libmbfl` in the
Mac CLI and `libbcmath` in the compiler-driver use LGPL-2.1; their license
texts are included in the Mac installer. Corresponding source and relinking
materials are in a separate v0.1.3 Release asset, described in
[Mac runtime source and relinking](https://github.com/supdger/webman-aot-builder/wiki/MacOS-Runtime-Source).
The Windows installer includes
a selected subset of the official PHP 8.4.26 x64 distribution and its license,
redist notice, and upstream SBOM. Both installers include TypePHP's GPL-3.0
license beside the patch set. The v0.1.3 small installers acquire the locked
minimal toolchain component at first use; the full installers bundle that same
component. It contains selected files from TypePHP 0.9.5, PHPx SDK 2.9.4,
LLVM/Clang 19.1.7, and other locked inputs rather than the complete upstream
archives. Upstream license terms remain in force for those selected files;
their exact sources and digests are recorded in `toolchain.lock.json` and
`toolchain/minimal-components.lock.json`. This repository's MIT license does
not relicense them. LLVM/Clang 19.1.7 is covered by
[Apache-2.0 with LLVM exceptions](https://llvm.org/LICENSE.txt); the license
text is included in both installers under `THIRD_PARTY_LICENSES/`.

The historical v0.1.3 [Release assets](https://github.com/supdger/webman-aot/releases/tag/v0.1.3)
contain checksums for that release. They retain the old `webman-aot` package
identity and are not checksums for the Webman AOT Builder v0.2.0 release. The
[verification record](https://github.com/supdger/webman-aot-builder/wiki/Verification) describes what was tested; a
passing package check is not a claim about every target server or third-party
plugin.

## v0.2.0 macOS runtime provenance

The v0.2.0 macOS runtime was rebuilt with static-php-cli 2.8.5 and
PHP 8.4.25. Its CLI contains PHP's `libmbfl`; the separate compiler-driver
contains `libbcmath`. Both are covered by LGPL-2.1, and their license texts
are included in the installer and the relinking materials.

The relinking-material asset is named
`webman-aot-builder-0.2.0-macos-php-relink-materials.tar.gz`
(137,607,655 bytes; SHA-256
`380880904e1aaa444d1c3485eb0a2733d47bc4fd8ec6e2fd7499ed45d384eeda`).
The archive generated on 2026-09-27 contained 59,944 files and 1,447
directories; all entries matched the embedded member manifest and passed the
path-safety audit. In an isolated extraction, both the modified CLI and
compiler-driver sources were rebuilt and relinked; 934 generated metadata files
had to be rewritten to the isolated paths for that test. The original archive
preserves its original build paths. The modified binaries were not deployed and
do not represent the locked release binaries. For current online publication
status and asset links, see the
[v0.2.0 GitHub Release page](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.0).
See
[v0.2.0 macOS runtime source and relinking materials](https://github.com/supdger/webman-aot-builder/wiki/MacOS-Runtime-Source)
for archive contents, exact commands, and the limits of the relinking evidence.
