# AI Drive v0.6.2

## 挂载存储驱动诊断与加载

- Agent API 现在递归搜索 KodBox 插件目录中的 `pathDriver*.class.php`，兼容驱动位于多级子目录的安装包。
- `capabilities` 增加已加载驱动类及百度网盘驱动状态，便于验证真实部署环境。
- 不修改现有 Agent 账号、Token、百度网盘授权或挂载配置。
