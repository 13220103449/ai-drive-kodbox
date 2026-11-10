# 运维、更新与备份

## 百度网盘镜像（v0.9.0+）

真人用户（默认 `admin`）的本地存储内容会自动镜像到百度网盘「AI-Drive镜像/<用户名>/」，智能体部门共享空间一并镜像。规则：

- **严格单向**：只上传到百度；任何情况下不把百度内容下载回服务器存储。
- **删除保护**：本地删除时百度副本移入「AI-Drive镜像回收站/<用户名>/」（百度网盘 App 内可见、可手动找回）；本地恢复时自动移回。百度侧永不物理删除。
- **容灾读取**：Agent API 读取部门共享空间遇到本地文件缺失时，自动从百度镜像流式中转（不落盘）。
- 已存储在其他网盘的内容自动跳过；回收站/历史版本保护目录（任意层级）不入镜像。

运维入口：管理面板「百度镜像」按钮（扫描差异 / 立即处理 / 重试失败 / 设置镜像用户），或 API `plugin/aiDrive/mirror`（op=status|reconcile|process|retry|enable|disable|setusers）。后台消费由 cron（每分钟 `queue-worker.php`）驱动：每轮处理一批队列任务，每 `mirrorReconcileMinutes`（默认 60）分钟全量对账一次。失败任务指数退避重试（默认 5 次后标记 failed，可手动重试）。

首次启用：面板点「扫描差异并排队」完成首扫，随后队列自动消费。百度 token 由 baiduDisk 插件自动续期；若存储管理中的百度授权失效，需重新授权一次。

## 共享账号迁移（v0.8.0+）

v0.8.0 起 Agent 默认不再各占一个 KodBox 用户，而是共用一个共享账号并在其个人空间下拥有独立子目录（`<homeDir>/`）。把存量独立账号 Agent 迁移到共享模式：

1. 准备共享账号（选一个**空的**专用账号，如从未使用的 Agent 账号）：

       php bin/migrate-shared-account.php prepare --source-user-id 6 --name agent

2. 逐个 Agent 预览并迁移（迁移前该 Agent 需静默 10 分钟；Token、API、路径语义全部不变）：

       php bin/migrate-shared-account.php status
       php bin/migrate-shared-account.php plan --agent "ZCode"
       php bin/migrate-shared-account.php migrate --agent "ZCode" --yes

3. 迁移完成后用该 Agent 的 Token 调 `whoami`/`list`/`write`/`read` 冒烟，再迁下一个。
4. 全部迁完后，旧账号已无数据与分享引用，可在管理员后台安全删除。

迁移内部按顺序执行：复制整棵个人空间 → 逐节点对账（路径、大小、抽样 SHA-256）→ 重指向 `share`/历史版本 blob/回收站 blob → 切换 Agent 记录 → 旧空间改名保留。任一步失败即中止，旧空间不受影响。分享链接 hash 不变、链接不断。新建 Agent 的 `POST agents` 默认走共享模式；只有显式传 `dedicated:true` 才会消耗一个 KodBox 用户。

## 更新

AI Drive 在线更新从 GitHub Release 获取插件包，并校验 SHA-256。更新前会备份 AI Drive 插件目录；插件健康检查失败时尝试回滚插件文件。升级后先检查 `health`、后台 Agent 控制台及 `capabilities`，再对实际挂载点跑[完整 API 验收](../AGENT_GUIDE.md#7-发布后的自动验收)。Docker 镜像更新与插件在线更新是两条不同路径，请按安装方式升级。

## 备份边界

后台“数据保护”按钮生成的是 **AI Drive 元数据快照**，包括智能体清单、部分审计日志、版本记录、回收站索引和更新记录；它不包含：

- KodBox 完整数据库和应用配置；
- 文件正文、本地版本 blob；
- 百度网盘或其他挂载存储的文件内容。

因此这份快照不能单独用于整站恢复。群晖 Docker 用户应另设备份任务，备份 Docker 映射的数据目录（默认 Compose 路径 `./ai-drive-data`）、部署配置和外置数据库（若实际使用 MySQL 等）。挂载云盘内容需由该云盘自己的版本/备份机制保护。保存备份到独立设备或异地位置，并定期做恢复演练。

## HTTP 部署

当站点只支持 HTTP 时，限制在可信局域网或 VPN 内；不建议把带 Bearer Token 的 API 直接暴露在公网明文访问。不要把 Token 写入公开仓库、调试日志或普通网盘文件。

## 升级后检查清单

- 登录管理员后台并确认 AI Drive 显示预期版本。
- 确认原 Agent 账号与挂载配置仍在；升级不会自动轮换 Token。
- 用 Agent 调 `whoami`、`capabilities`，再检查目标存储 driver 已加载。
- 对每类挂载使用临时目录测上传、读回 SHA-256、覆盖、移动/复制、软删除/恢复和版本回退。
- 将测试临时内容软删除后检查回收站记录；不要清理真实用户内容。

[返回手册首页](README.md)
