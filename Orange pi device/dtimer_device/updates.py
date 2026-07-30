from __future__ import annotations

import json
import os
import re
import subprocess
from datetime import datetime, timezone
from html.parser import HTMLParser
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import Request, urlopen


DEFAULT_UPDATE_BASE_URL = "https://dtimerapp.online"
DEFAULT_USB_ROOTS = (Path("/media"), Path("/mnt"), Path("/run/media"))
MAX_ONLINE_BYTES = 1024 * 1024
MAX_USB_PACKAGES = 200
MAX_USB_DEPTH = 3
PACKAGE_NAME = "dtimer-orange-pi"
PACKAGE_FILE_RE = re.compile(
    r"^dtimer-orange-pi_(?P<version>[^_]+)_(?P<architecture>[^_]+)\.deb$",
    re.IGNORECASE,
)


class UpdateSourceError(RuntimeError):
    pass


class InertiaUpdateParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.in_page_script = False
        self.page_json_parts: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = dict(attrs)
        self.in_page_script = tag == "script" and attributes.get("data-page") == "app"

    def handle_endtag(self, tag: str) -> None:
        if tag == "script":
            self.in_page_script = False

    def handle_data(self, data: str) -> None:
        if self.in_page_script:
            self.page_json_parts.append(data)


def parse_usb_roots(raw: str | None) -> tuple[Path, ...]:
    if not raw:
        return DEFAULT_USB_ROOTS

    roots = []
    for item in raw.split(","):
        value = item.strip()
        if value:
            roots.append(Path(value).expanduser())

    return tuple(roots) or DEFAULT_USB_ROOTS


def version_key(value: str) -> tuple[tuple[int, int | str], ...]:
    parts = re.findall(r"\d+|[A-Za-z]+", value.lstrip("vV"))
    return tuple((1, int(part)) if part.isdigit() else (0, part.lower()) for part in parts)


class UpdateChecker:
    def __init__(
        self,
        base_url: str = DEFAULT_UPDATE_BASE_URL,
        usb_roots: tuple[Path, ...] = DEFAULT_USB_ROOTS,
        fallback_version: str = "",
        installed_version: str | None = None,
    ):
        parsed = urlparse(base_url)
        if parsed.scheme not in {"http", "https"} or not parsed.netloc:
            base_url = DEFAULT_UPDATE_BASE_URL

        self.base_url = base_url.rstrip("/")
        self.usb_roots = usb_roots
        self.fallback_version = fallback_version.strip()
        self._installed_version = installed_version.strip() if installed_version is not None else None

    def check(self) -> dict[str, Any]:
        installed_version = self.installed_version()
        online_updates, online_source = self.online_updates(installed_version)
        usb_updates, usb_source = self.usb_updates(installed_version)
        updates = online_updates + usb_updates
        updates.sort(key=lambda item: version_key(str(item["version"])), reverse=True)

        return {
            "ok": online_source["ok"] or usb_source["ok"],
            "checkedAt": datetime.now(timezone.utc).isoformat(),
            "installedVersion": installed_version,
            "hasNewer": any(item.get("isNewer") is True for item in updates),
            "updates": updates,
            "sources": [online_source, usb_source],
        }

    def installed_version(self) -> str:
        if self._installed_version is not None:
            return self._installed_version or self.fallback_version or "unknown"

        try:
            completed = subprocess.run(
                ["dpkg-query", "-W", "-f=${Version}", PACKAGE_NAME],
                text=True,
                capture_output=True,
                timeout=3,
                check=False,
            )
            version = completed.stdout.strip()
            if completed.returncode == 0 and version:
                return version
        except (FileNotFoundError, subprocess.SubprocessError):
            pass

        return self.fallback_version or "unknown"

    def online_updates(self, installed_version: str) -> tuple[list[dict[str, Any]], dict[str, Any]]:
        collection_url = f"{self.base_url}/api/v1/updates"
        support_url = f"{self.base_url}/support"
        latest_url = f"{self.base_url}/api/v1/updates/latest"
        endpoint = collection_url

        try:
            try:
                payload = self.read_json(collection_url)
                raw_updates = payload.get("updates", []) if isinstance(payload, dict) else payload
                if not isinstance(raw_updates, list):
                    raise UpdateSourceError("The update list response is invalid.")
            except HTTPError as exc:
                if exc.code != 404:
                    raise

                try:
                    endpoint = support_url
                    raw_updates = self.read_support_updates(support_url)
                except (HTTPError, URLError, TimeoutError, json.JSONDecodeError, UpdateSourceError, OSError):
                    endpoint = latest_url
                    payload = self.read_json(latest_url)
                    latest = payload.get("update") if isinstance(payload, dict) else None
                    raw_updates = [latest] if isinstance(latest, dict) else []

            updates = []
            for raw_update in raw_updates:
                normalized = self.normalize_online_update(raw_update, installed_version)
                if normalized:
                    updates.append(normalized)

            return updates, {
                "id": "online",
                "label": urlparse(self.base_url).netloc,
                "ok": True,
                "count": len(updates),
                "endpoint": endpoint,
                "message": f"Found {len(updates)} online release(s).",
            }
        except (HTTPError, URLError, TimeoutError, json.JSONDecodeError, UpdateSourceError, OSError) as exc:
            if isinstance(exc, HTTPError):
                detail = f"HTTP {exc.code}"
            else:
                detail = str(exc) or exc.__class__.__name__

            return [], {
                "id": "online",
                "label": urlparse(self.base_url).netloc,
                "ok": False,
                "count": 0,
                "endpoint": endpoint,
                "message": f"Online update check failed: {detail}",
            }

    def read_json(self, url: str) -> Any:
        raw = self.read_response(url, "application/json")
        return json.loads(raw.decode("utf-8"))

    def read_support_updates(self, url: str) -> list[object]:
        raw = self.read_response(url, "text/html")
        parser = InertiaUpdateParser()
        parser.feed(raw.decode("utf-8"))
        if not parser.page_json_parts:
            raise UpdateSourceError("The support page does not include update metadata.")

        page = json.loads("".join(parser.page_json_parts))
        updates = page.get("props", {}).get("updates") if isinstance(page, dict) else None
        if not isinstance(updates, list):
            raise UpdateSourceError("The support page update metadata is invalid.")

        return updates

    def read_response(self, url: str, accept: str) -> bytes:
        request = Request(
            url,
            headers={
                "Accept": accept,
                "User-Agent": "DTimer-Orange-Pi-Update-Checker/1.0",
            },
        )
        with urlopen(request, timeout=8) as response:
            raw = response.read(MAX_ONLINE_BYTES + 1)

        if len(raw) > MAX_ONLINE_BYTES:
            raise UpdateSourceError("The update response is too large.")

        return raw

    def normalize_online_update(
        self,
        raw_update: object,
        installed_version: str,
    ) -> dict[str, Any] | None:
        if not isinstance(raw_update, dict):
            return None

        version = str(raw_update.get("version") or "").strip()[:50]
        if not version:
            return None

        download_url = str(raw_update.get("downloadUrl") or "").strip()
        parsed_download = urlparse(download_url)
        if parsed_download.scheme not in {"http", "https"} or not parsed_download.netloc:
            download_url = ""

        return {
            "id": f"online:{raw_update.get('id') or version}",
            "source": "online",
            "sourceLabel": urlparse(self.base_url).netloc,
            "title": str(raw_update.get("title") or "DTimer update").strip()[:120],
            "version": version,
            "description": str(raw_update.get("description") or "").strip()[:4000],
            "fileName": str(raw_update.get("fileName") or "").strip()[:255],
            "fileSize": self.safe_int(raw_update.get("fileSize")),
            "publishedAt": raw_update.get("publishedAt"),
            "downloadUrl": download_url or None,
            "location": None,
            "isNewer": self.is_newer(version, installed_version),
            "packageVerified": None,
        }

    def usb_updates(self, installed_version: str) -> tuple[list[dict[str, Any]], dict[str, Any]]:
        updates = []
        available_roots = []
        seen_paths: set[Path] = set()

        try:
            for configured_root in self.usb_roots:
                root = configured_root.resolve()
                if not root.is_dir():
                    continue

                available_roots.append(root)
                for package_path in self.iter_deb_files(root):
                    resolved_package = package_path.resolve()
                    if resolved_package in seen_paths:
                        continue
                    seen_paths.add(resolved_package)

                    metadata = self.read_deb_metadata(resolved_package)
                    if not metadata or metadata["package"] != PACKAGE_NAME:
                        continue

                    stat = resolved_package.stat()
                    updates.append(
                        {
                            "id": f"usb:{resolved_package}",
                            "source": "usb",
                            "sourceLabel": f"USB - {root.name or root}",
                            "title": "DTimer Orange Pi package",
                            "version": metadata["version"],
                            "description": "",
                            "fileName": resolved_package.name,
                            "fileSize": stat.st_size,
                            "publishedAt": datetime.fromtimestamp(stat.st_mtime, timezone.utc).isoformat(),
                            "downloadUrl": None,
                            "location": str(resolved_package.relative_to(root)),
                            "isNewer": self.is_newer(metadata["version"], installed_version),
                            "packageVerified": metadata["verified"],
                        }
                    )
                    if len(updates) >= MAX_USB_PACKAGES:
                        break

                if len(updates) >= MAX_USB_PACKAGES:
                    break

            if not available_roots:
                return [], {
                    "id": "usb",
                    "label": "USB storage",
                    "ok": False,
                    "count": 0,
                    "message": "No mounted USB update folders were found.",
                }

            return updates, {
                "id": "usb",
                "label": "USB storage",
                "ok": True,
                "count": len(updates),
                "message": f"Found {len(updates)} DTimer package(s) on USB storage.",
            }
        except OSError as exc:
            return updates, {
                "id": "usb",
                "label": "USB storage",
                "ok": False,
                "count": len(updates),
                "message": f"USB update scan failed: {exc}",
            }

    def iter_deb_files(self, root: Path):
        yielded = 0
        for current_dir, dir_names, file_names in os.walk(root):
            current_path = Path(current_dir)
            try:
                depth = len(current_path.relative_to(root).parts)
            except ValueError:
                continue

            dir_names[:] = [
                name
                for name in dir_names
                if not name.startswith(".") and not (current_path / name).is_symlink()
            ]
            if depth >= MAX_USB_DEPTH:
                dir_names[:] = []

            for file_name in file_names:
                if not file_name.lower().endswith(".deb"):
                    continue

                package_path = current_path / file_name
                if package_path.is_symlink() or not package_path.is_file():
                    continue

                yield package_path
                yielded += 1
                if yielded >= MAX_USB_PACKAGES:
                    return

    def read_deb_metadata(self, package_path: Path) -> dict[str, Any] | None:
        try:
            completed = subprocess.run(
                ["dpkg-deb", "-f", str(package_path), "Package", "Version", "Architecture"],
                text=True,
                capture_output=True,
                timeout=5,
                check=False,
            )
            if completed.returncode == 0:
                fields = {}
                for line in completed.stdout.splitlines():
                    if ":" not in line:
                        continue
                    key, value = line.split(":", 1)
                    fields[key.strip().lower()] = value.strip()

                if fields.get("package") and fields.get("version"):
                    return {
                        "package": fields["package"],
                        "version": fields["version"][:50],
                        "architecture": fields.get("architecture", ""),
                        "verified": True,
                    }
        except (FileNotFoundError, subprocess.SubprocessError):
            pass

        match = PACKAGE_FILE_RE.match(package_path.name)
        if not match:
            return None

        return {
            "package": PACKAGE_NAME,
            "version": match.group("version")[:50],
            "architecture": match.group("architecture"),
            "verified": False,
        }

    def is_newer(self, candidate: str, installed: str) -> bool | None:
        if not installed or installed == "unknown":
            return None

        try:
            completed = subprocess.run(
                ["dpkg", "--compare-versions", candidate, "gt", installed],
                capture_output=True,
                timeout=3,
                check=False,
            )
            return completed.returncode == 0
        except (FileNotFoundError, subprocess.SubprocessError):
            return version_key(candidate) > version_key(installed)

    @staticmethod
    def safe_int(value: object) -> int:
        try:
            return max(0, int(value or 0))
        except (TypeError, ValueError):
            return 0
