#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT="${ROOT_DIR}/scripts/configure_usb_customer_lan.sh"
FIREWALL_SCRIPT="${ROOT_DIR}/scripts/apply_network_rules.sh"
TEST_DIR="$(mktemp -d "${TMPDIR:-/tmp}/dtimer-network-test.XXXXXX")"

cleanup() {
  if [[ -n "${TEST_DIR}" && -d "${TEST_DIR}" && "${TEST_DIR}" == *"dtimer-network-test."* ]]; then
    rm -rf -- "${TEST_DIR}"
  fi
}
trap cleanup EXIT

FAKE_BIN="${TEST_DIR}/bin"
SYS_CLASS_NET="${TEST_DIR}/sys/class/net"
COMMAND_LOG="${TEST_DIR}/commands.log"
DNSMASQ_CONF="${TEST_DIR}/etc/dnsmasq.d/dtimer-orange-pi.conf"
SYSCTL_CONF="${TEST_DIR}/etc/sysctl.d/99-dtimer-orange-pi.conf"
RUNTIME_DIR="${TEST_DIR}/run/dtimer-orange-pi"

mkdir -p "${FAKE_BIN}" "${SYS_CLASS_NET}/end0/device" \
  "${SYS_CLASS_NET}/enx00e04c580166/device"
printf '1\n' > "${SYS_CLASS_NET}/end0/carrier"
printf '1\n' > "${SYS_CLASS_NET}/enx00e04c580166/carrier"

cat > "${FAKE_BIN}/id" <<'EOF'
#!/usr/bin/env bash
echo 0
EOF

cat > "${FAKE_BIN}/readlink" <<'EOF'
#!/usr/bin/env bash
case "${2:-}" in
  *enx00e04c580166/device)
    echo "/devices/platform/soc/usb2/2-1/2-1.2/net/enx00e04c580166"
    ;;
  *end0/device)
    echo "/devices/platform/soc/1c30000.ethernet/net/end0"
    ;;
  *)
    exit 1
    ;;
esac
EOF

cat > "${FAKE_BIN}/ip" <<'EOF'
#!/usr/bin/env bash
case "$*" in
  "link show eth0"|"link show eth1")
    exit 1
    ;;
  "link show end0"|"link show enx00e04c580166")
    exit 0
    ;;
  "route show default")
    echo "default via 192.168.1.1 dev end0"
    ;;
  "-o -4 addr show")
    echo "2: end0 inet 192.168.1.20/24 brd 192.168.1.255 scope global end0"
    ;;
  "link set enx00e04c580166 up"|"addr replace 10.0.0.1/20 dev enx00e04c580166")
    printf 'ip %s\n' "$*" >> "${DTIMER_TEST_COMMAND_LOG}"
    ;;
  *)
    printf 'Unexpected ip command: %s\n' "$*" >&2
    exit 1
    ;;
esac
EOF

cat > "${FAKE_BIN}/python3" <<'EOF'
#!/usr/bin/env bash
# The fixture's 192.168.1.0/24 WAN does not overlap 10.0.0.0/20.
exit 1
EOF

cat > "${FAKE_BIN}/dnsmasq" <<'EOF'
#!/usr/bin/env bash
[[ "${1:-}" == "--test" ]]
EOF

cat > "${FAKE_BIN}/nft" <<'EOF'
#!/usr/bin/env bash
if [[ "${1:-}" == "list" ]]; then
  exit 1
fi
printf 'nft %s\n' "$*" >> "${DTIMER_TEST_COMMAND_LOG}"
EOF

cat > "${FAKE_BIN}/systemctl" <<'EOF'
#!/usr/bin/env bash
printf 'systemctl %s\n' "$*" >> "${DTIMER_TEST_COMMAND_LOG}"
EOF

cat > "${FAKE_BIN}/sysctl" <<'EOF'
#!/usr/bin/env bash
printf 'sysctl %s\n' "$*" >> "${DTIMER_TEST_COMMAND_LOG}"
EOF

chmod +x "${FAKE_BIN}"/*

OUTPUT="$(
  PATH="${FAKE_BIN}:${PATH}" \
  DTIMER_CONFIG_FILE="${TEST_DIR}/missing.conf" \
  DTIMER_CUSTOMER_INTERFACE=eth1 \
  DTIMER_WAN_INTERFACE=eth0 \
  DTIMER_CUSTOMER_INTERFACE_WAIT_SECONDS=0 \
  DTIMER_SYS_CLASS_NET="${SYS_CLASS_NET}" \
  DTIMER_RUNTIME_DIR="${RUNTIME_DIR}" \
  DTIMER_DNSMASQ_CONF="${DNSMASQ_CONF}" \
  DTIMER_SYSCTL_CONF="${SYSCTL_CONF}" \
  DTIMER_TEST_COMMAND_LOG="${COMMAND_LOG}" \
  bash "${SCRIPT}"
)"

grep -Fxq "interface=enx00e04c580166" "${DNSMASQ_CONF}"
grep -Fxq "listen-address=10.0.0.1" "${DNSMASQ_CONF}"
grep -Fxq "bind-dynamic" "${DNSMASQ_CONF}"
grep -Fxq "DTIMER_DETECTED_CUSTOMER_INTERFACE=enx00e04c580166" "${RUNTIME_DIR}/network.env"
grep -Fxq "DTIMER_DETECTED_WAN_INTERFACE=end0" "${RUNTIME_DIR}/network.env"
grep -Fxq "ip addr replace 10.0.0.1/20 dev enx00e04c580166" "${COMMAND_LOG}"
grep -Fxq "systemctl restart dnsmasq" "${COMMAND_LOG}"
grep -Fq "Configured enx00e04c580166 as customer LAN 10.0.0.1/20." <<< "${OUTPUT}"

STATE_FILE="${TEST_DIR}/active-sessions.json"
printf '{"allowed_ips":[]}\n' > "${STATE_FILE}"

PATH="${FAKE_BIN}:${PATH}" \
DTIMER_CUSTOMER_INTERFACE=eth1 \
DTIMER_WAN_INTERFACE=eth0 \
DTIMER_RUNTIME_NETWORK_CONFIG="${RUNTIME_DIR}/network.env" \
DTIMER_TEST_COMMAND_LOG="${COMMAND_LOG}" \
bash "${FIREWALL_SCRIPT}" "${STATE_FILE}" >/dev/null

grep -Fq "iifname enx00e04c580166" "${COMMAND_LOG}"
grep -Fq "oifname end0 masquerade" "${COMMAND_LOG}"

echo "USB customer-LAN auto-detection test passed."
