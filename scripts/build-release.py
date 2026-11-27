#!/usr/bin/env python3
"""Build the deterministic runtime ZIP for a release or a staging candidate.

This script never tags or publishes. Candidate mode cannot emit a release manifest.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import zipfile

SLUG = "ach-wp-accounts"
ZIP = SLUG + ".zip"
MANIFEST = SLUG + "-release.json"


def header(text, name, required=True):
    result = re.search(r"^\s*\*\s*" + re.escape(name) + r":\s*([^\r\n]+)$", text, re.M)
    if result:
        return result.group(1).strip()
    if required:
        raise ValueError(f"Missing plugin header: {name}")
    return ""


def build(source, output, tested_wp, candidate=False):
    entry = source / (SLUG + ".php")
    text = entry.read_text(encoding="utf-8")
    version = header(text, "Version")
    stable_pattern = r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
    version_pattern = stable_pattern + (r"(?:-dev)?" if candidate else "")
    if not re.fullmatch(version_pattern, version, re.ASCII):
        raise ValueError("Invalid candidate version." if candidate else
                         "The release plugin header must contain a stable X.Y.Z version.")
    requires_php = header(text, "Requires PHP")
    if requires_php != "8.1":
        raise ValueError("The approved minimum PHP version is 8.1.")
    requires_wp = header(text, "Requires at least")
    if requires_wp != "6.3":
        raise ValueError("The validated minimum WordPress version is 6.3.")
    if any(value and not re.fullmatch(r"[0-9]+(?:\.[0-9]+){1,2}", value) for value in
           (requires_php, requires_wp, tested_wp)):
        raise ValueError("Invalid compatibility version.")
    if header(text, "Update URI") != "https://github.com/AChWorks/ach-wp-accounts":
        raise ValueError("Unexpected update repository.")
    # Only installed runtime file types, not source catalogs, development
    # files, caches or arbitrary files placed beside a runtime asset.
    runtime_types = {
        "includes": {".php"},
        "assets": {".css", ".js", ".svg", ".png", ".jpg", ".jpeg", ".webp", ".woff", ".woff2"},
        "languages": {".mo", ".json", ".php"},
    }
    files = [entry]
    for folder, suffixes in runtime_types.items():
        directory = source / folder
        if not directory.is_dir() or directory.is_symlink():
            raise ValueError(f"Missing or symlinked runtime directory: {folder}")
        # Runtime layout is deliberately flat today. Explicitly extend this
        # contract when actual nested runtime modules/assets are introduced.
        for path in directory.iterdir():
            if path.is_symlink():
                raise ValueError(f"Symlink in release source: {path.relative_to(source)}")
            if path.is_file() and not path.name.startswith(".") and path.suffix.lower() in suffixes:
                files.append(path)
    license_file = source / "LICENSE"
    if not license_file.is_file() or license_file.is_symlink():
        raise ValueError("Missing release license.")
    files.append(license_file)
    output.mkdir(parents=True, exist_ok=True)
    if candidate and (output / MANIFEST).exists():
        raise ValueError("Candidate output must not contain a release manifest.")
    archive = output / ZIP
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for path in sorted(files):
            if path.is_symlink():
                raise ValueError("Release contains a symlink.")
            item = zipfile.ZipInfo(f"{SLUG}/{path.relative_to(source).as_posix()}", (1980, 1, 1, 0, 0, 0))
            item.external_attr = 0o100644 << 16
            item.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(item, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    with archive.open("rb") as f:
        digest = hashlib.sha256()
        for block in iter(lambda: f.read(65536), b""):
            digest.update(block)
    manifest = {
        "slug": SLUG, "version": version, "package": ZIP,
        "requires_php": requires_php, "requires_wp": requires_wp,
        "tested_wp": tested_wp, "size": archive.stat().st_size,
        "sha256": digest.hexdigest(),
    }
    if candidate:
        print(f"Staging only: {SLUG} {version}, {archive.name}, SHA-256 {manifest['sha256']}")
    else:
        (output / MANIFEST).write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print(f"Prepared {SLUG} v{version}: {archive.name} and {MANIFEST}")
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--output", type=Path)
    parser.add_argument("--tested-wp", default="", help="WordPress version verified for this release (optional)")
    parser.add_argument("--candidate", action="store_true", help="Build staging ZIP only, never a release manifest")
    args = parser.parse_args()
    if args.candidate and args.output is None:
        parser.error("--candidate requires an explicit staging --output directory")
    source = args.source.resolve()
    build(source, args.output or source / "dist", args.tested_wp, args.candidate)


if __name__ == "__main__":
    main()
