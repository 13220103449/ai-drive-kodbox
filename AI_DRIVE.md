# AI Drive on KodBox

AI Drive is now developed as an upgrade-friendly KodBox extension instead of a replacement file manager.

## Product boundary

- KodBox owns users, departments, permissions, files, versions, shares, WebDAV, storage drivers and Office plugins.
- `plugins/aiDrive` owns Agent identities, one-time machine credentials, Agent REST/MCP access and Agent audit events.
- CloudDrive2 is mounted through KodBox WebDAV/storage drivers; it is not reimplemented.
- Upstream KodBox files should stay unchanged whenever a hook or plugin endpoint can provide the feature.

## Agent account model

- Enabling the plugin creates a first-level `智能体` department.
- Creating an Agent without `userID` creates a real KodBox user in that department.
- The returned KodBox username/password can be used through the web UI or WebDAV.
- The returned `aidv_...` token can be used through REST or the bundled MCP adapter.
- Tokens and generated passwords are displayed once; only the token digest is stored.

## Endpoints

- `GET /index.php/plugin/aiDrive/health`
- `GET /index.php/plugin/aiDrive/agents` — root administrator
- `POST /index.php/plugin/aiDrive/agents` — root administrator; JSON `{ "name": "OpenClaw", "username": "openclaw", "sizeMax": 0 }`
- `DELETE /index.php/plugin/aiDrive/agents` — root administrator; JSON `{ "agentID": "agent_..." }`
- `POST /index.php/plugin/aiDrive/api` — `Authorization: Bearer aidv_...`
- `POST /index.php/plugin/aiDrive/webdav` — root administrator; enables WebDAV access to the Agent's visible spaces

File actions are `list`, `stat`, `read`, `download`, `write`, `upload`, `mkdir`, `rename`, `move`, `copy`, `delete`, `trash`, `restoreTrash`, `share`, `versions` and `restore`. `write` creates a missing file or overwrites an existing file and accepts `content`, `text`, `fileContent`, `data` or `body`; set `encoding: "base64"` for binary data. Upload uses multipart field `file`. Paths are relative to `space: "personal"` (default) or the shared `space: "department"` for the 智能体 department.

Deletion is non-destructive. AI Drive moves deleted files and folders into `AI Drive回收站(勿删)` at the mounted storage root. Before an overwrite or version restore, the old file is copied into `AI Drive历史版本(勿删)`. These folders are hidden and read-only through normal Agent file actions but remain visible to the storage administrator. AI Drive does not expose permanent deletion to Agents.

Paths are resolved strictly against the real KodBox hierarchy. Missing paths return HTTP 404 and never fall back to the space root. `mkdir` creates missing intermediate folders, while upload requires its destination folder to exist.

For Agent onboarding, request examples, path rules and post-upload integrity checks, see [AGENT_GUIDE.md](AGENT_GUIDE.md).

## Connection choices

- MCP: use `integrations/ai-drive-mcp/server.mjs` with `AI_DRIVE_URL` and `AI_DRIVE_TOKEN`.
- REST: call the Agent API directly with a Bearer token.
- WebDAV: use `/index.php/plugin/webdav/aidrive/` and the Agent's KodBox username/password. The WebDAV root exposes the Agent's personal space and the `智能体` department space.
