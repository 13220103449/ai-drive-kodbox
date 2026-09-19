# AI Drive v0.4.8

- Resolve Agent paths through KodBox's real directory view, including virtual personal-space folders such as `我的文档`.
- Accept `newName`, `to`, `dest`, and `destination` consistently for rename.
- Allow move/copy destinations to be either an existing folder or a complete target path.
- Fall back to verified copy-and-delete when a storage driver rejects a direct move.
- Treat empty-folder deletion as successful when the folder is actually gone, even if the storage driver returns a false-like value.
