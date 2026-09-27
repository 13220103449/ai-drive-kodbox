# 回收站与历史版本

## 回收站：软删除

Agent 调用 `delete` 后，文件或目录会被移动到挂载存储上的 `AI Drive回收站(勿删)`，并记录原路径和 `trashID`。Agent API 没有永久删除/清空接口。回收站保护目录从普通列表中隐藏，不要手动改名、删除或清空。

查看记录：

```json
{"action":"trash","space":"personal","limit":100}
```

恢复：

```json
{"action":"restoreTrash","space":"personal","trashID":123}
```

默认情况下，如果原路径已存在，恢复会失败以避免覆盖。显式传 `"overwrite":true` 时，冲突文件也会先被软删除到回收站。

## 历史版本：覆盖保护

覆盖写入/上传之前，AI Drive 会尝试将旧内容保存到 `AI Drive历史版本(勿删)`，并登记版本元数据。查看和恢复：

```json
{"action":"versions","space":"personal","path":"/reports/monthly.xlsx"}
{"action":"restore","space":"personal","versionID":456}
```

恢复前的当前内容也会尝试保存为一个新版本。底层存储若不支持创建目录、移动或复制，保护操作可能失败；重要挂载点必须先用临时文件验证。

## 保留与灾备

回收站和挂载盘版本目前按保护数据处理，不由 Agent 永久清理。它们仍然位于对应挂载存储中，不等同于独立备份；完整灾备见[运维与备份](operations.md)。

[返回手册首页](README.md)
