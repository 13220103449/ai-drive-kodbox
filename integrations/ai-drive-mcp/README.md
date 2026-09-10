# AI Drive MCP

This zero-dependency Node.js adapter exposes an Agent's AI Drive account as MCP tools.

## Environment

- `AI_DRIVE_URL`: KodBox base URL, for example `https://drive.example.com`
- `AI_DRIVE_TOKEN`: the one-time `aidv_...` token issued for this Agent
- `AI_DRIVE_SPACE`: `personal` for the Agent's own disk or `department` for the shared 智能体 disk

## MCP client configuration

```json
{
  "mcpServers": {
    "ai-drive": {
      "command": "node",
      "args": ["/absolute/path/to/integrations/ai-drive-mcp/server.mjs"],
      "env": {
        "AI_DRIVE_URL": "https://drive.example.com",
        "AI_DRIVE_TOKEN": "aidv_REPLACE_ME",
        "AI_DRIVE_SPACE": "personal"
      }
    }
  }
}
```

Each Agent should receive its own token and KodBox account. The MCP tools cover list, stat, text read/write, upload, download, mkdir, rename, move, copy, delete and public share.
