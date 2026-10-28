# CLAUDE

## 项目

Webman AOT 工具链：把 Webman/PHP 项目编译为 AOT 产物，含 installer、toolchain、compatibility、dist、packages 等模块。

## 入口

- 项目说明：[README.md](README.md)（英文） / [README.zh-CN.md](README.zh-CN.md)（中文）
- 变更历史：[CHANGELOG.md](CHANGELOG.md)
- 规格与变更：`openspec/`（`openspec/specs/` 现行规格，`openspec/changes/` 本次变更）。改动跨模块行为或需要持续交接时走 OpenSpec 流程，先读 `openspec/config.yaml` 与当前 change，不重复建提案。

## 工作约定

- 构建、兼容性验证与安装器行为是本项目的主要风险面；改 `toolchain/`、`compatibility/`、`installer/` 前先看 `tests/` 中既有覆盖与 `compatibility/` 的既有约束。
- `toolchain.lock.json` 锁定工具链版本，不擅自升级或改写；需要变更时单独说明理由与影响。
- `dist/` 是产物目录，不从其中反推源码改动，也不把临时产物提交为源码。
- 全局规则见 `~/.claude/rules/`：交付约定 `flow-delivery.md`、编排 `orchestration.md`、构建口径 `vite-frontend-dev.md`（前端）、密钥 `secret-hygiene.md`。
- PHP/AOT 业务实现细节用 `/php-webman` 技能；金额与执行语义见 `rules/references/`。
