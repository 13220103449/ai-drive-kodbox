# AI Drive Agent 使用指南

这份文档面向 OpenClaw、Codex 及其他能够发送 HTTP 请求的 AI Agent。每个 Agent 使用独立的 KodBox 账号和 Bearer Token，可以在个人空间或“智能体”部门空间中管理文件。

## 1. 连接信息

- REST API：`http://你的域名:8091/index.php?plugin/aiDrive/api`
- WebDAV：`http://你的域名:8091/index.php/plugin/aiDrive/webdav/`
- 身份验证：请求头 `Authorization: Bearer <AGENT_TOKEN>`
- 请求格式：除上传和下载外，REST API 使用 `POST` 和 JSON 请求体。
- 空间：`personal` 是 Agent 的个人空间；`department` 是“智能体”部门共享空间。

Token 等同于账号密码。不要写入公开仓库、聊天记录或共享文档；每个 Agent 应使用自己的 Token。

## 2. 首次连接

先读取身份和协议，不要猜测服务器能力：

```bash
curl -sS -X POST "$AI_DRIVE_API" \
  -H "Authorization: Bearer $AI_DRIVE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action":"whoami"}'

curl -sS -X POST "$AI_DRIVE_API" \
  -H "Authorization: Bearer $AI_DRIVE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action":"capabilities"}'
```

只有响应同时满足 HTTP 2xx 且 JSON 中 `code=true` 才算成功。不要仅凭 HTTP 状态或非空响应判定成功。

## 3. 路径规则

- 所有路径都相对于所选空间，例如 `/我的文档/agents-starbucks/report.json`。
- 路径严格解析；不存在的路径返回 HTTP 404，不会回退到根目录。
- `mkdir` 会自动创建全部缺失的中间目录。
- `copy` 和 `move` 会自动创建目标文件的缺失父目录。
- `upload` 的目标目录必须已经存在；可先调用 `mkdir`。
- `read` 和 `download` 只接受文件；目录内容使用 `list`。

## 4. 常用操作

创建工作目录：

```json
{"action":"mkdir","space":"personal","path":"/我的文档/agents-starbucks"}
```

列出目录：

```json
{"action":"list","space":"personal","path":"/我的文档/agents-starbucks"}
```

写入小型文本文件：

```json
{"action":"write","space":"personal","path":"/我的文档/agents-starbucks/status.txt","content":"ready"}
```

上传文件：

```bash
curl -sS -X POST "$AI_DRIVE_API" \
  -H "Authorization: Bearer $AI_DRIVE_TOKEN" \
  -F "action=upload" \
  -F "space=personal" \
  -F "path=/我的文档/agents-starbucks" \
  -F "name=inventory_snapshots.json" \
  -F "file=@inventory_snapshots.json"
```

读取或下载：

```json
{"action":"stat","space":"personal","path":"/我的文档/agents-starbucks/status.txt"}
{"action":"read","space":"personal","path":"/我的文档/agents-starbucks/status.txt"}
{"action":"download","space":"personal","path":"/我的文档/agents-starbucks/status.txt"}
```

重命名只使用 `newName`（兼容别名仅有 `name`）：

```json
{"action":"rename","space":"personal","path":"/我的文档/agents-starbucks/status.txt","newName":"status-final.txt"}
```

不要在 `rename` 中使用 `to`、`dest` 或 `destination`。需要改变目录时使用 `move`。

复制和移动：

```json
{"action":"copy","space":"personal","path":"/我的文档/agents-starbucks/big1.json","to":"/我的文档/agents-starbucks/sub/big1_copy.json"}
{"action":"move","space":"personal","path":"/我的文档/agents-starbucks/status-final.txt","to":"/归档/2026/status-final.txt"}
```

删除与公开分享：

```json
{"action":"delete","space":"personal","path":"/我的文档/agents-starbucks/empty-folder"}
{"action":"share","space":"personal","path":"/我的文档/agents-starbucks"}
```

分享成功后返回 `shareID`、`shareHash` 和 `url`。

## 5. 一致性验证

上传重要文件后，Agent 应执行以下检查：

1. 检查响应的 `code=true`。
2. 使用 `stat` 对比文件大小。
3. 使用 `read` 或 `download` 取回内容。
4. 在本地和远端内容上计算 SHA-256；两者一致才算完成。
5. 对 404、`code=false`、超时或无法解析的响应执行有限次数重试，绝不能把它们记作成功。

## 6. 可复制给 Agent 的绑定说明

```text
你现在可以使用 AI Drive 网盘。请把服务地址和 Token 保存在你的私密凭据存储中，不要在回复中展示 Token。

REST API: <AI_DRIVE_API>
Bearer Token: <AGENT_TOKEN>
默认空间: personal
共享空间: department
推荐工作目录: /我的文档/<你的 Agent 名称>/

首次连接先调用 whoami 和 capabilities。每次写入或上传后必须用 stat 加 read/download 验证大小与 SHA-256；只有 HTTP 2xx 且 JSON code=true 才能记录为成功。目录用 list，文件用 read/download；rename 只使用 newName，跨目录使用 move。
```

## 7. 发布后的自动验收

仓库提供 `tests/agent_api_acceptance.mjs`。管理员为测试 Agent 准备 Token 后，可运行：

```bash
AI_DRIVE_URL="http://你的域名:8091/index.php?plugin/aiDrive/api" \
AI_DRIVE_TOKEN="<测试 Agent Token>" \
node tests/agent_api_acceptance.mjs
```

脚本会在个人空间创建一个带时间戳的临时目录，真实验证身份、能力、级联建目录、multipart 上传、list/stat/read、SHA-256、rename 字段约束、跨子目录 copy、空目录 delete、不存在路径 404 和子目录 share，最后删除临时目录。正式版本只有通过这套带 Token 的验收才应标记为已验证。

## v0.5 数据安全能力

- 对会改变状态的请求传入唯一 `requestID`，或发送 `Idempotency-Key` 请求头。相同 Agent 与请求编号再次提交时，服务器会返回第一次的结果，避免网络重试造成重复操作。
- `uploadChunk` 支持顺序分片上传。每片最大 10 MiB，`content` 使用 base64；参数为 `uploadID`、`index`（从 0 开始）、`total`、`path` 和 `name`。最后一片成功后才写入目标文件。
- `write`、`upload`、`uploadChunk` 覆盖文件，以及 `delete` 删除文件前，服务器自动生成 SHA-256 校验的历史版本。
- `versions` 返回当前 Agent 的版本记录；`restore` 使用 `versionID` 恢复。恢复前也会保存当前内容，因此恢复操作本身可以撤销。
- 管理员换钥后，新旧 Token 可并行使用 24 小时。Agent 应尽快保存新 Token，验证 `whoami` 后再移除旧配置。

## 8. Token 失效与恢复

- AI Drive 升级不会自动轮换 Agent Token；Codex、邮件或其他平台的周限额也不会影响 Token。
- `401 invalid or revoked Agent token` 表示请求携带的完整 Token 与服务端记录不匹配，或该记录被管理员停用。
- 标准 Token 长度为 69 个字符：`aidv_` 加 64 个十六进制字符。环境变量中不要包含引号、空格或换行。
- 管理员可在“部门及用户 → Agent 密钥管理”查看 Agent 状态和 Token SHA-256 前 12 位指纹。
- Agent 可在自己的设备上计算指纹：`printf %s "$AI_DRIVE_TOKEN" | sha256sum`，前 12 位应与后台一致。
- 明文 Token 只在创建或重新生成时显示一次，服务器只保存哈希，无法找回旧明文。若指纹不一致，请在后台重新生成并立即覆盖 Agent 的私密配置；旧 Token 会立即失效。
