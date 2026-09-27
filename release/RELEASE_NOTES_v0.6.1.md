# AI Drive v0.6.1

## 百度网盘等可选挂载存储的 Agent 上传修复

- Agent API 请求现在会加载 KodBox 已安装的 `pathDriver*.class.php` 存储驱动。
- 修复个人空间挂载百度网盘时，API 上传失败并返回 `PathDriverBaidu not exists!` 的问题。
- 不修改现有 Agent 账号、Token、百度网盘授权或挂载配置。
- 伪回收站、历史版本及其他 API 行为保持不变。
