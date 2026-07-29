#!/usr/bin/env bash
set -euo pipefail

CONFIG_FILE="${DTIMER_CONFIG_FILE:-/etc/dtimer-orange-pi/dtimer.conf}"
if [[ -f "${CONFIG_FILE}" ]]; then
  # shellcheck disable=SC1090
  source "${CONFIG_FILE}"
fi

if [[ "${DTIMER_CONFIGURE_CUSTOMER_LAN:-1}" != "1" ]]; then
  echo "DTimer customer LAN setup is disabled."
  exit 0
fi

CUSTOMER_IFACE="${DTIMER_CUSTOMER_INTERFACE:-eth1}"
CUSTOMER_ADDRESS="${DTIMER_CUSTOMER_ADDRESS:-10.0.0.1/20}"
CUSTOMER_NETMASK="${DTIMER_CUSTOMER_NETMASK:-255.255.240.0}"
DHCP_RANGE_START="${DTIMER_DHCP_RANGE_START:-10.0.0.10}"
DHCP_RANGE_END="${DTIMER_DHCP_RANGE_END:-10.0.15.254}"
DHCP_LEASE_TIME="${DTIMER_DHCP_LEASE_TIME:-12h}"
DTIMER_PORT="${DTIMER_PORT:-8080}"
WAIT_SECONDS="${DTIMER_CUSTOMER_INTERFACE_WAIT_SECONDS:-30}"

write_disabled_dnsmasq_config() {
  local dnsmasq_conf="$1"

  mkdir -p "$(dirname "${dnsmasq_conf}")"
  cat > "${dnsmasq_conf}" <<EOF
# DTimer WiFi customer/output network is waiting for a USB-to-LAN adapter.
# Keep dnsmasq from binding DNS on every interface while no customer interface
# is available.
port=0
EOF
}

detect_customer_iface() {
  local configured_iface="$1"
  local wan_iface="${DTIMER_WAN_INTERFACE:-}"

  if ip link show "${configured_iface}" >/dev/null 2>&1; then
    echo "${configured_iface}"
    return 0
  fi

  if [[ -z "${wan_iface}" ]]; then
    wan_iface="$(ip route show default 2>/dev/null | awk 'NR == 1 { for (i = 1; i <= NF; i++) if ($i == "dev") { print $(i + 1); exit } }')"
  fi

  local candidates=()
  local iface
  for device_path in /sys/class/net/*; do
    iface="$(basename "${device_path}")"
    [[ "${iface}" == "lo" || "${iface}" == "${wan_iface}" ]] && continue
    [[ "${iface}" == docker* || "${iface}" == br-* || "${iface}" == veth* ]] && continue
    candidates+=("${iface}")
  done

  if [[ "${#candidates[@]}" -eq 1 ]]; then
    echo "${candidates[0]}"
    return 0
  fi

  echo ""
  return 1
}

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this script with sudo." >&2
  exit 1
fi

if ! command -v ip >/dev/null 2>&1; then
  echo "ip command was not found. Install iproute2." >&2
  exit 2
fi

if ! command -v dnsmasq >/dev/null 2>&1; then
  echo "dnsmasq command was not found. Install dnsmasq." >&2
  exit 3
fi

if ! command -v nft >/dev/null 2>&1; then
  echo "nft command was not found. Install nftables." >&2
  exit 4
fi

DETECTED_CUSTOMER_IFACE=""
for _ in $(seq 0 "${WAIT_SECONDS}"); do
  DETECTED_CUSTOMER_IFACE="$(detect_customer_iface "${CUSTOMER_IFACE}")"
  [[ -n "${DETECTED_CUSTOMER_IFACE}" ]] && break
  sleep 1
done

if [[ -z "${DETECTED_CUSTOMER_IFACE}" ]]; then
  echo "Customer interface ${CUSTOMER_IFACE} was not found and could not be auto-detected." >&2
  echo "Run: ip link" >&2
  echo "Then update DTIMER_CUSTOMER_INTERFACE in ${CONFIG_FILE}." >&2
  write_disabled_dnsmasq_config "/etc/dnsmasq.d/dtimer-orange-pi.conf"
  exit 0
fi
if [[ "${DETECTED_CUSTOMER_IFACE}" != "${CUSTOMER_IFACE}" ]]; then
  echo "Customer interface ${CUSTOMER_IFACE} was not found. Using detected interface ${DETECTED_CUSTOMER_IFACE}."
  CUSTOMER_IFACE="${DETECTED_CUSTOMER_IFACE}"
fi

CUSTOMER_GATEWAY="${CUSTOMER_ADDRESS%/*}"
DNSMASQ_CONF="/etc/dnsmasq.d/dtimer-orange-pi.conf"
SYSCTL_CONF="/etc/sysctl.d/99-dtimer-orange-pi.conf"

ip link set "${CUSTOMER_IFACE}" up
ip addr replace "${CUSTOMER_ADDRESS}" dev "${CUSTOMER_IFACE}"

mkdir -p "$(dirname "${DNSMASQ_CONF}")" "$(dirname "${SYSCTL_CONF}")"

cat > "${DNSMASQ_CONF}" <<EOF
# DTimer WiFi customer/output network.
# ${CUSTOMER_IFACE} should be the USB-to-LAN adapter connected to the AP/switch.
interface=${CUSTOMER_IFACE}
except-interface=lo
listen-address=${CUSTOMER_GATEWAY}
bind-dynamic
dhcp-range=${DHCP_RANGE_START},${DHCP_RANGE_END},${CUSTOMER_NETMASK},${DHCP_LEASE_TIME}
dhcp-option=3,${CUSTOMER_GATEWAY}
dhcp-option=6,${CUSTOMER_GATEWAY}
domain-needed
bogus-priv
address=/www.msftconnecttest.com/${CUSTOMER_GATEWAY}
address=/www.msftncsi.com/${CUSTOMER_GATEWAY}
address=/connectivitycheck.gstatic.com/${CUSTOMER_GATEWAY}
address=/clients3.google.com/${CUSTOMER_GATEWAY}
address=/captive.apple.com/${CUSTOMER_GATEWAY}
EOF

cat > "${SYSCTL_CONF}" <<EOF
net.ipv4.ip_forward=1
EOF
sysctl -w net.ipv4.ip_forward=1 >/dev/null

nft list table inet dtimer_captive >/dev/null 2>&1 && nft delete table inet dtimer_captive
nft add table inet dtimer_captive
nft add set inet dtimer_captive allowed_v4 '{ type ipv4_addr; flags interval; }'
nft add chain inet dtimer_captive prerouting '{ type nat hook prerouting priority dstnat; policy accept; }'
nft add rule inet dtimer_captive prerouting iifname "${CUSTOMER_IFACE}" ip saddr @allowed_v4 return
nft add rule inet dtimer_captive prerouting iifname "${CUSTOMER_IFACE}" tcp dport 80 redirect to ":${DTIMER_PORT}"

if command -v systemctl >/dev/null 2>&1; then
  systemctl restart dnsmasq
fi

echo "Configured ${CUSTOMER_IFACE} as customer LAN ${CUSTOMER_ADDRESS}."
echo "Customer portal: http://${CUSTOMER_GATEWAY}:${DTIMER_PORT}/"
echo "Admin panel:     http://${CUSTOMER_GATEWAY}:${DTIMER_PORT}/admin"
echo "Captive portal trigger is active for unpaid HTTP clients."
