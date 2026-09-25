# AI Drive v0.5.0

## Agent operations

- Adds an administrator Agent dashboard with active/online/request/failure counters.
- Adds searchable audit-log and file-version views.
- Adds pause/resume controls for individual Agents.
- Token rotation now provides a 24-hour overlap window so long-running Agents can migrate without downtime.

## Data protection

- Captures a SHA-256 verified snapshot before an Agent overwrites, deletes, or restores a file.
- Adds `versions` and `restore` Agent API actions.
- Stores version payloads outside the public web root under the persistent KodBox data directory.
- Adds retention pruning support for future scheduled maintenance.

## Safe updates

- Records successful and failed update attempts.
- Runs a post-install health check and restores the plugin backup on failure.
- Adds update history and one-click rollback, with a fresh safety backup before rollback.
- Continues to require GitHub HTTPS allow-listing and SHA-256 package verification.

Existing Agent accounts, files, departments, tokens and WebDAV settings are preserved.
