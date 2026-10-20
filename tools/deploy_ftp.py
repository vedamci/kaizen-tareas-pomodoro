#!/usr/bin/env python3
"""Deploy tracked Kaizen runtime files to cPanel over verified explicit FTPS."""

from __future__ import annotations

import argparse
import io
import json
import os
import re
import ssl
import subprocess
import sys
from ftplib import FTP_TLS, error_perm
from pathlib import Path, PurePosixPath


MANIFEST = ".kaizen-deploy-manifest.json"
EXCLUDED_FILES = {
    ".gitignore",
    ".thumbnail",
    "Gestor de Tareas.dc.html",  # A server-side divergent editor export; index.html is served.
    "MCP.md",
    "README.md",
    "api/config.example.php",
}
EXCLUDED_PREFIXES = (
    ".githooks/",
    ".github/",
    "deployment/",
    "docs/",
    "integrations/",
    "preview/",
    "tests/",
    "tools/",
)
PROTECTED_REMOTE_FILES = {
    "api/config.php",
    "api/mcp-local.php",
    "github-webhook.php",
    "github-webhook-secret.txt",
    "Gestor de Tareas.dc.html",
}


def safe_relative_path(value: str) -> str:
    if not value or "\x00" in value or "\r" in value or "\n" in value or "\\" in value:
        raise ValueError(f"Invalid repository path: {value!r}")
    path = PurePosixPath(value)
    if path.is_absolute() or any(part in ("", ".", "..") for part in path.parts):
        raise ValueError(f"Unsafe repository path: {value!r}")
    return path.as_posix()


def is_deployable(path: str) -> bool:
    if path in EXCLUDED_FILES or path in PROTECTED_REMOTE_FILES:
        return False
    if path.startswith(EXCLUDED_PREFIXES):
        return False
    if path.lower().endswith((".md", ".sql", ".log")):
        return False
    if path.endswith(".oxlintrc.json"):
        return False
    return True


def tracked_runtime_files() -> list[str]:
    result = subprocess.run(
        ["git", "ls-files", "-z"], check=True, stdout=subprocess.PIPE
    )
    paths = sorted(
        safe_relative_path(raw.decode("utf-8"))
        for raw in result.stdout.split(b"\0")
        if raw
    )
    root = Path.cwd().resolve()
    for relative in paths:
        source = Path(relative)
        if source.is_symlink():
            raise RuntimeError(f"Refusing to deploy symlink: {relative}")
        resolved = source.resolve()
        try:
            resolved.relative_to(root)
        except ValueError as exc:
            raise RuntimeError(f"Refusing path outside repository: {relative}") from exc
        if is_deployable(relative) and not resolved.is_file():
            raise RuntimeError(f"Tracked deployment file is missing: {relative}")
    return [path for path in paths if is_deployable(path)]


def remote_parent(ftp: FTP_TLS, remote_root: str, relative: str, create: bool = True) -> str | None:
    ftp.cwd(remote_root)
    parent = PurePosixPath(relative).parent
    for part in parent.parts:
        if part in ("/", "."):
            continue
        try:
            ftp.cwd(part)
        except error_perm as exc:
            if not create:
                if str(exc).startswith("550"):
                    return None
                raise
            try:
                ftp.mkd(part)
            except error_perm:
                # If it already exists but cannot be created, cwd reports the real error.
                ftp.cwd(part)
            else:
                ftp.cwd(part)
    return PurePosixPath(relative).name


def stage_bytes(ftp: FTP_TLS, remote_root: str, relative: str, data: bytes, tag: str) -> str:
    name = remote_parent(ftp, remote_root, relative)
    temporary = f".{name}.github-deploy-{tag}.tmp"
    ftp.storbinary(f"STOR {temporary}", io.BytesIO(data))
    parent = PurePosixPath(relative).parent
    return temporary if str(parent) == "." else f"{parent.as_posix()}/{temporary}"


def promote(ftp: FTP_TLS, remote_root: str, relative: str, staged: str, tag: str) -> None:
    name = remote_parent(ftp, remote_root, relative)
    staged_name = PurePosixPath(staged).name
    backup = f".{name}.github-deploy-{tag}.bak"
    moved_old = False
    try:
        ftp.rename(name, backup)
        moved_old = True
    except error_perm:
        # Most commonly the destination does not exist yet. The next rename will
        # still fail if the server cannot publish the staged file.
        pass
    try:
        ftp.rename(staged_name, name)
    except Exception:
        if moved_old:
            try:
                ftp.rename(backup, name)
            except Exception as restore_error:
                raise RuntimeError(f"Could not restore previous file {relative}") from restore_error
        raise
    if moved_old:
        try:
            ftp.delete(backup)
        except error_perm:
            print(f"::warning::Could not remove temporary backup for {relative}")


def read_manifest(ftp: FTP_TLS, remote_root: str) -> set[str]:
    ftp.cwd(remote_root)
    data = io.BytesIO()
    try:
        ftp.retrbinary(f"RETR {MANIFEST}", data.write)
    except error_perm as exc:
        if str(exc).startswith("550"):
            return set()
        raise
    payload = json.loads(data.getvalue().decode("utf-8"))
    files = payload.get("managed_files") if isinstance(payload, dict) else None
    if not isinstance(files, list):
        raise RuntimeError("Remote deployment manifest has an invalid format")
    return {
        safe_relative_path(path)
        for path in files
        if isinstance(path, str) and path not in PROTECTED_REMOTE_FILES
    }


def delete_remote(ftp: FTP_TLS, remote_root: str, relative: str) -> bool:
    if relative in PROTECTED_REMOTE_FILES:
        return True
    name = remote_parent(ftp, remote_root, relative, create=False)
    if name is None:
        return True
    try:
        ftp.delete(name)
        return True
    except error_perm as exc:
        # Keep failed paths in the manifest so a later deployment retries them.
        if str(exc).startswith("550"):
            return False
        raise


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--apply", action="store_true", help="upload files; without this flag only list them")
    mode.add_argument("--dry-run", action="store_true", help="list selected files without connecting")
    args = parser.parse_args()

    files = tracked_runtime_files()
    print(f"Selected {len(files)} tracked runtime files for deployment:")
    for path in files:
        print(f"  {path}")
    if not args.apply:
        print("Dry run only; no server connection or changes made.")
        return 0

    host = os.environ.get("KAIZEN_FTP_HOST", "").strip()
    username = os.environ.get("KAIZEN_FTP_USERNAME", "")
    password = os.environ.get("KAIZEN_FTP_PASSWORD", "")
    remote_root = os.environ.get("KAIZEN_FTP_REMOTE_DIR", "").strip()
    if not host or not username or not password or not remote_root:
        raise RuntimeError(
            "Set KAIZEN_FTP_HOST, KAIZEN_FTP_USERNAME, KAIZEN_FTP_PASSWORD, "
            "and KAIZEN_FTP_REMOTE_DIR as GitHub Actions secrets."
        )
    if any(char in value for value in (host, username, password, remote_root) for char in "\r\n\x00"):
        raise RuntimeError("FTP connection settings contain an invalid control character")
    port_text = os.environ.get("KAIZEN_FTP_PORT", "21").strip() or "21"
    if not re.fullmatch(r"\d{1,5}", port_text) or not 1 <= int(port_text) <= 65535:
        raise RuntimeError("KAIZEN_FTP_PORT must be a TCP port from 1 to 65535")

    commit = re.sub(r"[^A-Za-z0-9_-]", "", os.environ.get("GITHUB_SHA", "local"))[:12] or "local"
    run = re.sub(r"[^A-Za-z0-9_-]", "", os.environ.get("GITHUB_RUN_ID", str(os.getpid())))
    attempt = re.sub(r"[^A-Za-z0-9_-]", "", os.environ.get("GITHUB_RUN_ATTEMPT", "1"))
    tag = f"{commit}-{run}-{attempt}"
    context = ssl.create_default_context()
    ftp = FTP_TLS(context=context, timeout=30)
    try:
        ftp.connect(host, int(port_text))
        ftp.login(username, password)
        ftp.prot_p()
        ftp.set_pasv(True)
        ftp.cwd(remote_root)
        old_managed = read_manifest(ftp, remote_root)

        staged: list[tuple[str, str]] = []
        try:
            for relative in files:
                staged.append((relative, stage_bytes(ftp, remote_root, relative, Path(relative).read_bytes(), tag)))
            for relative, temporary in staged:
                promote(ftp, remote_root, relative, temporary, tag)
        except Exception:
            for _, temporary in staged:
                try:
                    delete_remote(ftp, remote_root, temporary)
                except Exception:
                    pass
            raise

        current = set(files)
        pending_deletes = set()
        for obsolete in sorted(old_managed - current):
            if not delete_remote(ftp, remote_root, obsolete):
                pending_deletes.add(obsolete)
                print(f"::warning::Could not remove previously managed file {obsolete}; will retry later")

        manifest_data = json.dumps(
            {"version": 1, "commit": commit, "managed_files": sorted(current | pending_deletes)},
            ensure_ascii=False,
            separators=(",", ":"),
        ).encode("utf-8")
        manifest_stage = stage_bytes(ftp, remote_root, MANIFEST, manifest_data, tag)
        promote(ftp, remote_root, MANIFEST, manifest_stage, tag)
        print(f"Published {len(files)} runtime files over verified FTPS from commit {commit}.")
    finally:
        try:
            ftp.quit()
        except Exception:
            ftp.close()
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"::error::{exc}", file=sys.stderr)
        sys.exit(1)
