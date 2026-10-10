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

- [x] 4.3 准备 0.4.1 版本与发行说明、确认 SDK 材料绑定及新组件锁，完成实际索引审查和 pre-commit 检查，经 feature branch / PR 提交合并。
- [x] 4.4 完成双平台实际发行资源、原生 Windows CI 与安装消费者验证，生成并核对全部摘要，公开 tag/Release 后回读资产及保留验证边界。

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

版本准入最终审查（范围更正）：当时证明已覆盖的显式版本门禁移除；原“实际构建入口无剩余业务版本上限或精确白名单门禁”结论过宽。Workerman 5.2.3 仍被历史源码摘要拒绝，captcha 仍有 version/reference 门禁，全部依赖兼容尚未完成。Monolog、deepclone、native intl 三处可选适配移除版本/reference 标签前拒绝，保留完整源码/输出/SDK 摘要与后置条件；24 个分支回归通过（独审复跑 0.126 秒），包含较高主版本、预发布、未知及较低标签、源码/输出/SDK 漂移及重复包拒绝。profile 最低、低版本、99 主版本、预发布、元数据、未知 Carbon 源码证明及未知 ORM 明确诊断均通过，vendor 和固定 profile 原件保持不变。

发行预检发现并修复既有材料绑定缺口：DeepClone 之前将整份工具链 lock 摘要（当前前缀 5692）与旧 policy 前缀 79fc 比较，误用为 SDK 身份。现按实际 SDK 摘要、派生证据和头文件验证，保持固定 SDK 材料不变；默认 ProjectBuilder 入口已以当前仓库锁验证实际 SDK 路径并生成五份预期 shadow，独立审查及普通 PHP/原生 DeepClone 语义回归通过。此入口夹具在后续 overlay 前主动停止，不等于完整应用构建；不将专项回归当作完整链路验收。源码、发行包、完整应用及原生 Windows 验收边界保持前述说明；分支仍为 codex/build-compatibility，未提交、推送或发布。

后续发布授权：用户明确要求“提交发布”，覆盖本轮兼容修复的 feature branch 提交、推送、PR 合并及适当 patch 版本 tag/Release 资产公开。已只读核远端最新正式版本为 v0.4.0，拟交付 v0.4.1。原候选基线 05f33af 后的远端 main 六个提交仅包含已公开 0.4.0 交付记录与 README 调整；本轮不回滚它们、不改原脏工作区。版本字段及发行说明已准备，资产锁须由真实新包生成。目标仓库原无 pre-commit，已接入现有索引审查检查器并验证缺审查记录时拒绝；不改全局 hooksPath。Wiki、用户宿主、数据库不在此次发布写入范围。

发布材料准入审查：SDK 修复的六份源码冻结摘要、实际 SDK/头文件/派生证据/库文件与写入前安全拒绝均已独立复核，默认生成五份 shadow 摘要一致；ProjectBuilder 真实入口夹具通过（1.24 秒），证据 /private/tmp/webman-aot-compatibility-release/sdk/default-builder-result.json。Windows 新增实际 FileStream 句柄占用分支已静态审查，macOS 通用镜像回归通过，原生分支须由此次发行 CI 执行。

发布执行进度：双平台 0.4.1 组件已逐文件核验并生成实际锁；源码与组件锁已通过索引绑定审查及实际 pre-commit，提交 f09ec1a297ea66912835c07be4985e6b5fc77f38 已推送 feature branch，PR #54 已创建。两组件上传私有 Release 草稿后的服务端大小和 SHA-256 与本机一致，尚未公开发行。0026 补丁的五行空白上下文产生 Git whitespace 提示，已核为 unified-diff 必需前缀，完整补丁应用与幂等核验通过，未改变补丁数据。

首轮原生 Windows CI 37754001594 已完成 PowerShell 语法及双安装包构建，但镜像测试在文件占用分支前失败：迭代器保留临时目录短路径名，快照前缀却使用 realpath 后的规范路径，误报越界。修复将迭代根与比较根统一为规范路径，保留越界和符号链接拒绝；需独立审查与新提交原生重跑，当前不计 Windows 恢复验收完成。两平台最终安装包须绑定修复后的同一源码提交。

第二轮原生 Windows CI 已通过规范路径、激活重试及实际 FileStream 占用/释放恢复/永久失败清理；后续旧并发夹具失败。诊断提交 b579710 的原生日志确认触发 `project mirror copy drift: f-2.php`，产品已正确安全拒绝，而旧夹具将复制中竞争与复制后完整变更摘要混为同一时序。夹具现保留真实复制并发拒绝分支，并以独立激活屏障检查完整 added/removed/modified、安全路径、诊断长度、恢复指引及候选清理；不放宽为任意异常。最终包与验收须使用夹具修复后的统一提交。

最终原生构包回归：统一 native build revision 为 ac9368144bd03a1523e54af463e8b5cf035e6874。Windows CI 37755998044 的原生 PowerShell、双包构建、真实 FileStream 恢复/永久失败清理、短路径别名、复制并发拒绝/激活屏障诊断及工作区测试均通过，原机器结果与包已由 CI 保存。macOS 同提交的轻量/完整包完成 155/156 项 payload manifest、实际私有安装与 0.4.1 CLI 自检（5.5/29.3 秒），完整工具链 7,614 项校验、离线安装及重复校验通过。安装消费者和公开资产回读仍待后续步骤，不将此构包回归等同于完整应用业务。

最终资产绑定准备：Windows 实际轻量/完整 ZIP 已核原机器 receipt、原生日志 SHA、七个选取外 ZIP entry CRC、内部 157/158 项 payload 与 runtime 摘要，完整包内组件与锁定新 26 补丁归档一致；未下载重复组件段，不宣称外 CI bundle 整体 SHA 已核。Composer 0.4.1 runtime 锁从同 ac936814 两平台实际完整包生成；最终轻量 ZIP 包含 13 个源码相同文件（31,300 字节），真实隔离 Composer 生命周期 32 项、最终入口 20 项通过。README 同步远端 main 已公开的两行徽章说明删除，保持 PR 合并后 Composer ZIP 来源一致。公开 Release/消费者校验仍待门槛，不提前勾选 4.4。


最终公开交付：PR #54 已正常合并，v0.4.1 tag 固定于合并提交 d01de199abac7b9516db7db3fc5ca3b70be3d85a，Release 于 2026-10-08T10:00:22Z 公开。最终资产清单共 11 项，10 项文件摘要写入 SHA256SUMS；本机完整文件摘要、服务端摘要/大小与匿名公开可用性均一致。匿名实际下载 Composer ZIP、macOS setup.command、setup.zip 和 SHA256SUMS 后摘要通过；不宣称所有大资产重新整包下载。最终索引审查与真实 pre-commit 通过，原脏工作区、用户宿主和 Wiki 未发布或同步。

公开 Windows 消费者：CI 37760742170 成功（4 分 30 秒），原始回执绑定公开源码 d01de199、native 构包源码 ac936814、SHA256SUMS 5e7a33c3dd236fc77a72d5866aa67afc9611a3b84cdea8d4a263d744edb84de6。匿名 canonical URL 实际下载 SUMS/setup/轻量包/完整包并验摘要，小/全包新安装均为 0.4.1；完整包 109 单元编译与自动结构完整性校验通过。五个缓存场景复用数为 0/109/107/108/0；实际父进程和 PHP 子树中断后重试通过，复用 24/剩余 85、并发 78，临时资源清理为空且持久 PATH 不变。验证范围为构建宿主结构与完整性；Windows 未执行 Linux targetLdd，不扩大为完整 Laravel/ORM 应用验收。原回执和日志位于 /private/tmp/webman-aot-compatibility-release/windows/consumer-public-37760742170 与 run-37760742170-public.log。

发布后索引状态：官方 Packagist 已收录 v0.4.1（2026-10-08T16:07:09Z），source/dist reference 均为 d01de199abac7b9516db7db3fc5ca3b70be3d85a，dist 指向公开 0.4.1 Composer ZIP。用户本人完成登录后，通过专用 CLI 会话执行目标包 Update；未读取密码、token 或 cookie。全新任务隔离 Composer home/cache 的官方源 require 0.4.1 与真实 0.4.0→0.4.1 update 均 exit 0，两份入口均显示入口/目标构建器 0.4.1，13 个安装文件与已验发行 ZIP 一致，实际下载 ZIP SHA256 为 592e62f0000677c6d845ad5f22c453213ccb75905cdd260cc7f785fce79d393b。证据 /private/tmp/webman-aot-compatibility-release/packagist-consumers/result.json；本轮禁用插件和脚本，仅验包安装升级，不重复既有 32 项生命周期及原生公开消费者验收，不升级用户宿主或全局运行时。公开零参 invert() 限制及完整应用未验收边界保持不变，专用认证窗口保留；公开 tag 和资产不变。

## 5. 全部项目依赖兼容补修（0.4.1 后）

用户要求“把所有的版本锁定都改成兼容”。第 1–4 节保留历史实现、测试和发布证据，本节为当前待办。

- [x] 5.1 清除生成器 Boundary、Adapter 和 Plugin 的项目历史源码/输出摘要白名单，按实际结构及本次摘要构建；适配 Workerman 5.2.2/5.2.3 的 resetStd、引用捕获及受影响映射，保留输出集合、compiler coverage 和执行前后源码不变检查；按实际 trait/class/interface 依赖闭合编译输入，真实前端验证不得缺失 vendor 已有声明。
- [x] 5.2 ProjectDiscovery.dynamicPhp 的第三方 view 按真实动态 require 结构验证；CoveragePlanner 的 core/bootstrap/main 按实际结构证据规划；captcha 按字体引用与资源结构准入，清除 version/reference 和历史资源摘要门禁，保留安全路径、资源完整性及本次摘要。
- [x] 5.3 Monolog、intl 和 deepclone 全部项目源码按已确认语义结构及后置条件验证；逐项复核 Laravel/Illuminate、Carbon、ThinkORM、dotenv、Crontab example 与可选 CarbonDoctrine 桥实际入口，清除无依据的历史总匹配次数及版本/reference/历史整文件摘要准入，按每处调用语义和完整覆盖验证，安装接口与工具准备的 semver 上下限及硬编码 exact gate 也改为能力/manifest 绑定，默认工具链选择和材料完整性保持校验。
- [x] 5.4 用官方新旧依赖和固定真实生成器回归完整 generate 入口，覆盖旧新版本、较高/未知标签、等价结构、已适配幂等、未知结构拒绝、输入执行中漂移及工具链损坏；不以改写历史锁摘要的夹具替代真实兼容证据。
- [x] 5.5 完成受影响回归、可执行构建及独立复核，同步受影响兼容说明并严格验证原 change；分别报告源码、生成器、产物、真实平台和发布层结果。

### 本轮首轮冻结候选证据（待独审与完整联调）

- 可选适配：实际 `tests/optional-adaptation-versions.php` 使用固定上游生成器及已指纹校验的 SDK，通过 45 分支，32.585 秒。覆盖 Monolog、native Intl、DeepClone 的较高/未知标签、格式与无关源码变化、幂等、危险结构/输出及 SDK 损坏拒绝，并完成对应普通 PHP 行为对照和实际 Intl shadow 检查。命令、四份候选文件摘要、退出码及日志绑定见 `/private/tmp/webman-aot-dependency-compatibility/result.json`，日志 `optional-adaptation.log`。仅本专项，不等于完整 generate、AOT 或原生 Windows。
- 覆盖及资源规划：`php tests/generated-coverage.php <实际 framework vendor> <实际 captcha vendor>` 通过 29 场景，0.041 秒，包含官方四个 view、真实 captcha 字体、新资源范围、未知标签、证据缺失/篡改/漏编译及危险动态执行拒绝。`php tests/bootstrap-entrypoint.php <两份官方 framework 根>` 通过 18 场景，0.009 秒；`php tests/project-mirror.php` 通过，2.930 秒。候选绑定及三份原始日志位于 `/private/tmp/webman-aot-dependency-coverage-20261009/`，固定文件摘要为 `candidate-sha256.txt`。完整 generate/coverage/打包联调仍待执行。
- 编译前项目排除：`php tests/full-static-project-overlay.php /private/tmp/full-static-official /Users/code/project/ttt/webman/vendor` 通过 48/48，0.282 秒，覆盖官方 Crontab 1.0.6/1.0.7 与 CarbonDoctrine 3.2.0/3.2.1，各 24 分支；危险引用、自动加载、顶层执行、链接及 SDK 缺失均拒绝且不写 YAML，vendor 摘要未变。日志 `/private/tmp/full-static-overlay-regression.log`。对实际 Webman 项目两组排除引用的只读扫描通过，1.731 秒；不代表该项目编译或业务运行验收。

上述为首轮专项冻结结果，尚待独立审查；核心生成器及历史总匹配次数补修仍进行。5.1–5.5 当前保持未勾，尤其不得据此认定 5.4 完整回归或 5.5 独立验收完成。未提交、推送、发布或同步用户宿主。

### 独审返工及当前状态

首轮冻结结果是历史检查，不能替代返工候选的验收。独审发现 optional 的引用生命周期与 null scope 语义、captcha 的字体选择来源、Composer 注册表整文件豁免等漏洞，分别返工，不因原首轮通过而放行。

- Optional 当前切片已独立通过：实现者回归 48/48，35.803 秒；独审复跑 48/48，36.160 秒，父循环引用 alias 和传引用反例均拒绝且不写入，真实 apply 的普通 PHP scope/alias 对照保持 null/null（0.469 秒）。实际 TypePHP 0.9.2、PHP 8.4 full-static 前端生成五份 C++（0.620 秒），三个 scope 局部变量采用支持 null 的 php::Var；这仅为前端生成，不是原生编译或链接。最新命令、候选摘要及前端结果仍绑定 `/private/tmp/webman-aot-dependency-compatibility/result.json`。该专项没有新增版本/reference/历史整文件摘要准入；5.3 的其余入口与完整联调尚未完成。
- Coverage/captcha 返工首轮为 40 场景、0.056 秒，日志 `/private/tmp/webman-aot-dependency-coverage-20261009/generated-coverage-rework.log`、候选 `candidate-rework-sha256.txt`。选择来源闭合检查修复了最初反例；二轮仍有 extract 影响局部变量的实证问题，已进入有界诊断与继续返工，5.2 未验收完成。
- 编译前排除返工候选为 58/58、0.377 秒，真实项目只读引用检查 1.720 秒；Composer 注册文件仅在纯 literal 数组数据结构确认后豁免，static.$files 不豁免，其他执行引用照常扫描。独审 Composer 执行反例 1/1 通过，0.008 秒；日志 `/private/tmp/full-static-overlay-regression.log`。完整 registry/语义复审仍进行，不能据此勾选 5.3。
- 核心生成器对四个实证问题集中返工后，旧新官方源码完整 generate 各 189 项，合计实现者回归 5.210 秒；尚未独审通过，不将此结果视为 5.1 或 5.4 完成。

5.1–5.5 继续保持未勾；README 与 Wiki 仍标当前候选待完整验证、未发布。未提交、推送、发布或同步用户宿主。

### 最终冻结切片独审通过（完整构建进行中）

本段更新前述过程状态：全部冻结切片已完成独审返工复验。已核本工作区核心 13 文件与 `/private/tmp/webman-aot-core-20261009-refreeze.sha256` 一致，覆盖规划最终候选与 `/private/tmp/webman-aot-dependency-coverage-20261009/candidate-function-binding-sha256.txt` 一致。

- 5.1 核心独审复跑官方 Workerman 5.2.2/5.2.3：各 189 verified mappings，原 vendor 不变，4.853 秒；原四个反例、源码新增/删除、映射合同、未知 shadow、完整输入执行中变化均按预期拒绝。bounded 19、installer 5 及 Illuminate 216 普通 PHP 行为比较通过。历史总次数按逐调用绑定与完整覆盖认证，未知变量绑定、SSL 旧状态与受体语义保持安全拒绝。
- 5.2 Coverage/font 最终独审 64 场景，0.127 秒；纠正 alias 反例通过，0.005 秒，全限定函数正例继续接受。实际 GD/font 来源、选择范围、extract 及作用域绑定闭合，未知结构仍拒绝；最终绑定日志为 `generated-coverage-function-binding.log`。既有两官方 bootstrap 18 场景及镜像回归也通过。
- 5.3 Optional 独审 48/48、36.160 秒及 scope/alias 普通 PHP 对照保持通过。Crontab/Doctrine 排除的最终注册文件角色合同独审 70/70、0.469 秒，两个原 Composer 执行反例拒绝；函数、闭包、条件返回、业务 static 消费及 initializer 执行均不享受数据注册豁免。实际 SDK 材料仍严格核验，未知整文件角色仍逐 token 扫描，静态 files 不豁免。
- 5.4 已完成全部锁点切片的官方旧新输入、未知标签/结构、幂等、覆盖与输入漂移、工具链破坏安全拒绝专项回归及独立复验，按对应验收条款勾选；这些是源码与实际 generate 层证据，不是完整应用或平台业务验收。

统一隔离 fresh build 与 verify 正在进行，5.5 尚未完成。README/Wiki 将待构建结果后统一更新；当前源码候选未提交、推送、发布，旧发行 0.4.1 及用户已有宿主尚未升级。

### 统一 fresh build 集成失败（覆盖规划任务重开）

此前切片独审通过只证明对应测试与合同。统一隔离 fresh build 在实际 generate、overlay 之后进入 coverage，报 `generated coverage mapping evidence drifted: app/functions.php`，退出 78，15.626 秒，尚未进入 compile。实际证据为 `/private/tmp/webman-aot-compatible-build-20261009/build.log` 与 `private/logs/20261009T042658Z-9367ff40/diagnostic.json`，宿主 macOS arm64；诊断确认 execute 失败，不能把专项独审扩大为完整入口完成。

5.2 因实际覆盖规划未达标重新置为未完成，5.5 保持未完成。核心与覆盖作者在原授权范围内定位源码/适配证据链的根因；5.1 是否重开待责任层证据，尚未作出通过或失败的新判断。完整兼容文档仍为候选待验证，未提交、发布或升级用户宿主。

### 实际覆盖修复通过，第二轮编译依赖闭合失败

首次实际覆盖失败已在原范围内返工：Webman profile 接受 Boundary 已证明的 app/functions runtime 映射；stock bootstrap wrapper 保持实际 project alias 摘要，Request/Response 覆盖保留继承与编译闭合，安装期 support Setup 仅在真实 Composer 注册和无运行引用证明后排除。专项 85 场景、0.128 秒，实际 generate 189 → overlay → completePlanner 及失败镜像复验通过（2.957 秒）；该修复已独审，5.2 恢复完成。实际证据位于 `/private/tmp/webman-aot-dependency-coverage-20261009/runtime-mapping-entrypoint.log`、`actual-runtime-mapping-evidence.json` 和 `candidate-runtime-entrypoint-sha256.txt`。

第二轮 fresh build 已过 coverage/fingerprint，在编译前端报 `Trait Carbon\Traits\LocalFactory not found`，位置 `.typephp/build/carbon-interval.php:197`。构建器退出 70，25.331 秒；编译器退出 255，5.4 秒，尚未完成原生编译/链接。原 vendor 存在该 trait，核心正在核实际 trait 依赖闭合与加载规则，不能把生成器映射通过视为编译源码依赖已闭合。原生日志 `/private/tmp/webman-aot-compatible-build-20261009/attempt2/build.log`，诊断 `private/logs/20261009T044456Z-2d855470/diagnostic.json`。

因此 5.1 的源码依赖闭合重开并补实际 trait/class/interface 编译输入验证要求；5.4 已完成专项矩阵证据仍保留，但不代替未完成的完整构建。5.5 保持待办，文档仍标候选待验证、未发布。后续同范围必要修复与复测属于原任务授权，不新增宿主操作或发布授权。

### 实际生产依赖闭合与共享可选阶段候选

核心补充按 Composer installed 的生产包归属、require 和 PSR-4 声明闭合已验证 replacement 的编译来源，保留开发包排除及安全目录校验；共享 optional 阶段改为根据 active compiler sources 执行，避免普通 Webman 实际使用 Monolog/Intl/DeepClone 时仅因 profile 名称而漏掉适配。完整前端 prepare 与 fresh build 尚待执行及独审，5.1/5.5 继续未完成。

最新 DeepClone 等 optional 候选回归 68/68、68.762 秒已冻结，`/private/tmp/webman-aot-dependency-compatibility/result.json` 仍明确 completeAotBuild=false、nativeWindows=false。该扩展覆盖实际生产依赖的新形态，不能沿用此前 48 分支独审结论为新候选自动通过；待当前候选独审与实际前端/完整构建结果后统一结论。旧发行 0.4.1、用户宿主和 Wiki 均未发布或升级。

### 第五轮实际构建进行中

本轮直接必要修复包括 Native Intl 两处 ctype 调用改为内建识别（无扩展 `php -n` 验证），以及 CarbonDoctrine 桥安全排除跨 Webman/SaiAdmin 执行、仅 ignore 节去重。独审实际 Webman 七份桥文件 ignore 与引用拒绝通过，排除矩阵 76 项通过，仍保持注册数据角色、运行引用与未知结构安全拒绝。

第五轮 fresh build 已通过 generate、overlay、coverage、全量 prepare 1,760 份源码（排除七份未激活 Doctrine 桥）、convert 和 arginfo；原生阶段计划 1,621 编译单元，主控报告当前 379 单元且无错误。这是进行中状态，不能记为编译/链接完成；5.1 与 5.5 继续未勾，待最终链接、verify 和当前候选独审结果后统一判定。未提交、发布或升级用户宿主。

### 最终 fresh build 与独立 verify 完成

第五轮统一隔离构建完成，`build --fresh --json --profile=webman` 退出 0，约 493.3 秒；1,621/1,621 单元全部原生编译，复用 0，编译阶段 464.5 秒，链接、打包、自动结构验证及隔离 dist 原子激活完成。独立公共 CLI `verify --json` 退出 0，约 0.69 秒，明确 scope 为 `build-host-structure-and-integrity`，40 个产物文件、direct 55、shadow 11；macOS 宿主未运行 target ldd。命令及结果绑定 `/private/tmp/webman-aot-compatible-build-20261009/build-result.json`、`verify-result.json`、`build.log` 与 `verify.stdout.log`，ELF SHA256 为 `114de1b0f34c76a89cf07d0b1d79dcc81b620f226049241dd532623ed56ca700`。

该实际构建证明本轮生产依赖闭合与共享 optional 的最终编译入口完成，5.1 恢复完成；5.5 所要求的受影响回归、可执行构建、独立复核和兼容说明同步均已完成，按其原条款勾选。本任务没有完整应用运行、数据库业务或原生 Windows 验收要求，不新增这些要求或将现有结果扩大为对应通过。README 与三页 Wiki 候选已同步实际结果，OpenSpec 严格校验与文档/差异检查随最终同步核验。

当前仍为本地源码候选：未提交、推送、发布，Wiki 只在任务临时目录有草稿，global 0.4.1 与用户已有宿主没有升级；原目标与工具链最终只读核验由 runner 单独记录，不以此次构建推导宿主变更。


最终保护核：原工具链 7,627 项、隔离工具链 4,150 项摘要未变。原目标 8,299 项中其余 8,298 项一致；仅 composer.json 被其他写者在 12:39:35 改动 pagination/events 约束由 ^12 到 ^13，runner 未执行该更改。成功构建绑定本轮初始 ^12 manifest、原 lock 与已安装 payload 的隔离快照，不能声称原目标全部未变，也不代表最新 ^13 约束重新解析或构建通过。本轮未执行该新增约束的 composer update，不扩大为未来版本或新增依赖组合的完整业务保证。

## 6. 用户新要求重开：全部有效版本与内容准入

用户明确要求“不允许有上限，必须做兼容性处理，不允许有锁定版本的情况！！！！”。审计 `/private/tmp/webman-aot-compatible-dependencies/version-audit.md` 证明 fresh5 installed 快照成功仍遗漏有效 generator 的精确文本/历史次数、Carbon/ThinkORM 下限及 Composer plugin API ^2.0 上界等。此前 5.1/5.3/5.4/5.5 完成结论仅保留作前轮证据，现在全部重开；5.2 的已完成实际覆盖修复仍保留，不借此认定新的完整兼容目标已达成。

- [x] 6.1 完成审计列出的全部可达 generator 规则语义适配，覆盖 guard/helper、nullable/preload/session、switch/reference/cache 和全部依赖域，不只修五个反例；token 等价注释/空白、已适配幂等、变量绑定及未知语义安全拒绝纳入每个适用规则回归。
- [x] 6.2 移除 Carbon/ThinkORM 与 Composer/插件 API/构包入口的历史 semver 下限、上限及精确标签准入；PHP 仅保留入口已证必要 match 等语法的最小运行能力声明；根 Composer manifest 和打包生成 manifest 同步能力合同，未知或低标签满足能力时接受，能力缺失给出具体诊断。
- [x] 6.3 对工具准备、runtime/driver、SDK、编译器及补丁适用版本改为 manifest 材料声明与能力绑定，清除硬编码旧版本 exact gate；保留已验证默认材料选择、平台/ABI目标、下载身份、来源及 patch/source/hash完整性，不把取消标签门禁变成取消材料验证。
- [x] 6.4 独立复核审计完整清单并复跑受影响规则、安装接口/manifest、工具准备能力回归及新候选公开入口隔离全新构建与 verify；同步既有 README/Wiki候选，区分源码、构建、运行、发行和宿主状态，不沿用旧 fresh5 自动闭合。

源码/文档和本地必要修复复测属于用户明确授权；未授权提交、推送、Release/Wiki 发布、全局升级、宿主同步、服务或数据库操作。不得停在方案等待重复批准。

### 新兼容候选冻结专项状态（完整构建仍待）

当前各包 gate 已由主控独审核准：profile 23、Composer bridge 33、实际 Composer 生命周期 34、packaged 13 源文件一致；coverage 93 与显式 policy 的 actual runtime 194；core 全部 133 规则域/352 变换的 token/上下文合同、官方旧新各 194 映射及 bounded 19；tools 23 文件/31 能力检查、Doctrine 76、bridge 8、license 检查。实际语言声明 PHP >=8.0 来自入口 match/str_contains 的必要语法能力，Composer/API 使用 * 加公共接口/事件/arity 能力检查，不保留历史 2.5.3 或 2 主版本上界。验证环境为 PHP 8.4.18/Composer 2.9.5，标签 0.0.1/999 的 solver 模拟不是 Composer 3 原生或 PHP 8.0 运行验收。

材料仍依据当前 manifest 与实际 selected PHP/Redis/Intl SDK headers/摘要绑定，Windows 工具链本轮仅静态验证。Intl provider 的 13 场景及 actual 194 映射不同选定 SDK 模式专项通过，最终无扩展 PHP-n入口与小 helper 末次冻结仍进行。上述为源码/局部实际入口与独审，6.1–6.4 和重开 5.1/5.3/5.4/5.5 暂不勾，待最后生产接入与 freshbuild6 结果统一判定。未提交、发布或升级用户宿主。


### 中断恢复后的补核（当前全新构建仍待）

- [x] 6.5 补齐有限模板真实方法、分支与变量角色隔离；无关同形方法、闭包、新增 case 内 while 反例通过独审，Date 只改真实 default 终端赋值。
- [x] 6.6 移除 Workerman 历史七次总数准入；旧七处已适配与新增一处受支持 handler 的完整转换、幂等及无关 class 保留通过独审。
- [x] 6.7 移除 TypePHP CompilerBase 主动上下限与 composer PHP 上限，改真实能力检查；PHP 8.4.18/8.4.25 的 19 例和生产 source verifier/fingerprint 通过，精确允许 composer.json 并保留路径、符号链接和批准 SHA 校验。
- [x] 6.8 衔接原批准 26 补丁组件、新 27 补丁增量派生链及当前实际输出，保留原 approved SHA 并拒绝任意修改；独审后完成当前公开 build --fresh 与独立 verify，再判定 6.1–6.4 和重开的完整构建任务。

恢复轮 133 域/352 模板专项、Workerman 7→8 handler、四域实际 prepare/convert、有限 scope 反例及 CompilerBase 应用链通过。PHP 8.6/99 标签模拟不等于未来 PHP 实跑。独审 `/Users/supdger/.tmp/webman-aot-resume-version-audit-20261009.md`。完整构建首次在编译前因组件旧摘要拒绝，修复复测待；不沿用 fresh5 勾选当前构建。旧 `/private/tmp` 材料已不可读，前段仅保留历史记录。未提交、推送、发布或替换原安装。


文档恢复核验：既有 README 与 Composer README 同步当前能力/作用域策略；OpenSpec 严格校验和仅文档 diff 检查通过。隔离 Wiki 草稿位于 `/Users/supdger/.tmp/webman-aot-compatibility-docs-resume-20261009/wiki`，读取公开 HEAD `b6ff0dea9eb2dbd3093c90e570cae344f6ef06d6`，仅修改四个已有受影响页 Compatibility、Adaptation、SaiAdmin-Compatibility、Install。当前公开 Wiki 入口为 0.4.2、运行时仍为 0.4.1，保留原事实，候选策略明确标为未发布。本地 checker：20 页、115 本地引用、0 错误、1 既有导航 warning、56 外链未验；未推送。完整构建结果到达后再同步当前验收段落。


当前完整构建新增真实源码阻断：组件派生身份 gate 后，fresh 前端转换报 ErrorListener 的 interfaceReflection 未定义，尚未进入 C++ 编译。规范化同形 needle 缺失 do/foreach 的局部变量绑定角色；本轮继续对 352 模板中的同形碰撞和引入 locals 核对上下文合同。6.5 仅记录有限模板反例专项，不表示全部生产作用域已验收；6.8 和完整构建任务保持未完成。此失败属于实际转换规则缺口，不归为环境问题。


### 恢复轮最终实际结果

前述组件身份与 ErrorListener 局部绑定缺口均已修复并独审，最后当前候选公开 CLI `build --fresh --json --profile=webman` 退出 0，1,621 个 C++ 单元全部编译、reuse 0、编译阶段 292.8 秒，链接及打包成功。最终 ELF SHA256 `767ad9e8671ac8fc0af1d231526b774c66ff13f75d71184ccf9113e1bc03cb5f`。随后对新 dist-aot 独立执行公开 CLI `verify --json` 退出 0，约 0.492 秒，scope `build-host-structure-and-integrity`、40 文件、55 direct、11 shadow；target ldd 未在 macOS 构建宿主运行。实际日志 `/Users/supdger/.tmp/webman-aot-compatibility-resume-20261009/build-fresh-final-scopes.log` 与 `verify-final-scopes.log`，新产物 `/Users/supdger/.tmp/webman-aot-compatibility-resume-20261009/project/dist-aot`，不沿用旧 fresh5。

README、Composer README 与四页既有 Wiki 草稿同步当前结果。源码兼容回归、全新静态 Linux ELF 编译和构建宿主结构完整性已通过；Linux 原生应用运行、Windows 原生及业务验收未执行，不扩大此次通过范围。未提交、推送、发布或替换全局安装 0.4.1，公开 Wiki 未更新。


主控最终依据当前独审完整清单、76 项源码冻结与本轮最终 fresh/独立 verify 判定：5.1、5.3、5.4、5.5、6.1–6.4 和 6.8 在本任务源码、能力准入及本地构建范围完成，5.2 原通过保留。机器可读证据 `/Users/supdger/.tmp/webman-aot-compatibility-resume-20261009/result.json` 绑定当前候选、新产物和真实日志；旧 `/private/tmp` 不可读且不作为当前证明。Linux 原生应用运行、Windows 原生、业务、提交、推送、Release/Wiki 发布及全局安装升级未完成，不由前述历史 4.x 发布授权扩大本轮范围。此次未执行新的安装或部署。

## 项目编译职责纠正（2026-10-10）

- [x] 7.1 移除 SWITCH 表四条 binding→throw 及 Carbon 丢弃绑定结果降级，保留其它转换和材料校验；初始 REF 定位已纠正。
- [x] 7.2 项目可选转换仅处理三个明确不适用条件，整个 Switch 原文回退，严格接口及其它配置/输出错误不吞。
- [x] 7.3 完整 installed 133 域/352 模板回归、binding 原样、partial/known/adapted/strict-error/Str 原文及 Carbon 预步回退通过，作者最终 5.101 秒。
- [x] 7.4 实际项目隔离调用生产 ProjectBuilder/TypePHP；进入 compile，原报 ModelInfo::toArray() 必须精确 array 返回，最后 7.5 秒 exit255，未生成 ELF。原 PHP 合法，错误检查与调用区已核同锁定官方 commit。
- [x] 7.5 独立 source gate 17 项通过：四个 fixed hash 匹配、闭包绑定原样、整 Switch compact 预步回退、严格材料及非形态配置错误传播；原项目与 mirror 4,301 项摘要/权限/link 一致。独立生产链实际进入 TypePHP 并保留原 exit255 错误，无 ELF，不沿用历史完整编译成功。证据 `/private/tmp/webman-aot-binding-independent-20261010/probe-result.json` 与 `preservation-result.json`。

材料和日志位于 `/private/tmp/webman-aot-closure-native-20261010`，未写原项目/vendor/宿主运行时，先前 26 文件候选完整保留，本轮未提交或发布。

## TypePHP 必要条件接续修复（本轮）

- [x] 8.1 统一编译器保留普通 PHP toArray 原声明、参数、动态返回与继承合同，Native 方法仍验证必要签名；ProjectBuilder 已撤销先前镜像 array 返回注入，不再沿用接口 PHPDoc 补类型策略。
- [x] 8.2 按实际 SDK/helper 条件与安装生产 Composer 来源补齐输入，覆盖 PSR-0/4、classmap、files、prospective overlay、提供者顺序及来源缓存 SHA/祖先路径；受影响专项通过。
- [x] 8.3 真实失败后追加缺 trait 的来源诊断，19 项回归通过（含损坏元数据与 NUL 路径不替换原始错误），保留原始 fatal/退出码，不安装包、不创建空声明或跳过来源。
- [x] 8.4 使用现候选和已缓存工具链复测原生产锁与已有 Queue 的独立隔离副本；原锁 38.916 秒，Queue 副本 53.139 秒，均 builder 退出 1、TypePHP 退出 255，具体缺依赖见下。
- [x] 8.5 使用最终 A51 + G45 + B 统一候选和原生产锁完成实际完整编译、静态链接及隔离产物完整性验证；此项仅为 macOS 构建宿主产生 Linux x64 ELF 和结构/完整性验收，Linux 原生应用运行及业务未验。

此前镜像注入阶段的历史结果（已由普通 PHP 编译合同替代，不作为当前阻塞结论）：原生产锁实测越过 ModelInfo 返回声明后缺 `Illuminate\Queue\Attributes\ReadsQueueAttributes`，引用位置为 illuminate/bus v13.35.0 的 DebounceLock.php:13。仅在已有 Queue v13.35.0 的临时副本继续验证，三个实际 toArray 返回声明与全部 prepare 来源均已越过，随后缺 `Illuminate\Foundation\Bus\Dispatchable`，引用位置为 illuminate/queue 的 CallQueuedClosure.php:16。原项目依赖未修改；本轮没有安装 framework 或新增包。两项不是同一个依赖快照，不能将 Queue 副本当成原锁成功。

末次结果与原始日志：`/private/tmp/webman-aot-closure-native-20261010/resume-build-results.json`、`resume-original-lock.log`、`resume-queue-copy.log`。array-return-type 与 composer-source-completion 回归通过；缓存 SHA 拒绝测试改为精确实际诊断断言。README 与 Wiki 候选两页同步当前数组证明/接口合同及未 ELF 事实，未提交、推送、发布或同步宿主。

### 统一编译器接续状态

来源编译器成果已按固定基线逐项合入统一候选；完整补丁 pristine/after 身份、added 文件缺失条件与原子冲突保护保持校验。最终候选为 51 个编译器补丁、48 条身份规则、6 个新增编译器文件，加 G45 generator overlay 与 B。A49 switch selector 回归由 A51 修复并独验通过；最终原生产锁完整执行退出 0、615.792 秒，TypePHP 593.1 秒退出 0，2,036 个前端转换及 arginfo、1,890 个 Linux 目标原生编译单元、静态链接和隔离 dist 完整性验证均完成。最终 ELF SHA256 `d03bd693e9e5f0759f08a19c61057f47b02d76e4b5b648db17983586929e9e28`，产物验证 40 文件、82 direct、11 shadow，ldd 未在 macOS 构建宿主运行。原始证据 `/private/tmp/webman-aot-unify-A/build51.log`、`build51-result.json`；A50 产物只是此前基线，不作为最终候选证明。8.5 仅在上述源码、本地隔离构建与结构完整性范围完成；Linux 原生应用、多请求及业务运行未验，8.20 旧 nested finally return 缺口仍待办，不能声称所有项目或全部 PHP 控制流兼容。隔离 dist 写入不表示 Git、Release 或公开发布；本轮未提交、发布或升级宿主。

### A35–A38-r2 已验切片（完整编译未通过）

- [x] 8.6 依据目标 SDK 已验证的 possible-return 元数据保留参数入口合同，仅为适用函数体预留 PHP 动态值存储；未知结果、Native 与引用边界保持原处理。A35 独验只通过此切片，命名空间运行时反例由下一项修复，不能将 A35 单独称全运行时通过。
- [x] 8.7 按原 PHP 命名空间/全局函数查找顺序实现精确 Zend 调用、参数与返回合同、缓存与未找到时行为；manifest coverage 对真实补丁路径完整覆盖，A36-r2 独立前端、两个宿主运行进程与 owned 反例通过。
- [x] 8.8 保留 PHP 字符串与动态值 bitwise NOT 的结果、类型错误、警告及异常传播；A37 前端、普通 PHP 对照和两个宿主运行进程通过，不宣称全部语言运算已覆盖。
- [x] 8.9 对已解析普通 PHP 局部变量的已知混合值合并采用动态存储，保留读前写、分支、goto、unknown、Native 与引用边界；A38-r2 前端、两个宿主运行进程、闭包/Fiber、一次求值与 warm cache 独验通过。保留原件和既有 typed 入口 ABI，不强制更改项目签名。

四个独验原始记录位于 `/private/tmp/webman-aot-binding-independent-20261010/resume-diagnostic/a35/fixed-result.json`、`a36/fixed-result.json`、`a37/fixed-result.json`、`a38/fixed-result.json`；各项只证明其明确切片及宿主独立进程，未运行 Linux ZTS 多请求或完整业务。A38-r2 最终材料固定 `/private/tmp/webman-aot-unify-A/fixed-A38-source-r2.json`；这些切片本身不证明完整项目与 ELF；最终结果以 8.5 的 A51 原锁构建为准。

### A39–A41-r2 后续切片（完整编译未通过）

- [x] 8.10 从实际目标 SDK 归档与已验证静态注册取得内部原生类及完整方法合同；宿主 driver 未加载扩展不直接视为目标缺少扩展。A39-r3 的前端、继承、LSP、引用、final/readonly 和元数据边界独验通过；未据此前端结果宣称目标 allocator 运行时验收。
- [x] 8.11 函数调用的已知返回类型预测不提前解析参数或执行普通转换，合法 foreach 不再因预测阶段报局部变量未定义；实际调用的参数及未知边界继续校验。A40 正反例独验通过。
- [x] 8.12 仅对生产 Composer 惰性加载、可选且没有顶部副作用、声明图与必需依赖门槛均明确的缺失接口声明延后，实际请求保留可捕获 PHP Error；A41-r1 宿主运行对照已验且源码未变，r2 循环声明及已选提供者门槛独验通过；未运行目标 Linux ZTS 跨请求验收。

独验记录沿用 `/private/tmp/webman-aot-binding-independent-20261010/resume-diagnostic/a39/fixed-result.json`、`a40/fixed-result.json`、`a41/`；候选材料见 `/private/tmp/webman-aot-unify-A/fixed-A39-source-r3.json`、`fixed-A40-source.json`、`fixed-A41-source-r2.json`。这些有界合同不替代 8.5 的完整编译、ELF 和目标运行验收。

### A42–A44 与 G45 切片（完整编译未通过）

- [x] 8.13 普通 PHP 构造方法显式调用保留动态返回值，new 忽略返回值；不改 PHP 原声明，Native 必需合同仍验证。A42 专项独验通过。
- [x] 8.14 普通 PHP 析构方法显式调用保留动态返回值，自动析构忽略返回值并保持异常与清理顺序；A43 正式和父类/异常边界宿主对照独验通过。早期误用构造方法的试例不计入此证明。
- [x] 8.15 已解析普通 PHP Child/Base 局部赋值采用动态值存储，两个以上类合并及相关类合并保留真实值；typed 调用的实际 TypeError 与 Native 边界不放宽。A44 独验通过。
- [x] 8.16 generator overlay 仅过滤旧的无声明 toArray 强制 array 返回规则，保留实际上游输入字节、已声明类型、属性、默认值及其余 JsonResource 规则；不更改编译器 LSP。G45 正式、反例和缓存独验通过。

有界独验记录位于 `/private/tmp/webman-aot-binding-independent-20261010/resume-diagnostic/a42/fixed-result.json`、`a43/fixed-result.json`、`a44/fixed-result.json`、`g45/fixed-result.json`；冻结材料沿用 `/private/tmp/webman-aot-unify-A/fixed-A42-source.json`、`fixed-A43-source.json`、`fixed-A44-source.json`、`fixed-G45-source.json`。宿主专项运行不代表目标 Linux 多请求及完整业务验收；8.5 的最终 A51 构建结果见上方，目标运行仍未验。

### A45–A48 控制流与 catch 切片

- [x] 8.17 非整数 switch 认可现有合法 Goto 终结；最后一个物理 case 保留 PHP 自然结束，不扩大中间 case 的隐式贯穿能力。A45 初次嵌套 finally Goto 反例由 A47 修复后，与 A46 一并独验通过。
- [x] 8.18 Goto 依据目标 label 的词法 try/catch 边界，依次执行实际退出的 inner→outer finally，finally 异常取消待跳转；选择表达式、case 操作数与跳转副作用求值次数保持。27 个正式和 29 个独立边界用例分别通过 PHP 与两个宿主 C++ 进程对照，不作为目标 Linux ZTS 跨请求验收。
- [x] 8.19 已知 PHP catch 局部变量使用能够保留 scalar/Object 的值存储，保留异常对象身份、引用释放及回调次数，实际未定义参数仍报错。A48 前端、19 个正式和 21 个所有权边界用例宿主对照独验通过；throw API 与 Native 合同不变。
- [ ] 8.20 已证实的旧嵌套 finally return 缺口仍未修：没有 switch 的 nested try 中 inner finally return，PHP value=7、outer count=1，而宿主 C++ value=7、outer count=0。证据 `/private/tmp/webman-aot-unify-A/probe45-finally-return-runtime.php`。本轮 Goto 修复不代表通用 Return CFG 修复；原项目未实际报此模式，不扩大当前必要编译修复为全 CFG 重写。

独验记录 `/private/tmp/webman-aot-binding-independent-20261010/resume-diagnostic/a46a47/fixed-result.json`、`a48/fixed-result.json`；冻结材料 `/private/tmp/webman-aot-unify-A/fixed-A46-A47-source.json`、`fixed-A48-source.json`。前端转换、目标原生编译、链接、ELF 完整性及目标运行是分别验证的阶段，8.5 的最终 A51 构建结果见上方，目标运行仍未验。

### A49–A51 C++ 临时声明及 PHP 值生存期

- [x] 8.21 普通 foreach-list 已知混合局部值使用 PHP 动态值存储，保留 iterable 求值次数、空迭代、实际对象身份与释放次数；参数、typed、Native、引用和未知来源保持门槛。仅为 list 候选使用已证明布尔结果，循环赋值不当作可能为空迭代后的支配赋值。A50 独验通过。
- [x] 8.22 Goto 可跨越的 C++ 临时声明前置，但实际赋值保持原执行点；switch selector 的值仅在 PHP 原作用域内生存，跳出、返回或异常后释放。A49 初次专项不能覆盖后来已证实的作用域回归；A51 修订专项独验通过：原作用域 inside/outside/returned 均与 PHP 为 [1,true]，分组 Goto 及未执行 switch 求值次数保持；A49 旧回归由 A51 解决，最终 A51 原生产锁完整编译、静态链接及隔离结构完整性验证通过；Linux 原生运行未验。

A50 独验 `/private/tmp/webman-aot-binding-independent-20261010/resume-diagnostic/a50/fixed-result.json`；A51 冻结 `/private/tmp/webman-aot-unify-A/fixed-A51-source.json`，独验 `/private/tmp/webman-aot-binding-independent-20261010/resume-diagnostic/a51/fixed-result.json`。A50 静态 ELF 基线不替代 A51 修订后的最终证明；最终 A51 完整构建已通过，目标运行仍未验，8.20 旧 nested finally return 缺口仍待办。


最终尾核 `/private/tmp/webman-aot-unify-A/final51-verification.json`：server 为 202,168,632 字节的 x86_64 静态 ELF，PT_LOAD 存在、PT_INTERP/PT_DYNAMIC 不存在、NEEDED 为空；原项目 composer.json、composer.lock、installed.json 三个摘要与记录一致。最终 A51 7 项、联合 12 项源码身份及全部 48 个 prepared after 摘要一致。`final51-processes.json` 无匹配本任务编译、链接或监控进程；保留隔离材料供复核，未删除既有或共享资源。Linux 原生运行未执行，不将结构完整性视为业务验收。
