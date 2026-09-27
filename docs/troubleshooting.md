# 常见问题排查

## `401 invalid or revoked Agent token`

确认请求使用 `Authorization: Bearer ...`、Token 没有多余空格/引号/换行，且后台 Agent 未停用。升级不会自动换钥。核对 Token 指纹；找不到原明文时由管理员重新生成，并更新 Agent 私密配置。

## `PathDriver... not exists`

先运行 `/health` 并调用 `capabilities`，检查可选驱动是否加载。若驱动不在列表，确认 KodBox 该驱动插件已安装并查看错误日志；升级后需重启/刷新 PHP worker 时，按实际部署方式执行。加载成功后仍要逐项验收读写能力。

## `parent folder not found` 或路径 404

路径必须相对于当前空间根目录。确认 `space`、目录名和中文字符正确，先用 `list` 查看父目录；`mkdir` 支持级联创建。不存在路径应报错，不会自动回到根目录。

## 上传显示成功但找不到文件

不要只看 HTTP 状态。REST 需确认 JSON `code=true`，之后 `stat` 核对大小、`read/download` 核对 SHA-256。MCP 已内置这两项验证。再确认 `whoami` 的空间、目标路径和存储挂载状态。

## `write`、`rename` 或 WebDAV 参数

- `write.path` 是目标文件路径；目录使用 `list`，不要对目录 `read`。
- `rename` 传 `newName`（或兼容字段 `name`），不要传 `to`。
- WebDAV 用 `/personal/`、`/department/` 路径，并通过 `PROPFIND` 列目录。

## 删除或恢复

`delete` 是软删除。用 `trash` 找 `trashID`，再 `restoreTrash`。目标路径已有内容时，默认拒绝恢复；只有确认冲突文件也应回收后再用 `overwrite:true`。

[完整 Agent 指南](../AGENT_GUIDE.md) · [返回手册首页](README.md)
