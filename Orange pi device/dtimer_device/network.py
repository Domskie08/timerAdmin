from __future__ import annotations

import ipaddress
import subprocess


def client_ip_from_headers(client_address: tuple[str, int], headers: object) -> str:
    raw = ""
    try:
        raw = str(headers.get("X-Forwarded-For", "")).split(",", 1)[0].strip()
    except AttributeError:
        raw = ""

    candidate = raw or client_address[0]
    try:
        return str(ipaddress.ip_address(candidate))
    except ValueError:
        return "0.0.0.0"


def resolve_client_mac(client_ip: str) -> str | None:
    """Best effort MAC lookup for LAN clients.

    Browsers do not send their MAC address. On Linux, the gateway can usually
    discover it from the ARP/neighbor table after the client talks to the Pi.
    """

    proc_arp = "/proc/net/arp"
    try:
        with open(proc_arp, "r", encoding="utf-8") as arp_file:
            for line in arp_file.readlines()[1:]:
                parts = line.split()
                if len(parts) >= 4 and parts[0] == client_ip and parts[3] != "00:00:00:00:00:00":
                    return parts[3].upper()
    except OSError:
        pass

    try:
        completed = subprocess.run(
            ["ip", "neigh", "show", client_ip],
            text=True,
            capture_output=True,
            timeout=2,
            check=False,
        )
    except (OSError, subprocess.SubprocessError):
        return None

    parts = completed.stdout.split()
    if "lladdr" in parts:
        idx = parts.index("lladdr")
        if idx + 1 < len(parts):
            return parts[idx + 1].upper()

    return None
