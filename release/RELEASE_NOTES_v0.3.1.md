# AI Drive v0.3.1

## 修复

- 群晖 DSM 7 套件不再依赖 80 端口和 `/aidrive` 路径别名。
- 默认注册独立 Web Station 服务端口 `8091`。
- 套件中心“打开”按钮现在访问 `http://群晖地址:8091/`。
- 安装时启用端口冲突检查，避免覆盖 NAS 上已有服务。

## 安装与升级

- 新安装：在套件中心手动安装 `AI-Drive-0.3.1-0002-noarch.spk`。
- 从 v0.3.0 升级：直接使用 v0.3.1 SPK 覆盖安装，原有 `data` 用户数据目录会继续保留。
- 安装完成后访问：`http://NAS_IP:8091/`。

## 发布文件

- `ai-drive-update-v0.3.1.zip`：AI Drive 插件在线更新包。
- `ai-drive-update-v0.3.1.zip.sha256`：在线更新包 SHA-256 校验值。
- `AI-Drive-0.3.1-0002-noarch.spk`：群晖 DSM 7 独立端口安装包。
