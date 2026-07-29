# DTimer WiFi Orange Pi Device

This folder contains the shop-local DTimer WiFi controller for Orange Pi devices. It is designed for Debian/Ubuntu Server images without a desktop environment.

## What It Does

- Serves a Svelte customer portal and local admin dashboard.
- Stores paid internet sessions, coin sales, and sync queue data in SQLite.
- Works offline and syncs to TimerAdmin when internet returns.
- Uses Linux firewall hooks for real internet allow/block enforcement.
- Creates the first setup super admin automatically:
  - username: `admin`
  - password: `admin`

The default password is intentionally marked as unsafe. The admin must change it before settings or network enforcement can be enabled.

## Development

Run backend only:

```bash
python app.py --host 127.0.0.1 --port 8080
```

Run frontend dev server:

```bash
npm install
npm run dev
```

Build static frontend:

```bash
npm run build
```

Run Python tests from the repository root:

```bash
python -m unittest discover -s "Orange pi device/tests"
```

## Debian Package

Build the `.deb` on a Debian/Ubuntu machine with `nodejs`, `npm`, and `dpkg-deb` installed:

```bash
cd "Orange pi device"
bash scripts/build_deb.sh
```

Install on the Orange Pi:

```bash
sudo apt install ./dist/dtimer-orange-pi_1.0.0_all.deb
```

After install:

```bash
systemctl status dtimer-orange-pi
```

Open:

- Customer portal: `http://orange-pi-ip:8080/`
- Local admin: `http://orange-pi-ip:8080/admin`

## Installed Paths

- App code: `/opt/dtimer-orange-pi`
- Config: `/etc/dtimer-orange-pi/dtimer.conf`
- Data/database: `/var/lib/dtimer-orange-pi`
- Logs: `/var/log/dtimer-orange-pi`
- Service: `/etc/systemd/system/dtimer-orange-pi.service`

## Security Notes

- No system can be guaranteed impossible to hack.
- This app is hardened by default:
  - PBKDF2 password hashing.
  - Signed `HttpOnly` admin session cookie.
  - CSRF token required for admin mutations.
  - Login lockout after repeated failures.
  - Default password must be changed before setup.
  - `device_secret` is not returned by normal APIs.
  - Firewall enforcement is dry-run until explicitly enabled.
  - systemd runs the app as a dedicated `dtimer` user.

## Network Enforcement

The Svelte UI and Python API decide who has paid time. The Orange Pi Linux firewall enforces internet access.

Recommended USB-to-LAN wiring:

- Built-in Orange Pi LAN port: ISP/router input, usually `eth0`
- USB-to-LAN adapter: customer/output side to access point or switch, usually `eth1`
- Customer portal after customer LAN setup: `http://10.0.0.1:8080/`
- Local admin after customer LAN setup: `http://10.0.0.1:8080/admin`

Check interface names on the Orange Pi:

```bash
ip link
```

If the USB-to-LAN adapter is not `eth1`, edit `/etc/dtimer-orange-pi/dtimer.conf` and replace `eth1` with the actual USB adapter name:

```text
DTIMER_WAN_INTERFACE=eth0
DTIMER_CUSTOMER_INTERFACE=eth1
```

If `dnsmasq` fails at boot, check the exact reason:

```bash
systemctl status dnsmasq --no-pager
journalctl -u dnsmasq -b --no-pager | tail -40
ip link
```

Most failures mean the USB-to-LAN adapter name is different from `eth1`. Update `DTIMER_CUSTOMER_INTERFACE`, then restart:

```bash
sudo systemctl restart dtimer-orange-pi-customer-lan
sudo systemctl restart dnsmasq
```

To configure the USB-to-LAN side with `10.0.0.1/20` and DHCP:

```bash
sudo /opt/dtimer-orange-pi/scripts/configure_usb_customer_lan.sh
```

The package also installs `dtimer-orange-pi-customer-lan.service`, which runs this setup on boot when `DTIMER_CONFIGURE_CUSTOMER_LAN=1`.
It also redirects unpaid customer HTTP traffic on port 80 to the local portal, which helps Windows, Android, and iOS show a sign-in portal notification.

The firewall helper reads:

```text
/var/lib/dtimer-orange-pi/active-sessions.json
```

By default, package config keeps enforcement off:

```text
DTIMER_ENFORCE_NETWORK=0
```

Real enforcement should only be enabled after the Orange Pi WAN/customer interfaces, license key, device secret, and MAC address are configured.
