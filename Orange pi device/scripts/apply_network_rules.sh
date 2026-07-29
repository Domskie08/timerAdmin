#!/usr/bin/env bash
set -euo pipefail

STATE_FILE="${1:-}"
CUSTOMER_IFACE="${DTIMER_CUSTOMER_INTERFACE:-wlan0}"
WAN_IFACE="${DTIMER_WAN_INTERFACE:-eth0}"

if [[ -z "${STATE_FILE}" || ! -f "${STATE_FILE}" ]]; then
  echo "Usage: apply_network_rules.sh /path/to/active-sessions.json" >&2
  exit 2
fi

if ! command -v nft >/dev/null 2>&1; then
  echo "nft command was not found. Install nftables." >&2
  exit 3
fi

mapfile -t ALLOWED_V4 < <(
  python3 - "${STATE_FILE}" <<'PY'
import ipaddress
import json
import sys

with open(sys.argv[1], "r", encoding="utf-8") as handle:
    data = json.load(handle)

for raw in data.get("allowed_ips", []):
    try:
        ip = ipaddress.ip_address(raw)
    except ValueError:
        continue
    if ip.version == 4:
        print(ip)
PY
)

mapfile -t ALLOWED_V6 < <(
  python3 - "${STATE_FILE}" <<'PY'
import ipaddress
import json
import sys

with open(sys.argv[1], "r", encoding="utf-8") as handle:
    data = json.load(handle)

for raw in data.get("allowed_ips", []):
    try:
        ip = ipaddress.ip_address(raw)
    except ValueError:
        continue
    if ip.version == 6:
        print(ip)
PY
)

nft list table inet dtimer_filter >/dev/null 2>&1 && nft delete table inet dtimer_filter
nft list table ip dtimer_nat >/dev/null 2>&1 && nft delete table ip dtimer_nat
nft list table inet dtimer_captive >/dev/null 2>&1 && nft delete table inet dtimer_captive

nft add table inet dtimer_filter
nft add set inet dtimer_filter allowed_v4 '{ type ipv4_addr; flags interval; }'
nft add set inet dtimer_filter allowed_v6 '{ type ipv6_addr; flags interval; }'
nft add table inet dtimer_captive
nft add set inet dtimer_captive allowed_v4 '{ type ipv4_addr; flags interval; }'

if [[ "${#ALLOWED_V4[@]}" -gt 0 ]]; then
  V4_JOINED="$(IFS=, ; echo "${ALLOWED_V4[*]}")"
  nft add element inet dtimer_filter allowed_v4 "{ ${V4_JOINED} }"
  nft add element inet dtimer_captive allowed_v4 "{ ${V4_JOINED} }"
fi

if [[ "${#ALLOWED_V6[@]}" -gt 0 ]]; then
  V6_JOINED="$(IFS=, ; echo "${ALLOWED_V6[*]}")"
  nft add element inet dtimer_filter allowed_v6 "{ ${V6_JOINED} }"
fi

nft add chain inet dtimer_filter forward '{ type filter hook forward priority 0; policy accept; }'
nft add rule inet dtimer_filter forward ct state established,related accept
nft add rule inet dtimer_filter forward iifname "${CUSTOMER_IFACE}" ip saddr @allowed_v4 accept
nft add rule inet dtimer_filter forward iifname "${CUSTOMER_IFACE}" ip6 saddr @allowed_v6 accept
nft add rule inet dtimer_filter forward iifname "${CUSTOMER_IFACE}" drop

nft add table ip dtimer_nat
nft add chain ip dtimer_nat postrouting '{ type nat hook postrouting priority srcnat; policy accept; }'
nft add rule ip dtimer_nat postrouting oifname "${WAN_IFACE}" masquerade

nft add chain inet dtimer_captive prerouting '{ type nat hook prerouting priority dstnat; policy accept; }'
nft add rule inet dtimer_captive prerouting iifname "${CUSTOMER_IFACE}" ip saddr @allowed_v4 return
nft add rule inet dtimer_captive prerouting iifname "${CUSTOMER_IFACE}" tcp dport 80 redirect to ":${DTIMER_PORT:-8080}"

echo "Applied DTimer WiFi network rules for ${#ALLOWED_V4[@]} IPv4 and ${#ALLOWED_V6[@]} IPv6 session(s)."
