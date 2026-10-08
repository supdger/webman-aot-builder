## 1. Illuminate 时间间隔兼容

- [x] 1.1 校验固定生成器摘要并确认官方新旧九单位辅助函数变化，记录同组脆弱规则范围。
- [x] 1.2 在生成器 overlay 实现旧、新及等价写法安全识别，通过九单位语义、空白限定名、已适配、重复与未知结构回归。
- [x] 1.3 用固定生成器实际执行 overlay 验证输出与原 vendor 不变，完成可执行构建并记录未运行层。

## 2. Windows 镜像激活恢复

- [x] 2.1 实现有界重试、系统原因及目标冲突保护，回归正常、瞬时失败、永久失败及冲突。
- [x] 2.2 复跑项目镜像及 resumable workspace 测试，记录原生 Windows 验证证据或明确缺口。

镜像回归结果：macOS 上 `php tests/project-mirror.php`（2.746 秒）和 `php tests/resumable-workspace.php`（0.11 秒）通过，覆盖瞬时/永久激活失败、目标冲突、重试期间源码变动、失败候选清理及 attempt 隔离。语法和差异空白检查通过。已取得只读原生 Windows SSH 入口，但本任务临时宿主同步尚未获授权；故障注入不等于 Windows 文件占用实测，平台验证仍需后续原生 Windows 执行。

## 3. Webman/Workerman 源码形态兼容

- [x] 3.1 对已确认等价的新版本、空白及已适配写法采用源码结构优先验证，通过未知结构安全失败回归。

- [x] 3.2 对 Carbon 已确认同形版本与写法采用源码验证，保持分数、负数及 dispatch 语义，通过普通 PHP 与实际生成器回归。

- [x] 3.3 修复编译器目标探测对含空格可执行文件路径的错误转义，以实际 Application Support 工具链编译和直接路径回归验证。

- [x] 3.4 修复 CarbonInterval 已确认内部零参 invert 调用的编译参数计数差异及链式受体求值顺序，验证已适配幂等、未知安全拒绝和真实 12/13 AOT 分数负数对照。

- [x] 3.5 清除业务依赖版本上限及精确白名单，验证最低版本、更高版本、未知标签和未知源码诊断及原件不变。

## 4. 切片交付

- [x] 4.1 同步受影响兼容与恢复说明，OpenSpec 严格校验及相关工具回归通过。
- [x] 4.2 固定候选后完成独立审查，区分源码验证、真实平台、发行包及发布状态。

- [ ] 4.3 准备 0.4.1 版本与发行说明、确认 SDK 材料绑定及新组件锁，完成实际索引审查和 pre-commit 检查，经 feature branch / PR 提交合并。
- [ ] 4.4 完成双平台实际发行资源、原生 Windows CI 与安装消费者验证，生成并核对全部摘要，公开 tag/Release 后回读资产及保留验证边界。

## Illuminate 实现证据

固定 generator archive SHA256 与工具链锁 b70003e1e694f971446fd75b9d136dfc1794cfb664d3614ab68965919f732b91 一致。官方 13 相比 12 的 seconds/minutes/hours/days 改为显式 make，另外五单位仍为魔术静态调用。旧构造式不等价：Carbon 3.14.1 默认 seconds(1.5) 原调用 s=1/f=0，旧构造式 s=1/f=0.5，因此改用显式 __callStatic 保留 dispatch/宏/floatSetters。

`php tests/illuminate-interval.php <锁定generator根> <Carbon3.14.1根>` 实跑通过：官方九单位、幂等、限定名/别名/参数改名、注释/字符串保留、异常结构拒绝、实际 flattened 输出及 vendor 不变、混合未知不写 shadow、216 个正负及小数行为对照（floatSetters 开/关）和宏对照。原材料 /private/tmp/webman-aot-compatibility。源码与普通 PHP 回归不是完整应用或发布包验收；真实 AOT 切片结果见下述证据。

Windows 状态：SSH 只读连接已连通，原生宿主临时源码同步尚未获授权，尚未原生测试；macOS 镜像及工作区回归不代替 Windows 验证。


CarbonInterval 内部符号兼容：新领域规则与 universal generator entry/profile 源码核验已实现。四份官方 3.13.2/3.14.0/3.14.1/3.14.2 源码各 265 个普通 PHP 对照（共 1,060）通过，覆盖 signed/fractional/cascade/显式参数与公开零参方法原行为；生成器/profile 四实际源码矩阵通过（3.95 秒）。实际 `generateSwitchTerminalSources` 已验证 CarbonPeriod/CarbonInterval 最终 shadow、级联受体顺序及 vendor 原件不变；普通构建 `saiAdmin=false` 最终 Interval shadow 也通过（3.14.1 完整 21 项 0.29 秒）。3.4 真实 ELF 负小数对照及 4.2 独立审查均已通过。公开用户代码直接零参 `invert()` 的编译器参数计数限制未在本领域切片扩大修复。

真实 AOT 结果：当前 generator entry、内部符号修复及私有 0026 编译器 overlay 使用实际含空格 clang 路径，完成 PCH、28 个 C++ 单元及链接。Laravel 12/13 两个 Linux x86_64 ELF 均经 Docker CLI 运行，各 75 个业务输出与普通 PHP 基准一致（共 150/150），包含之前四个失败的负小数案例。证据：/private/tmp/webman-aot-compatibility/aot/result.json 及各版本 runtime.jsonl/compile.log，记录 artifact/helper SHA256。该切片运行不等于完整应用或原生 Windows 验收；公开用户代码直接零参 invert() 仍有编译器参数计数限制。Docker 启动、运行及任务资源清理由本次用户授权，清理确认由执行 Agent 记录。

文档结果：README 候选已同步；仓库外 Wiki 草稿仅更新 Compatibility/Adaptation。检查通过 20 页、117 个本地引用，0 错误、1 个既有 warning；54 个外链未实时检查。源码和 Wiki 均未发布。

独立审查：最终候选全切片通过；复核原始结果 JSON、普通 PHP 基准 150/150、当前源码/helper 摘要绑定、私有 SDK 两处编译器修改与 0026 manifest、生成 C++ 的真实受体顺序及静态验证器。候选保存于 codex/build-compatibility 分支，创建分支前后 HEAD 均为 05f33af0ae7b3ea108b25f42140eb41b1617b6af，全部源码差异仍未提交。未验收完整应用、原生 Windows 或发行包，未提交、推送、发布或同步宿主。

版本准入补充：SaiAdmin 实际 profile 使用 Carbon >=3.13.2、ThinkORM >=3.0.34 的下限比较，无上限或精确白名单；ThinkORM 下限来自既有 profile 锁基线，不等于更高版本完整业务已实测。四份官方 Carbon 源码各执行 10 项新增 profile 准入回归（最低、更高主版本、预发布、元数据、低版本、开发/未知标签），全部通过（6.77 秒）。MinimumVersion 直接 8 项回归及完整 19 规则/另官方 Framework 2.2.3、Worker 5.2.1/RuleEngine/Boundary 回归通过（1.454 秒）。Webman/Workerman 版本字段只记录最早已验证输入，不执行版本门禁，仍依据源码形态验证。新增版本准入文档同步及最后独立审查均已通过。

版本准入文档：README 与仓库外 Wiki 两页草稿已同步 profile 下限、无上限/精确白名单、工具链材料锁与业务源码检查区别；明确 ThinkORM 为既有基线而非本轮新业务验收。检查 0 错误、差异检查通过，公开文档未发布。分支保持 codex/build-compatibility，未提交或推送。

版本准入最终审查：实际构建入口无剩余业务版本上限或精确白名单门禁。Monolog、deepclone、native intl 三处可选适配移除版本/reference 标签前拒绝，保留完整源码/输出/SDK 摘要与后置条件；24 个分支回归通过（独审复跑 0.126 秒），包含较高主版本、预发布、未知及较低标签、源码/输出/SDK 漂移及重复包拒绝。profile 最低、低版本、99 主版本、预发布、元数据、未知 Carbon 源码证明及未知 ORM 明确诊断均通过，vendor 和固定 profile 原件保持不变。

发行预检发现并修复既有材料绑定缺口：DeepClone 之前将整份工具链 lock 摘要（当前前缀 5692）与旧 policy 前缀 79fc 比较，误用为 SDK 身份。现按实际 SDK 摘要、派生证据和头文件验证，保持固定 SDK 材料不变；默认 ProjectBuilder 入口已以当前仓库锁验证实际 SDK 路径并生成五份预期 shadow，独立审查及普通 PHP/原生 DeepClone 语义回归通过。此入口夹具在后续 overlay 前主动停止，不等于完整应用构建；不将专项回归当作完整链路验收。源码、发行包、完整应用及原生 Windows 验收边界保持前述说明；分支仍为 codex/build-compatibility，未提交、推送或发布。

后续发布授权：用户明确要求“提交发布”，覆盖本轮兼容修复的 feature branch 提交、推送、PR 合并及适当 patch 版本 tag/Release 资产公开。已只读核远端最新正式版本为 v0.4.0，拟交付 v0.4.1。原候选基线 05f33af 后的远端 main 六个提交仅包含已公开 0.4.0 交付记录与 README 调整；本轮不回滚它们、不改原脏工作区。版本字段及发行说明已准备，资产锁须由真实新包生成。目标仓库原无 pre-commit，已接入现有索引审查检查器并验证缺审查记录时拒绝；不改全局 hooksPath。Wiki、用户宿主、数据库不在此次发布写入范围。

发布材料准入审查：SDK 修复的六份源码冻结摘要、实际 SDK/头文件/派生证据/库文件与写入前安全拒绝均已独立复核，默认生成五份 shadow 摘要一致；ProjectBuilder 真实入口夹具通过（1.24 秒），证据 /private/tmp/webman-aot-compatibility-release/sdk/default-builder-result.json。Windows 新增实际 FileStream 句柄占用分支已静态审查，macOS 通用镜像回归通过，原生分支须由此次发行 CI 执行。

发布执行进度：双平台 0.4.1 组件已逐文件核验并生成实际锁；源码与组件锁已通过索引绑定审查及实际 pre-commit，提交 f09ec1a297ea66912835c07be4985e6b5fc77f38 已推送 feature branch，PR #54 已创建。两组件上传私有 Release 草稿后的服务端大小和 SHA-256 与本机一致，尚未公开发行。0026 补丁的五行空白上下文产生 Git whitespace 提示，已核为 unified-diff 必需前缀，完整补丁应用与幂等核验通过，未改变补丁数据。

首轮原生 Windows CI 37754001594 已完成 PowerShell 语法及双安装包构建，但镜像测试在文件占用分支前失败：迭代器保留临时目录短路径名，快照前缀却使用 realpath 后的规范路径，误报越界。修复将迭代根与比较根统一为规范路径，保留越界和符号链接拒绝；需独立审查与新提交原生重跑，当前不计 Windows 恢复验收完成。两平台最终安装包须绑定修复后的同一源码提交。
