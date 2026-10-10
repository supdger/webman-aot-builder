## ADDED Requirements

### Requirement: 有限发现并静态显示安装

系统 SHALL 从已知管理根、显式管理根、PATH 固定入口名及 Composer 全局元数据发现安装，显示类型、版本、绝对路径、用途、卸载影响与可卸载状态；不得运行未知入口探测版本或扫描项目成果。

#### Scenario: 多个版本与重复 PATH
- **WHEN** current、多个版本代次、备份、Composer状态与重复路径同时存在
- **THEN** 每个真实安装只显示一次，同目录不同版本分别显示，无法静态证明的版本显示未知

### Requirement: 逐项默认保留

系统 SHALL 先列出已识别对象，再对每个可卸载项显示其精确路径和卸载影响并询问 `y/N/q`，空输入或非交互保留，EOF/q 停止，且 `--list` 全程只读。

#### Scenario: 保留卸载与中断
- **WHEN** 使用者依次输入空白、y、q
- **THEN** 首项保留、第二项精确卸载、余项不处理，结束显示结果与耗时

#### Scenario: 非交互与 EOF
- **WHEN** 输入不是终端或提前结束
- **THEN** 无确认的对象不删除，不下载、不创建状态、不等待缺失输入

### Requirement: 所有权与删除边界

系统 MUST 在删除前重新核验所有权与真实路径，拒绝系统根、用户根、项目根与链接穿越；不得删除共享工具链、未知目录或项目dist，不恢复旧入口。

#### Scenario: 删除当前或活动代次
- **WHEN** 使用者选择当前/活动版本
- **THEN** 只删除该项与可证明绑定该安装的公开入口，保留版本不会自动成为公开活动入口，备份命令不恢复

#### Scenario: 未知与链接
- **WHEN** 入口或目录没有归属证据或路径包含符号链接/reparse
- **THEN** 如实说明保留原因且不运行入口、不删除对象

### Requirement: Composer 与执行中的卸载

系统 SHALL 在不准备运行时的前提下提供 Composer 卸载入口，全局包只由 Composer CLI 移除精确包且保留其他全局工具；原生 Windows 同步运行临时可信运行时完成卸载，失败返回非零并说明残留。

#### Scenario: 全局包移除失败
- **WHEN** Composer CLI remove 失败
- **THEN** 显示失败退出状态，保留未选择对象与其他全局包，不谎报成功

#### Scenario: 当前 native 运行时
- **WHEN** Windows 已安装入口启动卸载
- **THEN** 从临时可信副本同步执行，选择当前版本也可卸载，结束清理本任务副本


#### Scenario: POSIX真实路径字节
- **WHEN** macOS目标目录名带真实反斜杠、末尾空格或双引号，且另一相似目录也存在
- **THEN** 发现与卸载保留精确POSIX字节，只操作显式目标，不将其改写为另一目录

#### Scenario: 并发安装锁
- **WHEN** Composer安装锁已由另一进程持有
- **THEN** 卸载返回失败且owner/payload保持；成功卸载也保留同一setup.lock inode与状态根，避免并发安装换锁


### Requirement: 版本无关的 Composer 升级与恢复

文档 SHALL 使用相同的 `composer global require "supdger/webman-aot-builder:*" --no-scripts` 更新或恢复入口，随后由既有 guide/setup 独立准备入口所需运行时；MUST NOT 把入口版本输出视为运行时已升级。

#### Scenario: 已固定旧版本或全局依赖记录缺失
- **WHEN** 用户使用固定旧版本，或 global require/lock 不再记录已安装入口
- **THEN** 相同命令恢复直接依赖并解析当前环境兼容的稳定版，生成可运行代理，保留其他全局工具与原私有运行时，不自动启动资源菜单

#### Scenario: 单独准备运行时
- **WHEN** 入口更新后用户主动在 guide 选择开始或运行 setup
- **THEN** 既有准备流程复用已匹配运行时或安装入口锁定的对应完整运行时，不以入口升级冒称完整环境升级
