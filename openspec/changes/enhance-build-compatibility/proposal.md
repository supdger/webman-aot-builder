## Why

Laravel 13 的 Illuminate 时间间隔源码变化会触发固定文本兼容规则的零次匹配；Windows 构建还可能在项目镜像目录激活时失败且缺少底层原因。需要让已知等价写法能够构建，并让瞬时文件占用可恢复而不削弱源码与产物安全边界。

## What Changes

- 对 Illuminate 时间间隔调用按源码结构识别旧、新及已适配写法，保持语义和重复构建安全。
- 核查同一时间间隔规则组的固定文本断言，仅修复本次确认的同类兼容缺口。
- 对 Webman/Workerman 已确认等价源码形态采用结构优先验证，兼容新版本与写法变化。
- 为 Windows 镜像目录激活增加有限恢复与原始原因诊断，保留目标冲突、源码漂移和隔离检查。
- 业务依赖仅可采用已验证版本下限，不预设上限或用精确版本白名单拒绝较新版本；未知版本标签给出有依据的结构检查或诊断。
- 用直接相关回归及实际可执行的平台构建验证结果，明确源码测试与发布包验证的区别。

## Capabilities

### New Capabilities

- `build-source-compatibility`: Illuminate 时间间隔写法兼容及异常结构安全停止。
- `build-mirror-recovery`: 项目镜像激活的有限恢复、失败诊断和隔离安全。

### Modified Capabilities

无。

## Impact

影响锁定生成器的本仓兼容 overlay、ProjectMirror、相关测试与受影响兼容说明。基于 0.4.0 源码；用户后续明确授权提交发布，发行目标为 0.4.1，通过 feature branch / PR 合并、tag 与 Release 资产交付，不直接推送 main，不同步用户宿主或执行数据库操作。Wiki 保留草稿，未授权推送。原项目和 vendor 不被改写，临时材料保存于仓库外。
