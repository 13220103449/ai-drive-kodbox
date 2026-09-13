# AI Drive v0.4.7

- Installs stable WebDAV `/personal/` and `/department/` aliases from the AI Drive plugin on the first authenticated Agent request.
- Uses a narrow, atomic source patch and invalidates PHP opcode cache after installation.
- Keeps the online-update archive fully compatible with older AI Drive updaters.
- Retains all `write`, `upload`, `rename`, and Docker persistence fixes.
