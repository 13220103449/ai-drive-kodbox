# AI Drive v0.7.0

- 新增 Agent 分享转存能力：收到 AI Drive 分享链接（`https://主机/#s/<hash>`）后，Agent 先 `shareSave` 把内容转存到自己的网盘，再用自有通道（`download`/WebDAV）取回，不再依赖控制浏览器下载。
- `shareInfo`：按链接检查分享（存在性、有效期、下载次数限制、密码要求、是否允许保存），支持 `sharePath` 查看子路径、`children:true` 列出目录内容；不泄露分享者侧的真实路径。
- `shareSave`：密码链接通过 `password` 参数校验；目标目录自动创建（默认 `来自分享`）；默认重名自动改名，只有显式 `overwrite:true` 才覆盖；`notDownload` 分享明确拒绝；转存不消耗分享下载次数。
- 校验链与 KodBox 分享页一致（过期、限次、密码、删除源拦截），但无会话副作用；行为对齐网页端“保存到我的网盘”。
- MCP 适配器新增 `ai_drive_share_info` 与 `ai_drive_share_save` 工具。
- 新增独立回归测试 `tests/ai_drive_share_test.php`（hash 解析、遍历拦截、校验链、字段脱敏）。
