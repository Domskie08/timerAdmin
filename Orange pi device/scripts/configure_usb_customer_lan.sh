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

CUSTOMER_IFACE="${DTIMER_CUSTOMER_INTERFACE:-auto}"
CUSTOMER_ADDRESS="${DTIMER_CUSTOMER_ADDRESS:-10.0.0.1/20}"
CUSTOMER_NETMASK="${DTIMER_CUSTOMER_NETMASK:-255.255.240.0}"
DHCP_RANGE_START="${DTIMER_DHCP_RANGE_START:-10.0.0.10}"
DHCP_RANGE_END="${DTIMER_DHCP_RANGE_END:-10.0.15.254}"
DHCP_LEASE_TIME="${DTIMER_DHCP_LEASE_TIME:-12h}"
DTIMER_PORT="${DTIMER_PORT:-8080}"
WAIT_SECONDS="${DTIMER_CUSTOMER_INTERFACE_WAIT_SECONDS:-30}"
SYS_CLASS_NET="${DTIMER_SYS_CLASS_NET:-/sys/class/net}"
RUNTIME_DIR="${DTIMER_RUNTIME_DIR:-/run/dtimer-orange-pi}"
RUNTIME_CONFIG="${RUNTIME_DIR}/network.env"
DNSMASQ_CONF="${DTIMER_DNSMASQ_CONF:-/etc/dnsmasq.d/dtimer-orange-pi.conf}"
SYSCTL_CONF="${DTIMER_SYSCTL_CONF:-/etc/sysctl.d/99-dtimer-orange-pi.conf}"

write_disabled_dnsmasq_config() {
  local dnsmasq_conf="$1"

  mkdir -p "$(dirname "${dnsmasq_conf}")"
  cat > "${dnsmasq_conf}" <<EOF
# DTimer WiFi customer/output network is waiting for a USB-to-LAN adapter.
# Keep dnsmasq from binding DNS on every interface while no customer interface
# is available.
port=0
EOF
  chmod 0644 "${dnsmasq_conf}"
}

restart_dnsmasq() {
  if ! dnsmasq --test; then
    echo "dnsmasq configuration validation failed; the service was not restarted." >&2
    return 1
  fi

  if command -v systemctl >/dev/null 2>&1 && ! systemctl restart dnsmasq; then
    echo "dnsmasq failed to restart. Check: journalctl -u dnsmasq -b --no-pager -n 80" >&2
    return 1
  fi
}

detect_customer_iface() {
  local configured_iface="$1"

  if [[ -n "${configured_iface}" && "${configured_iface}" != "auto" ]] &&
    ip link show "${configured_iface}" >/dev/null 2>&1; then
    echo "${configured_iface}"
    return 0
  fi

  local candidates=()
  local active_candidates=()
  local device_real_path
  local iface
  for device_path in "${SYS_CLASS_NET}"/*; do
    [[ -e "${device_path}" ]] || continue
    iface="$(basename "${device_path}")"
    [[ "${iface}" == "lo" ]] && continue
    [[ "${iface}" == docker* || "${iface}" == br-* || "${iface}" == veth* ]] && continue

    device_real_path="$(readlink -f "${device_path}/device" 2>/dev/null || true)"
    [[ "${device_real_path}" == *"/usb"* ]] || continue

    candidates+=("${iface}")
    if [[ -r "${device_path}/carrier" ]] && [[ "$(<"${device_path}/carrier")" == "1" ]]; then
      active_candidates+=("${iface}")
    fi
  done

  if [[ "${#candidates[@]}" -eq 1 ]]; then
    echo "${candidates[0]}"
    return 0
  fi

  if [[ "${#active_candidates[@]}" -eq 1 ]]; then
    echo "${active_candidates[0]}"
    return 0
  fi

  echo ""
  return 1
}

detect_wan_iface() {
  local customer_iface="$1"
  local configured_iface="${DTIMER_WAN_INTERFACE:-auto}"
  local default_iface

  if [[ -n "${configured_iface}" && "${configured_iface}" != "auto" &&
    "${configured_iface}" != "${customer_iface}" ]] &&
    ip link show "${configured_iface}" >/dev/null 2>&1; then
    echo "${configured_iface}"
    return 0
  fi

  default_iface="$(ip route show default 2>/dev/null | awk 'NR == 1 { for (i = 1; i <= NF; i++) if ($i == "dev") { print $(i + 1); exit } }')"
  if [[ -n "${default_iface}" && "${default_iface}" != "${customer_iface}" ]]; then
    echo "${default_iface}"
    return 0
  fi

  local candidates=()
  local device_real_path
  local iface
  for device_path in "${SYS_CLASS_NET}"/*; do
    [[ -e "${device_path}" ]] || continue
    iface="$(basename "${device_path}")"
    [[ "${iface}" == "lo" || "${iface}" == "${customer_iface}" ]] && continue
    [[ "${iface}" == docker* || "${iface}" == br-* || "${iface}" == veth* ]] && continue

    device_real_path="$(readlink -f "${device_path}/device" 2>/dev/null || true)"
    [[ -n "${device_real_path}" && "${device_real_path}" != *"/usb"* ]] || continue
    candidates+=("${iface}")
  done

  if [[ "${#candidates[@]}" -eq 1 ]]; then
    echo "${candidates[0]}"
    return 0
  fi

  echo ""
  return 1
}

address_overlaps_another_iface() {
  local customer_iface="$1"
  local customer_address="$2"
  local existing_address
  local existing_iface

  while read -r existing_iface existing_address; do
    [[ -n "${existing_iface}" && -n "${existing_address}" ]] || continue
    [[ "${existing_iface}" == "${customer_iface}" ]] && continue

    if python3 - "${customer_address}" "${existing_address}" <<'PY'
import ipaddress
import sys

customer = ipaddress.ip_interface(sys.argv[1]).network
existing = ipaddress.ip_interface(sys.argv[2]).network
raise SystemExit(0 if customer.overlaps(existing) else 1)
PY
    then
      echo "${existing_iface} (${existing_address})"
      return 0
    fi
  done < <(ip -o -4 addr show | awk '{print $2, $4}')

  return 1
}

write_runtime_config() {
  local customer_iface="$1"
  local wan_iface="$2"
  local temp_config

  mkdir -p "${RUNTIME_DIR}"
  temp_config="$(mktemp "${RUNTIME_CONFIG}.XXXXXX")"
  {
    printf 'DTIMER_DETECTED_CUSTOMER_INTERFACE=%s\n' "${customer_iface}"
    printf 'DTIMER_DETECTED_WAN_INTERFACE=%s\n' "${wan_iface}"
  } > "${temp_config}"
  chmod 0644 "${temp_config}"
  mv -f "${temp_config}" "${RUNTIME_CONFIG}"
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

if ! command -v python3 >/dev/null 2>&1; then
  echo "python3 was not found." >&2
  exit 5
fi

if [[ ! "${WAIT_SECONDS}" =~ ^[0-9]+$ ]]; then
  echo "DTIMER_CUSTOMER_INTERFACE_WAIT_SECONDS must be a non-negative integer." >&2
  exit 6
fi

DETECTED_CUSTOMER_IFACE=""
for ((attempt = 0; attempt <= WAIT_SECONDS; attempt++)); do
  DETECTED_CUSTOMER_IFACE="$(detect_customer_iface "${CUSTOMER_IFACE}")"
  [[ -n "${DETECTED_CUSTOMER_IFACE}" ]] && break
  [[ "${attempt}" -lt "${WAIT_SECONDS}" ]] && sleep 1
done

if [[ -z "${DETECTED_CUSTOMER_IFACE}" ]]; then
  echo "A unique USB-to-LAN customer interface could not be detected." >&2
  echo "Run: ip -br link" >&2
  echo "For multiple USB-LAN adapters, set DTIMER_CUSTOMER_INTERFACE in ${CONFIG_FILE}." >&2
  write_disabled_dnsmasq_config "${DNSMASQ_CONF}"
  restart_dnsmasq
  exit 0
fi
if [[ "${DETECTED_CUSTOMER_IFACE}" != "${CUSTOMER_IFACE}" && "${CUSTOMER_IFACE}" != "auto" ]]; then
  echo "Customer interface ${CUSTOMER_IFACE} was not found. Using USB interface ${DETECTED_CUSTOMER_IFACE}."
fi
CUSTOMER_IFACE="${DETECTED_CUSTOMER_IFACE}"

if [[ ! "${CUSTOMER_IFACE}" =~ ^[[:alnum:]_.:-]{1,15}$ ]]; then
  echo "Detected an invalid customer interface name: ${CUSTOMER_IFACE}" >&2
  exit 7
fi

WAN_IFACE="$(detect_wan_iface "${CUSTOMER_IFACE}")"
if [[ -z "${WAN_IFACE}" ]]; then
  echo "WAN interface could not be detected. Customer DHCP will be configured without internet forwarding." >&2
elif [[ ! "${WAN_IFACE}" =~ ^[[:alnum:]_.:-]{1,15}$ ]]; then
  echo "Detected an invalid WAN interface name: ${WAN_IFACE}" >&2
  exit 8
fi

CONFLICT="$(address_overlaps_another_iface "${CUSTOMER_IFACE}" "${CUSTOMER_ADDRESS}" || true)"
if [[ -n "${CONFLICT}" ]]; then
  echo "Customer subnet ${CUSTOMER_ADDRESS} overlaps another interface: ${CONFLICT}." >&2
  echo "Set DTIMER_CUSTOMER_ADDRESS and its DHCP range to a different subnet." >&2
  exit 9
fi

CUSTOMER_GATEWAY="${CUSTOMER_ADDRESS%/*}"

ip link set "${CUSTOMER_IFACE}" up
ip addr replace "${CUSTOMER_ADDRESS}" dev "${CUSTOMER_IFACE}"
write_runtime_config "${CUSTOMER_IFACE}" "${WAN_IFACE}"

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

restart_dnsmasq

echo "Configured ${CUSTOMER_IFACE} as customer LAN ${CUSTOMER_ADDRESS}."
[[ -n "${WAN_IFACE}" ]] && echo "Detected ${WAN_IFACE} as the WAN interface."
echo "Customer portal: http://${CUSTOMER_GATEWAY}:${DTIMER_PORT}/"
echo "Admin panel:     http://${CUSTOMER_GATEWAY}:${DTIMER_PORT}/admin"
echo "Captive portal trigger is active for unpaid HTTP clients."
