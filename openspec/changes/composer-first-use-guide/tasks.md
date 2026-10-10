## 1. 连续首次使用入口

- [x] 1.1 实现 TTY 无参/guide/start，资源选择和无写入取消；行为测试覆盖 EOF、取消和非 TTY 无下载。
- [x] 1.2 通过私有 PHP 调用 0.3.2 已有项目 Flow，验证正确 home/bin/cwd、原命令退出码及机器 version 不变。

## 2. 实际入口验证

- [x] 2.1 用真实 0.3.2 完整包在隔离 Mac PTY 从导入进入项目菜单，覆盖就绪复用、错误包、失败重选和结束；保留实时日志。
- [x] 2.2 实际临时 Composer ZIP 安装并使用 global exec，验证旧 PATH 同名哨兵不执行、cwd 正确、菜单可达及 require 成功再启动顺序。

## 3. 文档与候选

- [x] 3.1 更新简明 README 和现有 Wiki 安装页草稿，给一次安装启动和已装单入口；检查本次命令及链接。
- [x] 3.2 必要包版本元数据、既有相关回归、归档身份和 OpenSpec strict 检查通过后固定候选；不在独立验收前提交发布。

## 已运行证据与边界

- 新引导行为 18/18、既有桥接 19/19、卸载 37/37 通过；静态 PHP 语法、Composer strict 和 OpenSpec strict 通过。新增测试临时资源 finally 清理。
- 原 v0.3.2 Mac 完整包大小 264098182、固定 SHA 匹配，中文空格私有 state 导入成功；prepare 19.0 秒，离线 7614 条资源校验并就绪，随后直接显示原 Flow 菜单。
- 真实 Mac PTY 无效路径→重选→中文空项目，原 build 78，失败重试菜单及诊断日志可见，选择结束后仍返回 78，未伪称构建成功。日志 /Users/supdger/.tmp/webman-aot-guided-76d36d5f6b1f31d9/guided.log。
- 实际隔离候选 ZIP 经 Composer global require 安装；old PATH 同名哨兵未执行，global exec 相对项目解析到原调用目录，build 78 透传；日志 /Users/supdger/.tmp/webman-aot-guided-8a4268e16a6ed24b/guided.log。
- Composer 父子 stdio 刻意管道化且保留已附着 Mac 终端，Console helper 自动恢复，复用 ready 进入原 Flow，结束 0；日志 /Users/supdger/.tmp/webman-aot-guided-bbc8fb8e3f67d6e7/guided.log。原沙箱 /dev/tty 拒绝时保守返回提示，正常工具审批仅对同 TEMP 放开后实跑通过。
- runtime 与 ZIP 候选固定清单 /private/tmp/aot-036-first-use-fixed-manifest-20261001.json。此为本地候选消费，尚未提交、发布或作为公开安装证明。
- Windows 只完成官方 PHP/Composer/Microsoft 源码链核对，未物理实跑；只读 console probe 尚未执行或导出。PHP 8.1、Linux 部署及业务验收未运行；未动用户真实安装、PATH 或数据库。

## 独立候选验收

- 独立安全审查：18 引导行为及真实 ZIP 安装代理 9 边界检查（共 27）通过，R3 12 成员与源码一致；未发现必须修复项。
- 独立冷用户：实际 Composer 入口恢复终端、取消 0、完整包离线导入、就绪复用、无效项目诊断和重选后保持失败 78 均通过。此为 Mac CLI 首次使用验收，不是新业务编译成功或 Windows 实机证明。
- R3 与 R2 仅 README 三处明确 Composer/独立安装包路线，运行时和资源字节不变；R3 SHA-256 ab01f161e94b2d94e9cb1195433675c7f3ced746a9b9764f297c97b8a5b35b07，28093 字节 / 12 成员。
- 用户既有“发布，我要清理干净后实施composer包”和“允许，完成合并、发布和仓库切换”授权，主控现准出同仓库 0.3.6 Composer 修复；仅 ZIP+SHA256SUMS 非 latest，原完整运行时及 latest 保持 0.3.2。公开发行与官方消费证据在操作完成后记录，不预填成功。

## 父 Composer 超时与输入恢复接续

- [x] 4.1 合入父 PRE_COMMAND_RUN 精确 global exec/binary 处理，保留其他命令与无事件能力路径。
- [x] 4.2 在现 Console/Flow 读取边界处理自身 fgets 故障，保留父 FD3、开放输入/半行/EOF 合同和原处理器；不覆盖 Installer/Process。
- [x] 4.3 作者验证：guide 28、实际父 Composer 对照 9、Flow 27 通过，实际背景 PTY EIO 明确失败，无 fgets Notice；临时 HOME/cache 清理。源码与候选 Composer ZIP 13 文件一致，6 路径固定 `/private/tmp/webman-aot-unified-timeout-package-v2/source-freeze.json`。
- [x] 4.4 当前固定候选独立验收通过：guide 28、actual Composer 9 与精准 Plugin API 6 场景，6 路径和保留的 Installer/Process 摘要无漂移；Windows 物理终端未验收，不据 macOS 对照声称已通过。

候选未提交、发布或替换用户全局安装，Wiki 仅草稿；原已通过下载父取消切片仍保留，其早版 Console 全回归不代替本次末改的独验。

独验原始证据：`/private/tmp/webman-aot-binding-independent-20261010/resume-diagnostic/package-B-acceptance.json` 与 `plugin-scope-result.json`。真实 PTY 使用正常审核后的隔离执行，默认受限环境不能恢复控制终端；无本机升级或服务操作。
