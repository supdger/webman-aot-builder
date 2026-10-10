## 1. 源码与发行元数据

- [x] 1.1 迁入已验收入口并仅将入口版本改0.3.3，核对固定0.3.2资源与18项行为回归。
- [x] 1.2 添加根注册元数据、嵌套bin/autoload与固定轻量dist URL，通过composer validate --strict。
- [x] 1.3 添加发行打包工具，验证仅九个源码一致文件、低于100KB、独立SHA256SUMS及非latest发布提示。

## 2. 使用与验证

- [x] 2.1 隔离Composer dist与source安装均验证真实代理、PSR-4自动加载、help/version且不准备平台资源。
- [x] 2.2 更新受影响README及现有Wiki安装页，核对路径/版本含义/离线恢复与原latest保持。
- [x] 2.3 完成独立审查和候选差异核验，区分本地通过与待公开注册/发行验收。

## 3. 后续公开门槛

- [x] 3.1 获原main/新tag及Release/Packagist切仓与旧版保留/Wiki目标授权后发布，并核对公开p2使用轻量dist与默认globalrequire。已按明确授权执行并完成公开验收。

证据：候选基于原main159861d；PHP8.4本地行为18项PASS，第一版ZIP16864字节/九成员。Windows新入口与PHP8.1实测仍未完成。

作者验证：真实隔离Composer ZIP安装与源码path mirror均生成代理、PSR-4与version0.3.3正确；复用本任务独立ready0.3.2 state doctor healthy。path mirror不是VCS源码回退，真实VCS门槛交独立验收。Wiki候选位于/private/tmp/webman-aot-composer-wiki-20261001，仅Install与Sidebar，check_wiki本地104targets/20pages，0errors/1既有warning，48external未验证。公开registry门槛已由独立验收通过。

独立验收：真实VCS源码与发行dist均通过Composer代理/PSR-4/版本检查，稳定文案最终ZIP16839字节、SHA256 58cbd8c1a130c1b0e4d360d101122cd6ea0d2cb01527ef7821241832d3e0301c，八core冻结不变。用户已明确授权“允许，完成合并、发布和仓库切换”；公开步骤及最终验收记录如下。

公开出版与独立验收：
- PR43 合并至 main 2062e6b24d217c9dab998dbcedea6a1edb6c26c9；v0.3.3 指向同一实际合并提交。
- v0.3.3 正式 Release 仅含九文件轻量 ZIP（16839字节，SHA256 58cbd8c1a130c1b0e4d360d101122cd6ea0d2cb01527ef7821241832d3e0301c）与 SHA256SUMS。公开下载逐字节/摘要匹配，v0.3.2 仍为 latest。
- 新 numeric 0.1.0 标签保留真实旧 Composer 发布提交 9b76baf1a9ac5f8a056504c5dcb7e8244202c231；原 v0.1.0 标签 a56c0ce 不移动。
- Wiki Install/Sidebar 已发布至 master 270570b9906378405d51d47f16dff30eb54d87d9。
- Packagist 官方维护者已将 repository 切换到原仓库；独立官方 p2 gate 同时确认新 v0.3.3 的 source/dist reference=2062e6b、小ZIP明确URL，以及旧 v0.1.0 真旧source/bin保持。
- 独立默认官方 Composer global require ^0.3.3 退出0、7.28秒，selected dist 为精确 Release 小 ZIP；真实 proxy help/version 与冻结核心一致，没有准备 compiler/toolchain。Wiki 发布 revision 内容亦已核对。
- 原仓库 webhook API 当前返回空列表；没有新增凭据或自动更新绑定。后续标签自动索引未作保证，必要时维护者需在官方页面执行 Update。
- 原主工作区既有 README/两源码/tests/packages dirty 保留；原运行时源码/锁/0.3.2资产和旧独立repo未改。Windows新入口与PHP8.1真实运行仍未验证，业务编译未因本次元数据迁仓重跑。

## 4. Component reuse candidate (not published)

- [x] 4.1 Package target manifest and reviewed replacement files; derive locked small/full metadata from actual archives.
- [x] 4.2 Verify independent copies, trusted patch guards, unsafe paths, and corrupt or missing files in isolation.
- [x] 4.3 Verify complete-cache priority, small upgrade, and outer activation/ready failure recovery.
- [x] 4.4 Complete independent review and affected documentation; distinguish native Windows and public release evidence.

Author evidence (local candidate): minimal-component-reuse 11 checks; outer runtime transaction 11 checks including marker write and promotion failure; actual small native Mac upgrade from read-only 0.4.1 backup to current target passed with 7610 reused files / 865138237 bytes and 4 replacements / 266086 bytes. Fresh source package 9435794 bytes, SHA f0391fb4873a3867abbcf20b0853ca8cdfdd8f47f418deb9c6e8bbc375758f4f; source and logs are retained outside the repository. Actual target component ZIP has after-files (not before variants), manifest SHA unchanged. Release-lock helper verifies actual Mac small bytes and rejects fabricated size and unsupported ordinary small packages (3 checks). Ordinary small packaging remains compatible without prepared input. Lightweight Composer archive verified 13 source-identical members, 32421 bytes before the final default-guide adjustment. None are public Release assets. Windows native installation/build is not claimed.

## 5. Guide download lifecycle

- [x] 5.1 Supervise restored and already-interactive Mac guide downloads through a parent-lifetime pipe, including default no-argument menus; stop owned curl on parent death and preserve partial bytes.
- [x] 5.2 Preserve non-interactive/default silent behavior and business command forwarding; complete native progress and parent-exit regressions.

Lifecycle evidence supplied by the bounded process slice: 51 checks (10 actual production-entry cases plus 41 owned helper cases), download 9 checks, and the final default-entry increment 10 checks. Real Composer 1-second timeout restores an interactive zsh prompt, stops partial-file writes, leaves no ready/runtime or owned curl/guide process, and permits the next command. This is the Mac guide/download leaf contract, not a claim about arbitrary installation/build descendants or native Windows process teardown.

Independent source gate accepted by solhigh__uninstall_cli_acceptance: optional packaging contract 5 cases, default actual-entry 2 cases, outer transaction 9 cases, actual native cross-version success and failure preservation, and 10 primitive cases. The actual target ZIP contains after-files, matching source and independent SHA checks. No task-owned guide/curl process remains. These check counts describe independent layers and are not one end-to-end public Release test.

Pending external delivery: no version change, 0.4.4 tag, Release, asset upload, or published upgrade-capable metadata was created here. Native Windows installation/process acceptance and a full new public asset build remain unverified. No user-host upgrade was performed. Existing released 0.4.3 assets and resource hashes remain unchanged.
