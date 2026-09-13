# AI Drive Docker 部署

Docker 版已内置 Apache、PHP 8.2、zlib、GD、SQLite、MySQL 驱动和 AI Drive Agent 功能，不依赖群晖 Web Station 或群晖 PHP 套件。

## 群晖 Container Manager

1. 在 File Station 新建目录，例如 `docker/ai-drive`。
2. 下载仓库根目录的 `compose.yml`，放入该目录。
3. 打开 Container Manager → 项目 → 新增，选择该目录并使用 `compose.yml`。
4. 启动项目，等待容器状态变为“正常”。
5. 访问 `http://群晖IP:8091/`，按页面向导安装。个人和小团队可直接选 SQLite。

所有数据库、配置和网盘文件都保存在同目录的 `ai-drive-data` 中。安装完成后，容器会自动将 KodBox 数据库配置备份到 `ai-drive-data/system/setting_user.php`，更新或更换镜像时自动恢复。删除或升级容器不会删除这个目录。不要把 `ai-drive-data` 放到临时目录。

若 `8091` 已被占用，只修改左边的端口，例如 `8092:80`，随后访问 `http://群晖IP:8092/`。

## 命令行安装

```bash
mkdir ai-drive && cd ai-drive
curl -LO https://github.com/13220103449/ai-drive-kodbox/raw/main/compose.yml
docker compose up -d
```

查看状态：

```bash
docker compose ps
docker compose logs --tail=100 ai-drive
```

## 更新

```bash
docker compose pull
docker compose up -d
```

镜像更新不会覆盖 `ai-drive-data`。正式升级前仍建议备份该目录。Docker 部署应优先通过更新镜像升级完整程序；后台“AI Drive 在线更新”用于更新 AI Drive 插件本身。

## 导入旧数据时权限不正确

先在 `compose.yml` 的 `environment` 下临时加入：

```yaml
AI_DRIVE_FIX_PERMISSIONS: "1"
```

重建并成功启动一次后删除该项，避免每次启动都扫描全部文件。
