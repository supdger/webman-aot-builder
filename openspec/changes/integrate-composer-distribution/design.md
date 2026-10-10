## Context

动机见 proposal.md。原仓库 main 为 159861d，运行时版本与最小组件锁绑定 0.3.2；已通过验证的 Composer 入口在额外仓库发布为 0.1.0。Packagist 官方实现直接保存 Composer dist 字段；缺失 reference 时 Composer 会填入源提交，因此无需制造提交自引用。

## Goals / Non-Goals

**Goals:** 同一仓库发布标签与入口为 0.3.3，默认取得轻量包，源码回退行为一致。

**Non-Goals:** 不升级完整运行时/SDK，不重打 0.3.2，不提供编译断点，不删除独立仓库或旧版。

## Decisions

- 根 composer.json 唯一注册包 metadata，bin 与 PSR-4 都指向 packages/composer-installer；ZIP 保持嵌套路径。入口 bin 自载三个源码文件，不借 PATH 查同名命令。
- dist URL 固定原仓库 v0.3.3 的 webman-aot-builder-0.3.3-composer.zip；不填写未知 reference 或递归 shasum。发行工具独立产生 SHA256SUMS 并检查每一成员与源码相同、九文件且小于100KB。
- 原 src/Version.php 与组件锁保持 0.3.2；Composer 入口版本改0.3.3。根 composer.json 不写 version，由新标签决定包版本。
- Composer 补充 Release 必须 --latest=false，README现有 latest setup入口不能被破坏。旧版 registry 保留及仓库切换由发布 gate 明确核验，不在本地实现阶段执行。

## Risks / Trade-offs

[源码安装会下载完整仓库] → 文档明确 --prefer-source 与默认轻量 ZIP 区别。

[Packagist仍需实际公开验证] → 本地 dist 代理检查不能替代公开 p2及default globalrequire；发布后作为最终 gate。

[新仓库缺旧包版会被soft-delete] → 切换前由维护者核对兼容tag保留策略，不动原已占用v0.1.0。

[Windows/PHP8.1缺真实环境] → 保留尚未验收说明，不把原0.3.2平台实测当新桥接实测。

## Migration Plan

候选审查后一次确认原main/新tag/非latestRelease/小ZIP/Packagist仓库切换/旧版保留/Wiki发布范围；未获确认不执行。失败保持旧registry入口与0.3.2完整资源，不移动旧tag。

## Component reuse and installation commit

The small package preserves the component ZIP manifest bytes and their locked SHA. It includes all reviewed TypePHP after-files from a verifier-approved prepared source, plus exact target manifest/lock/prepared/derivation metadata. Each copied file must match its target SHA or the existing reviewed before/preparedBefore-to-after guard. Copies share no hardlinks; root and entry ancestors cannot escape via links. A candidate is validated before activation. Complete full caches and complete valid .part files take precedence. Otherwise, an owned previous runtime can attempt a locked small upgrade. A safely rejected candidate falls back to the locked full package with a reason. The bridge installs in a staging root, verifies private PHP, then backs up and switches runtime/bin/ready under setup.lock. Commit failure restores all old roots and marker. Rollback failure stops without full fallback and preserves diagnostics. Resource metadata is derived from actual archives, never invented sizes or hashes.
