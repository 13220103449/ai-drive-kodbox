# AI Drive v0.4.1

这是 Docker 首个可部署正式版。在 v0.4.0 首次镜像构建后新增的真实启动验收中发现 Debian Apache 启动环境变量未初始化，本版已修复该问题。

## Docker 能力

- 支持 `linux/amd64` 和 `linux/arm64`，Container Manager 会自动选择架构。
- 默认访问端口 `8091`，无需占用群晖 80 端口。
- 内置 Apache、PHP 8.2、zlib、GD、SQLite、MySQL 驱动等运行环境。
- 数据独立持久化到 `ai-drive-data`，更新或重建容器不覆盖网盘数据。
- 每次发布后自动启动容器，验证关键 PHP 模块和真实网页内容。

## 安装

从本 Release 下载 `compose.yml`，在群晖 Container Manager 的“项目”中导入并启动，然后打开 `http://群晖IP:8091/`。

正式更新 Docker 版时执行 `docker compose pull` 与 `docker compose up -d`。升级前建议备份 `ai-drive-data`。
