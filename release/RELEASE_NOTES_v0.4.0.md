# AI Drive v0.4.0

这是首个正式 Docker 镜像版本，重点解决群晖 Web Station、PHP 扩展和目录权限造成的安装失败。

## 新增

- 提供 `ghcr.io/13220103449/ai-drive-kodbox` Docker 镜像。
- 同时构建 `linux/amd64` 与 `linux/arm64`，覆盖主流群晖机型及普通 Linux 主机。
- 内置 Apache、PHP 8.2、zlib、GD、SQLite、MySQL 等运行环境。
- 提供群晖 Container Manager 可直接导入的 `compose.yml`，默认端口为 `8091`。
- 网盘数据独立保存至 `ai-drive-data`，重建或升级容器不会清空数据。
- 增加真实页面健康检查，避免“容器已启动但网页不可用”。
- 支持通过一次性权限修复开关导入旧数据目录。

## 升级说明

Docker 部署执行 `docker compose pull` 和 `docker compose up -d` 即可升级完整程序。升级前建议备份 `ai-drive-data`。

群晖 SPK 仍然保留，但遇到 Web Station 环境或权限兼容问题时，建议优先使用 Docker 版。
