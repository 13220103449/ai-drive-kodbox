# AI Drive v0.3.4

## 关键修复

- 修复群晖套件升级成功后，Web Station 仍继续使用旧版网页目录的问题。
- 升级脚本现在会把新程序文件强制同步到实际运行目录。
- 用户文件、数据库和配置仍保存在独立 `data` 目录，不会被程序同步覆盖。
- 包含 v0.3.3 的 KodBox 启动文件完整性修复，以及 PHP `zlib` 扩展和 8091 独立端口配置。

## 升级

请在群晖套件中心手动上传 `AI-Drive-0.3.4-0005-noarch.spk` 覆盖安装。无需卸载旧版本。

安装完成后访问：`http://NAS_IP:8091/`。

## 发布文件

- `AI-Drive-0.3.4-0005-noarch.spk`：群晖 DSM 7 运行目录同步修复包。
- `ai-drive-update-v0.3.4.zip`：AI Drive 插件在线更新包。
- `ai-drive-update-v0.3.4.zip.sha256`：在线更新包 SHA-256 校验值。
