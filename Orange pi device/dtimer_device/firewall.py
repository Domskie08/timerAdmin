from __future__ import annotations

import json
import os
import platform
import subprocess

from .config import DeviceConfig
from .store import InternetSession


class FirewallController:
    """Persists desired access state and optionally calls the Linux enforcer.

    The web app is the control plane. The firewall is the enforcement plane.
    Enforcing is opt-in through DTIMER_ENFORCE_NETWORK=1 so the app remains
    testable on Windows/macOS and safe during first setup.
    """

    def __init__(self, config: DeviceConfig, store: object | None = None):
        self.config = config
        self.store = store

    def reconcile(self, sessions: list[InternetSession]) -> dict[str, object]:
        payload = {
            "active_sessions": [session.to_dict() for session in sessions],
            "allowed_ips": sorted({session.client_ip for session in sessions}),
            "allowed_macs": sorted({session.client_mac for session in sessions if session.client_mac}),
        }
        self.config.data_dir.mkdir(parents=True, exist_ok=True)
        self.config.active_sessions_path.write_text(json.dumps(payload, indent=2), encoding="utf-8")

        configured = False
        customer_interface = os.getenv("DTIMER_CUSTOMER_INTERFACE", "wlan0")
        wan_interface = os.getenv("DTIMER_WAN_INTERFACE", "eth0")
        if self.store is not None:
            get_setting = getattr(self.store, "get_setting")
            configured = get_setting("network_enforcement_enabled", "0") == "1"
            customer_interface = get_setting("customer_interface", customer_interface) or customer_interface
            wan_interface = get_setting("wan_interface", wan_interface) or wan_interface

        enforce = os.getenv("DTIMER_ENFORCE_NETWORK", "0") == "1" or configured
        if not enforce:
            result = {"enforced": False, "message": "Dry run only. Set DTIMER_ENFORCE_NETWORK=1 on the Orange Pi to apply firewall rules."}
            self.config.firewall_state_path.write_text(json.dumps({**payload, **result}, indent=2), encoding="utf-8")
            return result

        if platform.system().lower() != "linux":
            result = {"enforced": False, "message": "Network enforcement requires Linux."}
            self.config.firewall_state_path.write_text(json.dumps({**payload, **result}, indent=2), encoding="utf-8")
            return result

        script = self.config.project_dir / "scripts" / "apply_network_rules.sh"
        env = os.environ.copy()
        env["DTIMER_CUSTOMER_INTERFACE"] = customer_interface
        env["DTIMER_WAN_INTERFACE"] = wan_interface
        completed = subprocess.run(
            [str(script), str(self.config.active_sessions_path)],
            text=True,
            capture_output=True,
            timeout=15,
            check=False,
            env=env,
        )
        result = {
            "enforced": completed.returncode == 0,
            "returncode": completed.returncode,
            "stdout": completed.stdout[-2000:],
            "stderr": completed.stderr[-2000:],
        }
        self.config.firewall_state_path.write_text(json.dumps({**payload, **result}, indent=2), encoding="utf-8")

        return result
