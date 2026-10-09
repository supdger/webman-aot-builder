## Context

基于 origin/main 的 0.4.0，生成器固定提交及 archive SHA256。已取得并校验上游源码，问题位于 ProjectGenerator.php 的 vendor/illuminate/support/functions.php 分支：九个 CarbonInterval 单位调用采用精确文本与固定一次断言。Windows 镜像激活采用单次 rename；已有 attempt 隔离及源快照检查。

## Goals / Non-Goals

**Goals:** 按已确认源码形态兼容时间间隔转换；提高镜像激活可靠性且保留可诊断失败。

**Non-Goals:** 任意 PHP 语法自动重写、未知依赖的全版本承诺、绕过规则断言、用户宿主升级、通用编译器 ABI 改造及数据库操作。

## Decisions

- 沿用锁定生成器 overlay，保留原 archive/hash；通过 PHP token 识别真实调用和辅助函数，旧魔术静态调用转换到显式 __callStatic（保留宏及 floatSetters 语义），新显式 make 调用保持原表达式，保持注释与字符串内容。拒绝单纯扩大版本白名单及直接允许零次替换。
- 仅改本次已证实的时间间隔单位规则组；限定语义等价的表达式，已适配输入独立识别并校验。所有未知结构保持安全停止。
- Carbon 版本门禁与同形源码规则按独立范围修改，以实际源码和行为等价检查替代历史精确版本表。SaiAdmin profile 的 Carbon 与 ThinkORM 门禁统一改为最低版本比较，不设置版本上限；基线分别是已测试 Carbon 3.13.2 与既有 profile 锁定 ThinkORM 3.0.34。较新版本仍接受现有源码与输出校验，不能宣称所有新版本均已实测。
- 非可排序版本标签不能伪造最低版本结论：Carbon 可依据已有严格源码形态检查继续；ThinkORM 没有对应结构准入证明时给出无法确认最低版本的明确诊断。稳定版、预发布及构建元数据正确比较；不存在精确版本白名单回退。既有 VersionRange 清除上限参数并改为 MinimumVersion，历史已验证下限记录不阻止等价源码，工具链固定输入锁与 PHP 最低版本保持各自用途。
- Monolog、deepclone 与 native intl 适配的实际版本/reference 等值门禁移除，项目本次源码摘要、输出摘要、结构后置条件及固定 SDK 材料锁仍保留；未知版本标签不影响这些已有完整证明，不新设无必要版本门禁。
- 本仓 Webman/Workerman 规则采用源码结构优先验证，版本信息保留作诊断，未知结构不能靠扩大版本范围放行。
- 真实 AOT 暴露编译器 target 探测对含空格可执行路径错误转义，沿用现有命令执行边界正确引用路径并直接回归；不增加环境版本门禁。
- TypePHP 对非 variadic 方法的 func_num_args 返回声明参数数，CarbonInterval 内部零参 invert 失去 toggle 行为；限定已确认方法体与内部调用形态改为显式布尔 toggle，副作用链先存受体后调用，保持求值顺序。已适配输入幂等，未知结构停止。外部调用方直接零参 invert 的通用参数计数 ABI 不在本切片改造，仍是编译器限制。
- 镜像激活有限重试，并捕获原始文件系统错误；每次重试核对目标冲突，不使用递归复制回退暴露部分结果，不削弱 attempt 与源码一致性检查。
- 按后续发布授权交付 0.4.1：先确认最新正式发行版本与源基线，核实际 SDK 材料身份，重建绑定最新补丁的双平台组件与安装资源，再生成实际摘要和 Composer 绑定。Windows CI 运行精确 revision 的原生构包与公开消费者；提交以实际暂存索引审查记录和 pre-commit 核验为前提，经 feature branch / PR 合并，不直接推送 main。公开前后核资产摘要，已知编译器及平台验证边界保留；Wiki 不在本次推送范围。
- 过程材料位于仓库外；长期测试沿用仓库 tests/tools，OpenSpec 为唯一任务来源。两实现包独立执行，候选固定后统一独立审查。

## Risks / Trade-offs

- 新写法语义或 Carbon 构造语义变化 → 官方新旧源码夹具和普通 PHP 行为对照；全量 AOT 构建与平台验证独立报告。
- Windows 文件占用无法在 macOS 实证 → 故障注入验证分支并保留原生 Windows 验证缺口，不冒称平台通过。
- tokenizer 误识别注释、字符串或函数上下文 → 直接测试无关内容保持及异常输入拒绝，不做全局宽泛正则替换。

## 0.4.1 后补修设计

- 核查全部实际入口：Boundary 的 26 个 core 映射、Adapter 的 Worker shadow/adapted 与 Plugin 摘要、CoveragePlanner 的 core/bootstrap/main/captcha、ProjectDiscovery.dynamicPhp 的第三方 view、Monolog source/output、intl bootstrap/grapheme 和 deepclone 的五个用户源码。版本、reference 及历史整文件摘要不得作为项目依赖白名单。
- 沿用固定生成器 overlay，先按实际源码结构预检并生成准确预期映射/输出，再记录本次输入输出摘要。适配 Workerman 5.2.2/5.2.3 的 resetStd、引用捕获及七个受影响映射；拒绝只更新一个版本的历史摘要。保持唯一匹配、已适配幂等、声明输出集合和 compiler sources/ignore 覆盖，不能生成后无条件采信输出。
- ProjectDiscovery.dynamicPhp 的第三方 view 历史摘要门禁改为真实动态 require 结构验证，保留安全路径和覆盖闭合检查。
- CoveragePlanner 沿用本次结构证据规划覆盖；captcha 按实际 builder 字体引用与资源集校验，不再锁包标签或历史字体摘要。保留安全路径、符号链接、缺失资源、重复声明及执行中漂移拒绝。
- Monolog、intl、deepclone 与 Plugin 的项目源码采用已确认语义结构及后置条件验证，生成本次摘要；固定 generator/archive/profile/stub、SDK/头文件及发行资产摘要继续校验。Carbon/ThinkORM 等有依据的最低版本保留，无上限或精确标签准入，未知结构明确诊断。
- 既有证据只证明当时已覆盖的版本门禁及夹具，本次以真实固定生成器与官方不同版本源码验证完整 generate 入口，不把预先重算旧锁摘要的夹具当作新版本兼容证明。独立区分源码、生成器、可执行构建、平台及发布证据；本次不继承历史发布操作授权。

- 继续清除实际 compile 前 FullStaticProjectOverlay 中 Crontab example 精确标签/reference/摘要，以及无 DBAL 时 CarbonDoctrine 桥的标签/reference/七份源码摘要和历史文件集门禁；按实际受支持源码及完整文件覆盖验证，固定 SDK 材料锁不变。生成器 Illuminate QueryBuilder 的 compact 31、Blueprint compact 5、ThinkORM Collection 10 与 dotenv EntryParser 6 等历史总次数改为逐调用语义及覆盖闭合检查；未知转换结构仍拒绝，不能以取消次数断言直接放行。


### 实际构建暴露的生产依赖闭合

沿用 PluginSourceCompletion，在已验证 generated mappings 基础上读取隔离镜像的 Composer installed 生产包声明，补齐 replacement 所属包及实际 require 依赖的 PSR-4 声明目录；保留目录归属、符号链接、开发包排除及 existing sources/ignore 决策，不无条件扫描全 vendor。实际业务 support 与 plugin 源码仍按 discovery 覆盖。UpstreamProjectGenerator 在该 sources 闭合后，以 actual active compiler sources 决定 Monolog、Intl 与 DeepClone 共享可选适配，普通 Webman 与 SaiAdmin 均遵循同一合同；适配后刷新对应本次输出摘要。此改动因 fresh build 缺失 Carbon trait 与后续实际依赖入口问题直接必要，当前全量 prepare、独审和完整 build 尚待闭环，不继承已发布包通过结论。


实际 Webman 生产依赖也可能包含未激活的 CarbonDoctrine 桥，因此桥的声明/引用排除合同跨 Webman 与 SaiAdmin 执行，不依赖 SaiAdmin 名称。排除仅更新 ignore 节并在该节去重，不能因路径出现在 sources 等其他节而省略；仍不排除 DBAL 激活、已引用或未知执行形态。Native Intl 的结构识别使用 PHP 内建能力，已移除两处 ctype 扩展调用并以 `php -n` 核对，不增加用户安装额外扩展的要求。


## 再次纠正后的完整兼容范围

此前“保留有依据最低版本”决策已被用户新要求撤销：Carbon/ThinkORM、PHP/Composer/插件 API 与构包入口的 semver 下限及上限不再作为准入，改为真实所需能力诊断。未知标签采用同一源码/能力合同，不报无法证明最低版本。历史构建和基线数值只记录证据。

有效 generator 的余下 guard/helper、Barrier/Swoole 与 Context/Fiber、Symfony preload/nullable/session/kernel、switch/reference/cache 等实际规则全纳入此次实施，不仅修复五个反例。涵盖 JWT、brick/math、Sleep、Guzzle、MetadataBag，Carbon Date/Difference/Period/Interval，Symfony Request/File/Console/Session/Mime/Grapheme/VarDumper，Illuminate date/关系/resource/reflection/helpers，ThinkORM builder/timestamp，dotenv、PHPMailer、Monitor、Nelexa、IP2Region、Pool、Webman Console 与 SaiAdmin controller/cache/exception 等审计列出的有效域。逐方法识别语义和变量作用域，当前匹配与后置覆盖闭合；历史总次数可以保留为原输入记录，不能继续作为版本或格式门禁。未知转换保持写入前拒绝，不能把 token 化做成全文 token 白名单。

根 Composer manifest 与实际 composer-installer/package-composer 输出同步移除 ^2.0 主版本上界和 semver 下限，并验证当前所需 Composer 插件接口/调用能力；不只是改根 manifest。工具准备、包装、driver、SDK 和补丁检查依据 manifest 声明的实际版本/源码指纹/能力绑定，不硬编码 PHP8.4.25、clang19.1.7、TypePHP0.9.2 等标签作为唯一路径。默认材料与确定 Linux musl ABI 可以保留作为目标选择，具体来源/摘要及 patch before/after 身份继续核验；目标身份相等校验不是兼容白名单，不能简单删除供应链完整性检查。

范围以有效 production 调用链逐项闭合，包括本轮审计所有有效转换及根 manifest、composer-installer、安装准备/构包工具和目标能力检查。实施后重新完成等价/高低/未知标签、能力缺失、危险结构和 manifest 材料不符回归，以及公开入口隔离全新构建与 verify；旧 fresh5 不能代替新候选结果。


入口实际使用 match/str_contains，机器可读 PHP >=8.0 声明表达必要语言能力，不能以 PHP8.1 或固定发行 PHP8.4.25 等历史标签代替能力。Composer/API manifest 使用 *，activate 检查实际公共方法、事件、arity、传值与构造合同；真实已测 PHP8.4.18/Composer2.9.5 与 solver 标签模拟不宣称 Composer3 或 PHP8.0 原生已测。动态 view/captcha 历史摘要元数据改为显式领域 policy，实际结构及本次摘要仍闭合；未知 policy 不是可放行的版本标签。


## 中断恢复后的生产入口补核

- 短 token 转换必须确认真实类、方法、分支和变量角色，排除嵌套闭包与同形无关方法。Carbon Localization 只处理真实翻译循环；Date 属性写入只定位 set 的 switch 最后直接 default 终端赋值，不误改新增 case 内 while。PdoSessionHandler 及其余有限模板沿用同一边界。
- Workerman 历史七次命中不作为准入；旧七处已适配与新增一处受支持 handler 按本次实际命中完整转换并验证幂等。
- TypePHP CompilerBase 主动 PHP 标签上下限改为实际 64 位、扩展、函数、反射、解析器 API 与语法能力检查，composer PHP 上限移除。源码使用 property hooks，PHP >=8.4 表达必要语法能力。生产补丁验证器只新增精确根 composer.json，继续拒绝任意根文件、路径越界、符号链接和不符批准 SHA 的材料。
- 当前隔离完整构建首次在编译前因 minimal component 旧摘要拒绝；原批准组件是 26 补丁派生树，新补丁 before 是原始源码。新增 27 补丁增量派生链必须衔接原批准 baseline、当前批准补丁和实际输出，保留原 approved SHA，不能删除组件校验或接受任意修改。独审及完整 build/verify 仍待。

源码和实际 prepare/convert 专项不代替完整编译、链接或部署；旧临时产物已丢失，不作为本轮可回读证据。未提交、推送、发布或替换原安装 0.4.1。


恢复轮收尾：原批准组件至新补丁树的派生关系和同形模板局部绑定缺口完成修复与独审，当前全新构建 1,621 单元零复用、链接打包与独立结构完整性 verify 通过。前述“仍待”段落记录当时失败过程；当前结论以 tasks 最终实际结果及新日志为准，不推导 Linux 原生、Windows 原生或业务验收。
