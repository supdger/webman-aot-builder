## Why

Composer 入口已验证但位于额外独立仓库，使用者希望源码与构建器归于同一仓库、发行版本保持一致。普通 Composer 安装仍应只取得轻量入口，避免下载整套工具链或仓库素材。

## What Changes

- 将已验证入口迁入原仓库 packages/composer-installer，根 composer.json 作为唯一注册元数据。
- 仓库新标签及入口采用 0.3.3；已发布完整运行时固定 0.3.2，不移动旧标签、不伪造全平台新包。
- 发行轻量 ZIP 的 bin/autoload 路径与源码安装完全一致；生成及验证发行文件与元数据。
- 补充首次使用说明与版本含义，发布时明确不将仅 Composer 的补充 Release 设为 latest。

## Capabilities

### New Capabilities
- `composer-distribution`: 原仓库的轻量 Composer 发行、源码回退及版本边界。

### Modified Capabilities
无。

## Impact

仅涉及根 Composer 元数据、packages/composer-installer、轻量打包工具和受影响文档/OpenSpec。原 AOT src、installer、工具链锁与 0.3.2 资产保持不变。本次先交可审候选，原 main 推送、Release 和 Packagist 仓库切换须主控确认授权范围。

## Follow-up: verified component reuse

Upgrade preparation reuses existing complete caches and assembles a separately verified candidate from target-locked files. Existing published 0.4.3 assets remain unchanged. New packaging and release consumption require independent acceptance before publication.
