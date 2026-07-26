from __future__ import annotations

import json
import urllib.error
import urllib.request
from typing import Any

from .store import DeviceStore


class TimerAdminSync:
    def __init__(self, store: DeviceStore):
        self.store = store

    def run_once(self) -> dict[str, Any]:
        base_url = self.store.get_setting("timeradmin_base_url").rstrip("/")
        license_key = self.store.get_setting("license_key")
        device_secret = self.store.get_setting("device_secret")

        if not base_url or not license_key or not device_secret:
            return {
                "ok": False,
                "synced": 0,
                "message": "TimerAdmin URL, license key, and device secret are required before sync.",
            }

        synced = 0
        heartbeat = self._post(
            f"{base_url}/api/v1/dtimer/heartbeat",
            {
                "license_key": license_key,
                "device_secret": device_secret,
                "device_id": self.store.get_setting("machine_id") or self.store.get_setting("mac_address"),
                "device_name": self.store.get_setting("device_name"),
                "mac_address": self.store.get_setting("mac_address"),
                "machine_id": self.store.get_setting("machine_id"),
                "app_version": self.store.get_setting("app_version"),
                "firmware_version": self.store.get_setting("firmware_version"),
                "wifi_status": "online",
                "timer_status": "running",
                "connected_users": self.store.stats()["active_sessions"],
                "active_sessions": self.store.stats()["active_sessions"],
            },
        )

        if not heartbeat["ok"]:
            return heartbeat

        for row in self.store.pending_sync_events():
            try:
                payload = json.loads(str(row["payload_json"]))
                response = self._post(
                    f"{base_url}/api/v1/dtimer/coin-sales/batch",
                    {
                        "license_key": license_key,
                        "device_secret": device_secret,
                        "device_id": self.store.get_setting("machine_id") or self.store.get_setting("mac_address"),
                        "mac_address": self.store.get_setting("mac_address"),
                        "events": [payload],
                    },
                )
                if response["ok"]:
                    self.store.mark_synced(int(row["id"]))
                    synced += 1
                else:
                    self.store.mark_sync_failed(int(row["id"]), str(response.get("message", "Sync failed")))
                    break
            except (json.JSONDecodeError, TypeError, ValueError) as exc:
                self.store.mark_sync_failed(int(row["id"]), str(exc))

        return {"ok": True, "synced": synced, "message": f"Synced {synced} queued event(s)."}

    def _post(self, url: str, payload: dict[str, Any]) -> dict[str, Any]:
        body = json.dumps(payload).encode("utf-8")
        request = urllib.request.Request(
            url,
            data=body,
            headers={"Content-Type": "application/json", "Accept": "application/json"},
            method="POST",
        )

        try:
            with urllib.request.urlopen(request, timeout=10) as response:
                response_body = response.read().decode("utf-8")
                data = json.loads(response_body) if response_body else {}
                return {"ok": 200 <= response.status < 300, "status": response.status, "data": data}
        except urllib.error.HTTPError as exc:
            return {"ok": False, "status": exc.code, "message": exc.read().decode("utf-8", errors="replace")}
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            return {"ok": False, "message": str(exc)}
