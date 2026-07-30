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
- Uploaded portal branding: `/var/lib/dtimer-orange-pi/branding`
- Logs: `/var/log/dtimer-orange-pi`
- Service: `/etc/systemd/system/dtimer-orange-pi.service`

## Portal Branding

The Admin dashboard controls the DTimerFi display name, built-in logo style,
custom logo, and top banner. Custom JPG, PNG, and WebP files are validated and
stored inside the device data directory. Both image controls include a restore
default action.

The customer Account view shows the current IP, detected MAC, session state,
and remaining time. An administrator sets the initial portal passcode, and a
customer who knows the current passcode can replace it. The passcode is hashed
before storage and repeated failures use the same lockout policy as admin
login. This is an application-level portal passcode; it does not rewrite an
external access point or a separate `hostapd` configuration.

## Software Update Discovery

The admin dashboard can check the configured TimerAdmin server and mounted USB
storage for available releases. This is discovery-only: it lists packages and
download links but does not install them automatically.

The package defaults are:

```text
DTIMER_UPDATE_BASE_URL=https://dtimerapp.online
DTIMER_USB_UPDATE_PATHS=/media,/mnt,/run/media
```

Online discovery uses `/api/v1/updates` when the server supports release
history, reads the public `/support` release list on older servers, and finally
falls back to `/api/v1/updates/latest`. For USB discovery, copy packages named
like this anywhere within three folders of a configured mount:

```text
dtimer-orange-pi_1.0.3_all.deb
```

The Orange Pi validates Debian package metadata with `dpkg-deb` before marking
a USB package as verified.

## Security Notes

- No system can be guaranteed impossible to hack.
- This app is hardened by default:
  - PBKDF2 password hashing.
  - Signed `HttpOnly` admin session cookie.
  - CSRF token required for admin mutations.
  - Login lockout after repeated failures.
  - Default password must be changed before setup.
  - `device_secret` is not returned by normal APIs.
  - Portal passcode hashes are not returned by normal APIs.
  - Branding uploads accept only validated JPG, PNG, and WebP content.
  - Firewall enforcement is dry-run until explicitly enabled.
  - systemd runs the app as a dedicated `dtimer` user.

## Network Enforcement

The Svelte UI and Python API decide who has paid time. The Orange Pi Linux firewall enforces internet access.

Recommended USB-to-LAN wiring:

- Built-in Orange Pi LAN port: ISP/router input, often `end0` on current images
- USB-to-LAN adapter: customer/output side to access point or switch, often `enx...`
- Customer portal after customer LAN setup: `http://10.0.0.1:8080/`
- Local admin after customer LAN setup: `http://10.0.0.1:8080/admin`

Automatic detection is enabled by default:

```text
DTIMER_WAN_INTERFACE=auto
DTIMER_CUSTOMER_INTERFACE=auto
```

The setup script follows the default route for WAN and identifies the customer
adapter from its USB device path. If multiple USB network adapters are
connected, edit `/etc/dtimer-orange-pi/dtimer.conf` and set the customer
interface explicitly:

```text
DTIMER_CUSTOMER_INTERFACE=enx00e04c580166
```

If `dnsmasq` fails at boot, check the exact reason:

```bash
systemctl status dnsmasq --no-pager
journalctl -u dnsmasq -b --no-pager | tail -40
ip link
```

The customer-LAN service normally detects renamed interfaces and regenerates
the `dnsmasq` configuration automatically. To rerun detection and restart
`dnsmasq`:

```bash
sudo systemctl restart dtimer-orange-pi-customer-lan
systemctl status dnsmasq --no-pager
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
