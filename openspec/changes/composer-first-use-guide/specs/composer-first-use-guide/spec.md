## Purpose

让通过 Composer 安装的开发者无需寻找代理位置或逐条猜测下一步，即可在一个终端入口选择准备方式并进入原有项目构建菜单；同时保留非交互调用、取消和既有安装的安全边界。

## ADDED Requirements

### Requirement: 连续交互入口
系统 SHALL 在 TTY 无参或 guide/start 时展示自动准备、本地完整包和结束选择，资源就绪后在同一终端进入既有项目构建菜单。

#### Scenario: 首次本地导入
- **WHEN** 使用者选择本地完整包并提供当前系统匹配的可信包
- **THEN** 系统校验和隔离准备，随后提供选项目、构建、校验及失败重试菜单，不要求另输 setup/build 命令

#### Scenario: 就绪资源复用
- **WHEN** 私有资源已就绪且使用者选择开始
- **THEN** 系统直接进入已有项目菜单，不重复下载或安装

### Requirement: 取消和非交互安全
系统 MUST 在初始取消、EOF 或非 TTY 无参时不下载、不创建状态、不安装、不构建。

#### Scenario: 无终端调用
- **WHEN** 无参数通过管道或非终端执行
- **THEN** 系统立即显示明确的交互入口，退出且不写状态

#### Scenario: 使用者结束
- **WHEN** 初始菜单选择 0 或输入关闭
- **THEN** 系统成功结束且不创建安装状态、不改变用户 PATH 或项目

### Requirement: 明确首次命令及既有调用兼容
系统 SHALL 提供不需要查 bin 的 Composer 官方执行入口及成功安装后启动菜单的示例，既有 build/doctor/setup/uninstall 和非 TTY version 的行为保持兼容。

#### Scenario: 历史 PATH 命令存在
- **WHEN** Composer 私有 bin 和 PATH 历史同名命令同时存在，使用者通过 global exec 启动
- **THEN** 启动 Composer 包入口，保留原调用目录而不永久修改 PATH

#### Scenario: 查询版本
- **WHEN** 非 TTY 调用 version
- **THEN** 输出入口和目标版本而不准备资源；交互查询可另显示下一步入口

### Requirement: 精确关闭父命令进程超时
系统 SHALL 仅对具备事件能力且启用插件的父 Composer global exec、binary 精确 webman-aot 关闭本次进程超时，MUST NOT 修改全局配置或放宽其他命令。

#### Scenario: 交互等待超出父默认计时
- **WHEN** 指定入口仍在正常下载或等待输入
- **THEN** 不因父默认进程计时结束，其他 exec 保留原超时

### Requirement: 保留输入与父生命周期合同
系统 MUST 在真实自身读取故障时失败结束并显示恢复入口，MUST 保留正常 EOF 取消、开放输入与半行持续等待、父消失结束及处理器恢复。周期探测 MUST NOT 变为交互截止时间。

#### Scenario: 无数据与部分行
- **WHEN** 父仍存在且输入管道开放、没有完整行
- **THEN** 继续等待；收到换行或 EOF 才返回输入，恢复原阻塞模式

#### Scenario: 背景终端读取 EIO
- **WHEN** 终端读取产生自身 fgets EIO
- **THEN** 明确失败结束，不泄漏 Notice，不吞无关错误；父 FD3 监测保持有效
