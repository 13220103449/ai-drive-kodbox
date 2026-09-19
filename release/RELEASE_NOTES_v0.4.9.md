# AI Drive v0.4.9

This release fixes strict Agent path handling after the v0.4.8 regression.

- Resolve children from the real KodBox `Source` hierarchy instead of display aliases.
- Return HTTP 404 for missing folders and files instead of falling back to the space root.
- Create every missing intermediate folder for nested `mkdir` requests.
- Verify uploaded and written files through the exact target path before reporting success.
- Keep existing personal and department space data unchanged during upgrades.
