# AI Drive v0.3.2

## 修复

- 群晖 Web Station 的 AI Drive PHP 8.2 配置现在强制启用 `zlib` 扩展。
- 修复首次打开时提示“不支持 gzinflate，请安装 php-zlib 扩展”的问题。
- 继续使用独立端口 `8091`，不依赖 NAS 的 80 端口。

## 升级

已安装 v0.3.0 或 v0.3.1 的用户，请在群晖套件中心手动上传 `AI-Drive-0.3.2-0003-noarch.spk` 覆盖安装。用户、文件和数据库保留不变。

安装完成后访问：`http://NAS_IP:8091/`。

## 发布文件

- `AI-Drive-0.3.2-0003-noarch.spk`：群晖 DSM 7 修复安装包。
- `ai-drive-update-v0.3.2.zip`：AI Drive 插件在线更新包。
- `ai-drive-update-v0.3.2.zip.sha256`：在线更新包 SHA-256 校验值。
