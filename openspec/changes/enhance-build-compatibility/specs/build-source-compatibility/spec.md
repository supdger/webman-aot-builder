## Purpose

让项目使用者在升级 Illuminate 或遇到等价源码写法时仍可完成构建，同时保持未知结构安全拒绝、重复适配稳定以及原项目源码不变的既有交付边界。

## ADDED Requirements

### Requirement: 时间间隔写法兼容
系统 SHALL 对已确认的 Illuminate 12 与 13 时间间隔辅助函数及其等价空白、限定名写法生成保持单位与参数语义的编译源码，重复适配 MUST 保持结果不变。

#### Scenario: 新旧源码构建
- **WHEN** 输入为已确认的 12 或 13 时间间隔辅助函数
- **THEN** 所有单位辅助函数正确适配，原 vendor 不变

#### Scenario: 等价及已适配输入
- **WHEN** 输入使用等价空白或限定名，或已经完成该适配
- **THEN** 适配保留语义且再次执行结果不变

### Requirement: 无法证明等价时停止
系统 MUST 拒绝缺失、重复、错误参数或无法证明等价的时间间隔结构并指出文件及规则；不得通过版本降级或忽略零次匹配实现通过。

#### Scenario: 异常结构
- **WHEN** 输入丢失辅助函数或包含未知调用结构
- **THEN** 构建停止且不发布产物

### Requirement: 源码形态优先的版本兼容
系统 SHALL 对已确认与现有规则等价的 Webman/Workerman 源码形态继续构建，即使依赖版本号不同；等价空白及已适配源码 MUST 安全识别，未知结构仍须停止并报告原因。

#### Scenario: 等价源码的新版本
- **WHEN** 依赖版本变化但被适配源码保持已确认语义
- **THEN** 构建按源码结构验证后继续，不要求使用者降级

#### Scenario: 新版本未知结构
- **WHEN** 源码无法匹配已确认或已适配结构
- **THEN** 构建停止并报告受影响规则与源码原因

### Requirement: 时间间隔负数小数运行一致
系统 SHALL 对已确认的 Illuminate 单位辅助函数路径保持普通 PHP 的负数、小数和 floatSetters 开关行为，在 AOT 运行中保持值分量及反向标志一致。

#### Scenario: 负数小数开启级联
- **WHEN** 使用者启用浮点 setter 后调用已确认单位辅助函数并传入负小数
- **THEN** AOT 的分量及反向标志与同源码普通 PHP 结果一致

### Requirement: 依赖版本不预设上限
系统 MUST 不以预设版本上限或精确版本白名单拒绝更高业务依赖版本；已验证基线只记录历史证据，MUST NOT 作为最低版本门禁；兼容判断 MUST 使用能力、源码及产物检查。

#### Scenario: 更高和更低版本
- **WHEN** SaiAdmin 使用可排序的 Carbon 或 ThinkORM 版本
- **THEN** 不论标签高于或低于历史基线，均执行相同能力与源码输出校验；不以 semver 上下限或白名单拒绝

#### Scenario: 未知版本标签
- **WHEN** 锁文件版本为无法排序的开发分支或其他标签
- **THEN** 执行相同能力和源码形态检查；能力无法证明时报告具体能力、文件及原因，不以无法确认最低版本拒绝

### Requirement: 全部项目依赖入口按实际结构准入
系统 MUST 将项目依赖的版本、reference 和历史整文件摘要准入改为已确认源码或资源结构判断；规则、生成器、后置适配、覆盖规划及运行资源入口 MUST 使用一致边界。工具链固定材料与本次输入输出完整性 MUST 继续校验。

#### Scenario: 实际生成器处理新旧 Workerman
- **WHEN** 完整 generate 入口使用官方 Workerman 5.2.2 或 5.2.3 及已确认 Webman 源码
- **THEN** resetStd、引用捕获及受影响映射按实际结构正确转换，覆盖完整且原 vendor 不变，不因历史版本、reference 或摘要拒绝

#### Scenario: 其余项目依赖适配入口
- **WHEN** Laravel/Illuminate、Carbon、ThinkORM、Monolog、deepclone、intl、Plugin 或安装器满足已确认结构和所需能力
- **THEN** 完整入口继续源码及输出验证，不以历史标签或整文件摘要作为替代版本白名单

#### Scenario: 验证码源码与资源变化
- **WHEN** captcha 标签改变且实际字体引用和资源集满足已确认结构
- **THEN** 按本次资源生成部署内容及摘要；缺失、越界、符号链接或未知引用结构时停止并报告文件和原因

#### Scenario: 未知结构或输入变化
- **WHEN** 依赖转换结构无法确认或输入在生成期间变化
- **THEN** 构建停止且不发布产物，指出依赖、文件及规则原因，不要求用户降级绕过诊断

#### Scenario: 固定工具链损坏
- **WHEN** 固定生成器、profile、stub、SDK 或发行材料摘要不匹配
- **THEN** 构建仍拒绝损坏材料，项目依赖兼容策略不放宽工具链完整性

### Requirement: 实际生产依赖编译闭合
系统 MUST 按已验证 replacement 的实际生产包归属、Composer 依赖及声明路径闭合编译输入；可选适配 MUST 按实际 active compiler sources 决定执行，不仅限于固定 profile 名称。

#### Scenario: 新版本新增运行依赖声明
- **WHEN** 已支持 replacement 使用项目已安装生产依赖中的 trait、class 或 interface
- **THEN** 完整前端可取得所需声明，来源与声明路径安全且不将开发依赖或整个 vendor 无条件加入

#### Scenario: 普通 Webman 激活共享可选适配
- **WHEN** 实际编译来源包含 Monolog、Intl 或 DeepClone 的受支持入口
- **THEN** 执行同一语义适配及 SDK 检查，不因 profile 不是 SaiAdmin 而漏掉必要转换

#### Scenario: 未启用 Doctrine 桥跨 profile 安全排除
- **WHEN** Webman 或 SaiAdmin 缺少 DBAL，桥源码仅包含已确认声明且没有运行或自动加载执行引用
- **THEN** 以相同排除合同在 ignore 节记录桥文件且不重复，存在引用或未知执行结构时停止，不能因 profile 不同而漏掉必要排除

### Requirement: 安装接口与编译材料按能力绑定
系统 MUST 对安装接口和适用编译材料按实际能力、接口合同及 manifest 绑定判断兼容，MUST NOT 以 semver 上下限或代码硬编码精确版本作为准入。已验证默认工具链 MAY 保留；入口实际使用的必要语言语法能力 MAY 以最小运行时声明表达，MUST NOT 伪装成历史材料版本锁；下载目标身份、补丁源码身份及材料完整性 MUST 继续核验。

#### Scenario: 不同 Composer 或运行时版本
- **WHEN** Composer、插件 API 或构建 PHP 的版本标签变化而实际所需接口与能力可用
- **THEN** 安装与入口可继续执行，不以主版本上限或历史最低版本拒绝；能力缺失时报告具体接口或能力

#### Scenario: 新工具链材料
- **WHEN** 编译器、运行时、driver 或 SDK 材料变化且 manifest 声明与实际能力、适用补丁和来源完整性一致
- **THEN** 按该 manifest 绑定材料并验证，不以硬编码旧版本拒绝；补丁或摘要不匹配仍安全停止

### Requirement: 有效生成器转换覆盖全部规则域
系统 MUST 处理实际可达生成器的全部转换规则，按真实 token 语义、唯一结构和转换后覆盖验证，不以历史格式或总命中数拒绝等价源码。已适配输入 MUST 幂等，未知语义 MUST 明确拒绝。

#### Scenario: 五个真实等价反例
- **WHEN** JWT、brick/math、Illuminate Sleep、Guzzle MessageFormatter 或 Symfony MetadataBag 仅增加不改变有效 token 的注释
- **THEN** 转换与原件语义一致，未知或危险结构仍拒绝

#### Scenario: 其余实际规则域
- **WHEN** 实际使用的 guard、helper、nullable、preload、switch、引用捕获及 cache 规则输入为已确认等价结构
- **THEN** 每个可达转换按当前语义和完整覆盖验证，不沿用历史固定总数或文本格式门禁


### Requirement: 转换限定实际语义作用域
系统 MUST 在真实类、方法、分支和变量角色内转换，MUST NOT 因短 token 同形修改无关方法、闭包或新增分支。新增受支持位置 MUST 按当前真实命中完整处理，不以历史总数拒绝。

#### Scenario: 无关作用域出现同形表达式
- **WHEN** 无关方法、闭包或新增 case 内循环具有同形短表达式
- **THEN** 这些作用域保持原语义，只有已确认目标位置被转换

#### Scenario: 新增受支持 handler
- **WHEN** Workerman 旧七处已适配且新增一处受支持 handler
- **THEN** 新位置正确适配且幂等，不以旧七次总数拒绝

### Requirement: 批准组件与补丁派生身份衔接
系统 MUST 核对原批准组件、当前批准补丁及实际输出的派生身份关系，MUST NOT 将可验证补丁结果误判为组件漂移，未知修改 MUST 继续拒绝。

#### Scenario: 原组件应用新批准补丁
- **WHEN** 原批准派生树与新补丁输入输出链具有完整校验证据
- **THEN** 验证该关系后继续编译，其他组件文件仍逐项校验且原批准摘要不改变

#### Scenario: 未批准修改
- **WHEN** 文件不符合原批准摘要或可验证补丁派生关系
- **THEN** 构建停止并指出文件，不跳过完整性检查


#### Scenario: 同形模板依赖不同局部绑定
- **WHEN** 相同规范化表达式分别出现于 do、foreach 或其他控制流而局部变量来源不同
- **THEN** 转换验证真实变量绑定及所属控制流，不将其他上下文误作目标，不引入未定义变量
