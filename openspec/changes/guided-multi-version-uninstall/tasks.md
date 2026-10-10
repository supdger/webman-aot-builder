## 1. 安装发现与交互
- [x] 1.1 实现统一只读发现、静态版本、路径去重与逐项默认保留。
- [x] 1.2 实现严格对象删除、活动入口撤销、Composer精确remove与失败提示。

## 2. 产品入口
- [x] 2.1 接入Composer/native命令及两平台卸载器、打包所需文件，Windows同步临时runtime。
- [x] 2.2 受影响README/CHANGELOG与Wiki草稿，明确未发布能力。

## 3. 验证
- [x] 3.1 隔离temp测试保留/卸载/EOF/非TTY/残留/同目录版本/中文空格/去重/所有权/失败；既有回归。
- [x] 3.2 原生Mac实际入口与Windows目标平台检查，区分未运行缺口；规格validate与独立审查返工。

## Evidence

起点本地 origin/main d365363，干净独立 worktree。远端实时核对DNS失败，不以本地引用冒充实时public验证。本机无pwsh；Windows原生检查待提供受限执行环境。

本轮实际证据：
- PHP 8.4/macOS 隔离 temp 行为测试 `php packages/composer-installer/tests/uninstall.php`：24 checks 通过；含0.1.2直接app/runtime备份布局、静态模板归属、符号链接、Composer失败与其他包保留。既有 Composer `tests/run.php` 18项通过。
- 真实 native Mac launcher PTY：中文空格InstallRoot+自定义BinDir未入PATH，锁定原生PHP，`--list`只读；y实删current+自身launcher，q保留旧generation/.previous/toolchains；最终重新列2项残留。未知PHP hash与linked ancestor拒执行。日志 `/private/tmp/aot-native-uninstall-pty-20261001.log`，fixture与临时副本已清理。测试首次发现cwd防护过宽后定点修复，最终实跑通过；不以先前失败当通过。
- 实际Composer候选ZIP 11 source-identical成员25,153 bytes，路径 `/private/tmp/webman-aot-uninstall-composer-candidate-20261001-r2/webman-aot-builder-0.3.3-composer.zip`，含引擎/resource；worktree `.git`误入归档已修并复测。包名沿用现源码0.3.3仅本地候选，未覆盖公开发行。
- Native app打包stage实测5个受影响成员与源码摘要一致，位于 `/private/tmp/webman-aot-uninstall-native-app-stage-20261001`；未重建完整原生安装包。
- `composer validate --strict`、PHP lint、shell语法、`git diff --check`及OpenSpec validate通过；Wiki未发布草稿 `/private/tmp/webman-aot-uninstall-wiki-draft-20261001` 20页/105本地目标0errors，既有2页未直接Home/sidebar链接警告、49外链及1anchor未验证。仅Install/Upgrade-Uninstall修改。
- Windows没有本机pwsh，原生CMD/PowerShell真实执行及PHP8.1尚未验证，不使用Mac证据替代；独立审查进行中，任务3.2保留未勾选。
- 源码feature分支仅修改本独立worktree，无提交、推送、tag、Release、真实安装/PATH改动或Wiki发布。

- 增补真实Composer全局自卸载：独立temp中文globalhome，本地path镜像安装本包和fixture/other-tool，禁用Packagist网络；真vendor/bin/webman-aot入口PTY y确认，Composer实际remove本包及proxy、其他包/proxy保留，源码自删除后remaining0仍成功，退出0。日志 `/private/tmp/aot-real-composer-uninstall-20261001.log`，tempglobal/fixture已清理。此证据区别于前述失败分支的CLI模拟。

最终候选补修与独立判定：
- 同范围后续审查确认并修复两个must：Mac归一化误改真实POSIX路径；Composer状态解锁后删除setup.lock会允许并发setup换锁。仅Windows转换分隔符/trim外壳，Mac保留反斜杠、尾空格、引号字节；Composer状态保留原锁inode/根并解释残留。
- 最终长期回归30/30通过（此前24项为初稿证据）；真实Mac native PTY与真实Composer global self-remove在新引擎下重新实跑通过，fixture均清理。
- 独立review最终补判PASS：长期30/30亲跑，自有9项path/lock碰撞及跨进程锁fixture通过；前轮独立Mac native12checks与真实ZIP安装后的Composer自卸载通过。后续日志 `/private/tmp/webman-aot-independent-path-lock-review-20261001.log`；原生日志 `/private/tmp/webman-aot-independent-native-review-20261001.log`，Composer日志 `/private/tmp/webman-aot-independent-composer-selfremove-20261001.log`。无剩余must。
- 新最终候选Composer ZIP r3 11source-identical成员25,314bytes：`/private/tmp/webman-aot-uninstall-composer-candidate-20261001-r3/webman-aot-builder-0.3.3-composer.zip`。独立生产native staging ZIP新r2 137files/4新增payload与源码一致（完整原生包未重建）。r2旧ZIP已被新候选证据替代，不用其25,153bytes作为最终候选数据。
- Windows原生执行仍未验证、PHP8.1仍未跑，任务3.2因为目标平台未完成保留未勾；本地source/Mac/Composer切片实现与独立验收完成。公开安装仍为既有0.3.2/0.3.3，不包含本能力。


## 4. v0.3.4轻量公开发行（续授权）

用户原话：“发布，我要清理干净后实施composer包”。范围：必要同功能修复、feature commit/push/PR合并至原仓库main、新v0.3.4轻量tag/Release/资产/索引及Wiki；保留native v0.3.2 latest与完整资源。不操作真实用户安装、删除或PATH。旧3.2Windows原生缺口不转嫁为本次full发行通过。

- [x] 4.1 版本metadata、CHANGELOG/README与Wiki清理流程同步，保持runtime0.3.2。
- [x] 4.2 必要检查、source一致11文件ZIP、真实隔离localZIP安装/help/version/list/逐项清旧版本保留Composer与自卸载验收。
- [x] 4.3 prepare-for-launch发布评审与固定候选独立验收，commit/push/PR并附task artifact。
- [x] 4.4 获独立发行验收后合并、精确tag/非latestRelease、资产与SHA256SUMS公开，核对source/index/dist引用。
- [x] 4.5 Wiki发布、官方Packagist默认globalrequire^0.3.4与代理公开验证，保持旧3.3和native3.2资产不可变。

prepare-for-launch进度（同本任务唯一记录）:
- [x] 0. 范围与风险
- [x] 1. 质量验收（跑检查）
- [x] 2. 提测/准出
- [x] 3. 预发验证清单
- [x] 4. 发布评审一页纸
- [x] 5. 物料与合规 checklist
- [x] 6. 灰度方案
- [x] 7. 优化风险速扫
- [x] 8. 结论


v0.3.4发布候选证据（替代前述0.3.3开发包的发行材料）:
- 固定bridge0.3.4、runtime0.3.2，原生完整包不重发；composer validate --strict、既有18项与卸载30项、diff check通过。
- 实际source一致ZIP 11成员25,376bytes，SHA256 `2e08d0b72aef6e02f3378ec68964f0d58764b30718bc09a91d2551006defbb82`，`/private/tmp/webman-aot-release-034-candidate-20261001/webman-aot-builder-0.3.4-composer.zip`。
- 真Composer消费该ZIP（非path源码）2.03秒通过：help/version/list不prepare，PTY卸载旧0.1.2及绑定旧入口、保留全局Composer，重新列剩余；再PTY真self-remove精确包、其他global工具保留，收尾remaining0正常。日志`/private/tmp/aot-034-localzip-consumer-20261001.log`；隔离temp已清理。
- Wiki受影响2页已准备0.3.4清理后用Composer流程，20页107本地目标0error；既有2历史页未直链警告，49外链未验证。尚未Wiki push。
- public主仓main已实时CLI核对d365363；0.3.4尚无tag。Windows SSH确认连接，但TEMP源码/包上传被auto_review拒绝特定物理机export授权；主Agent正在请求该具体许可，不换入口绕过。Windows实跑依赖此许可，发行结论由独立review最终判定。


v0.3.4实际公开发行结果：
- 独立fixed候选准出PASS，PR [#45](https://github.com/supdger/webman-aot-builder/pull/45)已merge；源码/tag `v0.3.4`精确对应`ba29240a38aaa66fc9eb7af919d773d4de8a586d`。未对现有tag改写。
- 从该merge SHA的干净源码重打11成员25,376bytes最终ZIP，所有成员与该commit的git blob逐字一致；SHA256 `a816674a0694e632f321d8bc30b1cbc0e058f3bccfdeab625493e866297d00f8`。归档元数据改变导致候选与最终ZIP摘要不同，内容相同；最终ZIP再次真实消费全部通过1.92秒，证据`/private/tmp/aot-034-finalzip-consumer-20261001.json`与`.log`。
- [v0.3.4 Release](https://github.com/supdger/webman-aot-builder/releases/tag/v0.3.4)明确非latest，仅上述轻量ZIP和104bytes SHA256SUMS两个资产。native0.3.2的10资产和Composer0.3.3的2资产前后ID/名称/大小/digest/URL完全相同；latest API实读仍`v0.3.2`。
- 官方Packagist首次未自动索引；Composer CLI无registry更新命令，官方更新API需要已有username/apiToken认证，未读取凭据。主Agent在既有认证本包页面Manage→Update精确刷新，未改repository/name或重复建包；官方p2随后可见`v0.3.4`，source.reference对应上述tag SHA，dist指向本Release小ZIP，旧版本保留。
- 默认官方`composer global require saiadmin/webman-aot-builder:^0.3.4`隔离真实安装PASS（无repositories覆盖），下载ZIP摘要/11成员matches正式tag；实际proxy help/version/list与PTY self-remove通过8.32秒，不prepare运行时，任务HOME/global/cache已清理。证据`/private/tmp/aot-034-public-consumer-20261001.json`与`.log`，未将原始日志纳入源码。
- Wiki仅Install/Upgrade-Uninstall两页公开，commit`d8e25de`；公开raw正文实读与验证页面逐字一致。最新指南链接：[安装](https://github.com/supdger/webman-aot-builder/wiki/Install)、[升级与卸载](https://github.com/supdger/webman-aot-builder/wiki/Upgrade-Uninstall)。
- 发布范围没有原生full包、真实用户安装/清理或PATH操作。Windows实机TEMP文件上传被auto_review以specific export授权不足拒绝，该依赖没有绕过；Windows/PHP8.1仍未实跑，任务3.2的full目标平台项保留未完成，不用Composer切片发行替代该证据。
- prepare-for-launch结论：**可进入灰度**（本次Composer轻量发行切片）；独立验收与真实官方消费完成，旧完整发行不变。原生wrapper/full目标平台门槛仍单列未完成。


## 5. 逐项提示可理解性修复（2026-10-10）

使用者目标：从任意终端目录运行卸载命令，由命令列出对象并逐个说明用途、删除影响，再自行确认；不在聊天里逐个询问，不要求先安装或升级。原失败命令将源码相对路径检查与 `--list` 拼在一起，检查失败后退出终端；现代入口本已支持逐项确认，本轮沿用现有删除边界。

- [x] 5.1 每项列表和确认提示说明用途及卸载影响，修正私有运行时内部日志/工具链的保留描述。
- [x] 5.2 README 直接交互入口与可选只读列表分清，不将查询绑定更新；隔离回归任意 cwd、逐项选择和 EOF。

本轮范围仅源码及隔离验证；不提交、推送、发布、更新宿主或真实卸载。源码基线 origin/main `9c3ba7ecde0988e13f3042ab1aa81155fea29b30`。

本轮作者验证：macOS/PHP 8.4 隔离卸载行为测试 40 项通过，覆盖列表用途/影响、任意 cwd 的公开 `--list`、一次确认仅卸载一项与后续 EOF 保留；PHP lint 与 `git diff --check` 通过。真实产品 Composer 入口在隔离 PTY 从无关 cwd 列出 3 项并询问 3 次：回车保留第 1 项、y 仅删除第 2 项夹具、q 保留第 3 项，退出 0/耗时 0.14 秒。原始终端记录 `/private/tmp/webman-aot-uninstall-20261010/prompt-transcript.log`。只操作临时夹具，未卸载用户实际安装。Windows 与 PHP8.1 未在本轮执行。Wiki 受影响最小段落草稿 `/private/tmp/webman-aot-uninstall-20261010/Upgrade-Uninstall-snippet.md`，未读取/合并远端页面、未发布；现代源码候选不表示已安装 0.4.1 包发生变化。


## 6. 稳定升级文档与隔离复现（2026-10-10）

公开 Wiki `Upgrade-Uninstall` 于本轮只读取得 revision `5bfce2f9b85609f748df9416392540f085b83f42`：升级只给 `global update`，无法恢复 root require/lock 缺失的孤立已安装入口。现有 require 与 guide/setup 已满足稳定入口，不新增自升级执行器、不改 Composer 信任配置。

- [x] 6.1 两份 README 与既有规格说明版本无关 require 更新/恢复和独立运行时准备。
- [x] 6.2 真实 Composer 隔离复现旧 update 移除入口；同一新命令覆盖旧版本固定约束、root/lock 丢失，保留其他工具和运行时，不启动菜单。

本轮授权只覆盖源码/文档与隔离验证；没有真实宿主升级、提交、推送、发布或 Wiki 发布。

本轮升级作者验证：真实 Composer 2.9.5/PHP 8.4/macOS 隔离轻量候选 ZIP 生命周期回归 43 项通过（包括 9 项本轮新增检查），从固定旧版本和 root/lock 丢失两种状态使用同一 `require :* --no-scripts` 更新/恢复代理、根依赖及本地测试目标版本；其他工具与运行时哨兵保持。旧 Wiki update 失败已真实复现：`Package listed for update is not locked` 后移除孤立包/代理。测试较新版本仅本地仓库 metadata 夹具，不是正式发布或完整运行时升级。日志 `/private/tmp/webman-aot-upgrade-20261010/composer-lifecycle-final.log`，测试 HOME/global/cache 已清理。

衔接验证曾发现并保留失败证据：`composer global exec -- webman-aot guide` 在真实 PTY 子进程 stdin/stdout 非TTY、`/dev/tty` 不可打开；COMPOSER_NO_INTERACTION/CI 为空，并非自动化标记阻止。相同夹具直接 PHP 代理 guide 正常显示对应目标版本和菜单；两种状态选择结束均保留运行环境。进一步在正常授权隔离环境核对 controllingTTY：pty.fork 子进程与 Composer exec 的 stdin/stdout 均为TTY，/dev/tty 可打开，Composer exec guide 与直接 PHP proxy guide 均正常开菜单/选择结束；因此前次失效属于工具 sandbox 权限限制，不是已证实产品缺陷。日志 `/private/tmp/webman-aot-upgrade-20261010/guide-control-terminal-unrestricted.log`。README 升级衔接使用已实测可用的统一官方 `composer global exec -- webman-aot guide`；直接 PHP 代理只作受限 sandbox 对照，卸载仍使用它进行逐项确认。没有修改 Console 执行器。未在本轮执行真实完整运行时升级或 Windows PowerShell。

公开 Wiki 受影响两页由独立文档子任务准备于 `/tmp/webman-aot-public-docs-candidate-20261010`（Install/Upgrade-Uninstall），已纠正原源码 cwd+exit 卸载块及 update 升级块，区分入口恢复和运行时准备；本地链接检查 20 页 0 errors、1 既有导航 warning。未发布，实际发布须另按本轮任务授权边界处理。
