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
- Monolog、deepclone 与 native intl 适配的实际版本/reference 等值门禁移除，完整源码摘要、输出摘要、结构后置条件及固定 SDK 材料锁仍保留；未知版本标签不影响这些已有完整证明，不新设无必要版本门禁。
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
