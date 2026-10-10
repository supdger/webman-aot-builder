## Purpose

将全局 Composer 命令入口的源码和发行维护归入已有构建器仓库，保证安装仅获取所需轻量入口并保持源码与发行布局一致，同时明确已发布运行时的版本和首次使用边界。

## ADDED Requirements

### Requirement: Lightweight installation
系统 SHALL 提供 saiadmin/webman-aot-builder 的轻量 Composer 发行包，仅包含注册元数据、必要命令入口、PHP 源码、锁定资源信息及许可文档。

#### Scenario: Dist installation
- **WHEN** 使用者以 Composer 从发行 ZIP 安装
- **THEN** 命令代理和自动加载正常，平台完整资源在执行 setup 或实际命令前不被下载

### Requirement: Source and dist parity
系统 SHALL 在原仓库根提供包元数据，源码安装和轻量 ZIP 安装 MUST 使用相同的 bin 与 autoload 路径。

#### Scenario: Source fallback
- **WHEN** 使用者选择源码安装
- **THEN** 相同的 webman-aot 代理可运行 help/version，包的自动加载不依赖完整 AOT 发行文件

### Requirement: Version and release isolation
入口 SHALL 使用新仓库发行版本 0.3.3 并明确目标运行时仍为已验证 0.3.2；新补充发行 MUST 不取代已有完整安装包的 latest 入口。

#### Scenario: Existing user
- **WHEN** 已安装原 0.3.2 的使用者选择 Composer 入口
- **THEN** 可以用代理完整路径确认入口 0.3.3 与目标 0.3.2，不覆盖原安装且旧 latest setup 下载路径保持有效

### Requirement: Verified component reuse across releases
The system SHALL prefer verified full caches and complete partial archives, and SHALL assemble upgrades in a separate candidate using the current locked target manifest. Raw lock equality MUST NOT replace prepared-file verification.

#### Scenario: Prepared source files changed
- **WHEN** unchanged files match the target and changed sources have trusted bundled replacements
- **THEN** the system downloads only the locked small package, copies verified files, validates the full candidate, and then activates it

#### Scenario: Reuse cannot safely complete
- **WHEN** a required file is missing, corrupt, or has no trusted target replacement
- **THEN** the system explains the reason and may use the locked full package while preserving the previous runtime on failure

#### Scenario: Installation commit fails
- **WHEN** candidate verification, marker creation, or activation fails
- **THEN** old runtime/bin/ready are restored consistently, or rollback failure stops and retains staging and backup diagnostics without further installation

### Requirement: Guide download parent lifetime
Interactive macOS guide downloads SHALL stop their owned curl when the supervising entry exits, including an already-TTY explicit or default menu. Cancellation SHALL preserve partial content, return control, and MUST NOT mark installation ready or proceed to an offline prompt after parent loss.

#### Scenario: Outer Composer command times out
- **WHEN** the parent entry is terminated while the interactive guide downloads resources
- **THEN** the owned curl stops, partial-file writes cease, the shell can accept another command, and retry can recover verified downloaded content

#### Scenario: Non-interactive default invocation
- **WHEN** no interactive terminal or an unattended environment is available
- **THEN** the existing guidance-only behavior remains and no recursive console restoration or resource preparation begins
