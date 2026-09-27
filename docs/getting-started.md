# 快速开始与管理员配置

## 1. 部署完成后

管理员先登录 KodBox 后台，确认应用可访问、存储空间可用，再进入“部门及用户”打开 AI Drive 的 Agent 控制台。AI Drive 会为智能体账号创建单独的 KodBox 用户，并将其加入“智能体”部门。

部署说明： [Docker](../docker/README.md) · [群晖 SPK](../synology/README.md)

## 2. 创建 Agent

1. 在“新建智能体”中填写名称；账号和密码可由系统生成。
2. 创建完成后，安全地复制一次性密码、Bearer Token 和绑定话术。
3. 将凭据交给该 Agent 的私密凭据存储，不要贴进公开仓库、普通共享文档或聊天记录。
4. 首次连接必须调用 `whoami` 和 `capabilities`。只有 HTTP 2xx 且 JSON `code=true` 才算成功。

Token 相当于密码。AI Drive 不会在升级时自动轮换 Token；若 Token 暴露或失效，请在后台重新生成并更新 Agent 配置。

## 3. 选择工作空间

- `personal`：Agent 自己的个人空间，适合草稿、私有数据和个人工作文件。
- `department`：智能体部门共享空间，适合跨 Agent 交接和协作文件。

不要把“共享空间”误当成个人空间的别名；创建文件前先确认 `whoami` 返回的身份和当前 `space`。

## 4. 连接方式

- **MCP**：适合支持 MCP 的 Agent 客户端。配置见 [MCP 使用说明](../integrations/ai-drive-mcp/README.md)。MCP 写入/上传会自动创建目标目录并检查读回大小和 SHA-256。
- **REST API**：适合 OpenClaw、自定义 Agent 和自动化脚本。详见 [Agent API](agent-api.md) 及 [完整 Agent 指南](../AGENT_GUIDE.md)。
- **WebDAV**：适合桌面同步工具和通用 WebDAV 客户端；WebDAV 账号密码与 Bearer Token 是两组不同凭据。

## 5. HTTP 部署注意

如果服务只能通过 HTTP 使用，不要把它暴露在不可信的公共网络上。Bearer Token 和 WebDAV 密码在 HTTP 中可能被窃听。优先限制在可信局域网或 VPN 中访问，并为不同 Agent 使用独立账号和 Token。

[返回手册首页](README.md)
