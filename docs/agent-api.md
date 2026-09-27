# Agent 使用与 API

## 连接与身份

Agent 使用独立 KodBox 账号和 Bearer Token。API 地址格式：

```text
http://<你的网盘域名>:<端口>/index.php?plugin/aiDrive/api
```

在请求头传 `Authorization: Bearer <AGENT_TOKEN>`。首次请求依次调用：

```json
{"action":"whoami"}
{"action":"capabilities"}
```

不要在日志或回复中输出 Token。HTTP API 必须限制在受信任网络/VPN 使用。

## 文件操作

除 multipart 上传、文件下载外，使用 POST JSON。目录使用 `list`，文件内容使用 `read` 或 `download`。

| Action | 必要参数 | 说明 |
| --- | --- | --- |
| `list` | `path` | 列出目录 |
| `stat` | `path` | 查文件/目录属性和大小 |
| `read` | `path` | 读取文件；二进制建议传 `base64:true`，单次最多 20 MiB |
| `write` | `path`, `content` | 创建或覆盖；覆盖前自动保留版本 |
| `upload` | multipart: `file`, `path`; 可传 `name` | `path` 是已存在的目标目录；可先 `mkdir` |
| `uploadChunk` | `path`, `name`, `uploadID`, `index`, `total`, `content` | 顺序分片；每片最多 10 MiB，`content` 为 base64 |
| `mkdir` | `path` | 自动级联创建缺失目录 |
| `rename` | `path`, `newName` | 仅支持 `newName` 或兼容字段 `name` |
| `move` / `copy` | `path`, `to` | `to` 可为目标目录或完整目标路径；会创建缺失父目录 |
| `delete` | `path` | 移入可恢复回收站，不会永久删除 |
| `trash` / `restoreTrash` | `trashID`（恢复时） | 查看软删除记录/恢复到原位置 |
| `versions` / `restore` | `versionID`（恢复时） | 查看文件历史版本/恢复某个版本 |
| `share` | `path` | 创建分享链接；按需设置密码和期限 |

所有路径相对所选空间根目录。不存在的路径会返回错误，不会伪装成根目录。完整请求示例见 [AGENT_GUIDE.md](../AGENT_GUIDE.md)。

## 写入验收

对重要 REST 上传/写入，Agent 应：

1. 检查 HTTP 成功且 JSON `code=true`。
2. 调 `stat` 比较目标文件大小。
3. 通过 `read`/`download` 读回并核对 SHA-256。
4. 失败时不要标记完成；仅对可安全重试的请求使用唯一 `requestID`。

MCP 的上传和文本写入会自动执行上述大小与 SHA-256 读回校验；校验失败将返回工具错误。

## WebDAV 目录约定

若 `capabilities` 声明 WebDAV 已启用，空间路径为 `/personal/` 与 `/department/`。目录发现应使用 `PROPFIND`；GET 集合根不是目录列表接口。

[完整 Agent 指南](../AGENT_GUIDE.md) · [MCP 配置](../integrations/ai-drive-mcp/README.md) · [返回手册首页](README.md)
