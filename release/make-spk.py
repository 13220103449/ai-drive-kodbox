#!/usr/bin/env python3
"""Create a deterministic DSM SPK with correct Unix ownership and modes."""

import argparse
import gzip
import io
import os
import tarfile
from pathlib import Path


def normalized(info: tarfile.TarInfo, executable: bool = False) -> tarfile.TarInfo:
    info.uid = 0
    info.gid = 0
    info.uname = "root"
    info.gname = "root"
    info.mtime = 0
    info.mode = 0o755 if info.isdir() or executable else 0o644
    return info


def add_tree(archive: tarfile.TarFile, root: Path, prefix: str = "") -> None:
    for path in sorted(root.rglob("*"), key=lambda item: item.as_posix()):
        relative = path.relative_to(root).as_posix()
        name = f"{prefix}/{relative}" if prefix else relative
        archive.add(path, arcname=name, recursive=False, filter=normalized)


def build_payload(payload: Path, destination: Path) -> None:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w", format=tarfile.USTAR_FORMAT) as archive:
        add_tree(archive, payload)
    with destination.open("wb") as stream:
        with gzip.GzipFile(filename="", mode="wb", fileobj=stream, mtime=0) as zipped:
            zipped.write(buffer.getvalue())


def build_spk(metadata: Path, output: Path) -> None:
    members = ["INFO", "package.tgz", "scripts", "conf", "LICENSE", "PACKAGE_ICON.PNG", "PACKAGE_ICON_256.PNG"]
    with tarfile.open(output, mode="w", format=tarfile.USTAR_FORMAT) as archive:
        for member in members:
            path = metadata / member
            if path.is_dir():
                archive.add(path, arcname=member, recursive=False, filter=normalized)
                for child in sorted(path.rglob("*"), key=lambda item: item.as_posix()):
                    executable = member == "scripts" and child.is_file()
                    archive.add(child, arcname=child.relative_to(metadata).as_posix(), recursive=False,
                                filter=lambda info, executable=executable: normalized(info, executable))
            else:
                archive.add(path, arcname=member, recursive=False, filter=normalized)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--payload", required=True, type=Path)
    parser.add_argument("--metadata", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    build_payload(args.payload, args.metadata / "package.tgz")
    build_spk(args.metadata, args.output)


if __name__ == "__main__":
    main()
