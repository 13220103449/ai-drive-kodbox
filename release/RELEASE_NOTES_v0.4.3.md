# AI Drive v0.4.3

This release repairs the Agent write paths reported by the OpenClaw binding probe.

- Load KodBox's optional WebDAV/NFS/Samba storage drivers for Agent API requests.
- Make `write` create missing files and overwrite existing files.
- Accept the documented text/base64 payload aliases and `parentPath` + `name` form.
- Validate PHP multipart upload errors before writing.
- Automatically enable the KodBox WebDAV server for Agent accounts.
- Return the real WebDAV endpoint and authentication mode in `capabilities`.

After updating, call any authenticated Agent API action once and then use:

`/index.php/plugin/webdav/aidrive/`

with the Agent's KodBox username and password (HTTP Basic authentication).
