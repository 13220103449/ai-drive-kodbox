# AI Drive v0.8.0

- **共享账号模式（本版核心）**：所有 Agent 不再各占一个 KodBox 用户账号，改为共用一个共享账号，每个 Agent 在其个人空间下拥有独立子目录（`<homeDir>/`）。KodBox 免费版 10 用户限制不再随 Agent 数量增长；新建 Agent 默认走共享模式，`dedicated:true` 才会创建独立账号。
- **对 Agent 完全无感**：Token 不变、API 端点与 action 协议不变、路径语义不变（`whoami` 新增 `homeDir` 字段，其余响应结构不变）。全部 API 路径锚定在按 Agent 注入的根目录上，越界访问（`..` 穿越）沿用既有拦截。
- **一次性迁移 CLI**：`bin/migrate-shared-account.php` 提供 `status / prepare / plan / migrate` 四个子命令。`prepare` 把一个空账号转换为共享账号并写入插件配置 `sharedUserID`；`migrate` 复制旧个人空间到 `<homeDir>/`，逐节点对账（路径、大小、抽样 SHA-256），通过后才重指向 `share` 分享、历史版本 blob、回收站 blob 与 Agent 记录，分享链接 hash 不变、链接不断。迁移前自动检查该 Agent 最近是否活跃（默认 10 分钟静默期）。
- **补齐 `runStorageAcceptance()`**：0.7.0 起 `bin/upgrade-check.php` 调用该方法做升级后存储生命周期验收，但方法本体缺失会导致在线升级必然回滚。本版实现完整验收（在真实个人空间跑 mkdir/mkfile/stat/read/rename/copy/move 并清理沙箱），在线更新链路恢复可用。
- **schema 平滑升级**：`plugin_ai_drive_agent` 表新增 `homeDir` 列，`initTable()` 启动时自动补列（SQLite/MySQL 双支持），无需手工执行 SQL。
- **测试更新**：`webdav_storage_bootstrap_test.php` 更新为 0.7.0+ 别名补丁一致性断言；`ai_drive_path_test.php` 新增共享账号家目录根的解析、相对路径与穿越拦截用例。
- 升级说明：本版可从 0.7.0 在线更新（updateInstall 会先跑新验收）。迁移现有 Agent 请按 `docs/operations.md` 的共享账号迁移章节操作，逐个迁移、逐个验证，全程零停机。
