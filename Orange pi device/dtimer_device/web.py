from __future__ import annotations

import base64
import binascii
import ipaddress
import json
import mimetypes
import os
from http import HTTPStatus
from http.cookies import SimpleCookie
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any
from urllib.parse import unquote, urlparse

from .config import DeviceConfig
from .firewall import FirewallController
from .network import client_ip_from_headers, resolve_client_mac
from .security import csrf_token_for_session, sign_session, verify_csrf_token, verify_session
from .store import DeviceStore
from .sync import TimerAdminSync
from .updates import DEFAULT_UPDATE_BASE_URL, UpdateChecker, parse_usb_roots
from .validators import (
    ValidationError,
    require_string,
    validate_int,
    validate_ip,
    validate_mac,
    validate_portal_passcode,
    validate_settings,
)


SESSION_COOKIE = "dtimer_admin_session"
MAX_JSON_BYTES = 64 * 1024
MAX_BRANDING_JSON_BYTES = 5 * 1024 * 1024
BRANDING_IMAGE_LIMITS = {"logo": 1024 * 1024, "banner": 3 * 1024 * 1024}
BRANDING_IMAGE_TYPES = {
    "image/jpeg": "jpg",
    "image/png": "png",
    "image/webp": "webp",
}
CAPTIVE_PORTAL_PATHS = {
    "/connecttest.txt",
    "/ncsi.txt",
    "/generate_204",
    "/gen_204",
    "/hotspot-detect.html",
    "/library/test/success.html",
    "/success.txt",
}


def decode_branding_image(image_data: object, kind: str) -> tuple[bytes, str]:
    value = require_string(image_data, "Image", max_length=MAX_BRANDING_JSON_BYTES)
    try:
        header, encoded = value.split(",", 1)
        mime_type = header.removeprefix("data:").removesuffix(";base64")
        extension = BRANDING_IMAGE_TYPES[mime_type]
        content = base64.b64decode(encoded, validate=True)
    except (ValueError, KeyError, binascii.Error):
        raise ValidationError("Use a valid JPG, PNG, or WebP image.") from None

    if len(content) > BRANDING_IMAGE_LIMITS[kind]:
        limit_mb = BRANDING_IMAGE_LIMITS[kind] // (1024 * 1024)
        raise ValidationError(f"The {kind} image must be {limit_mb} MB or smaller.")

    valid_signature = (
        (extension == "jpg" and content.startswith(b"\xff\xd8\xff"))
        or (extension == "png" and content.startswith(b"\x89PNG\r\n\x1a\n"))
        or (extension == "webp" and len(content) >= 12 and content.startswith(b"RIFF") and content[8:12] == b"WEBP")
    )
    if not valid_signature:
        raise ValidationError("The uploaded image content does not match its file type.")

    return content, extension


def serve(config: DeviceConfig, store: DeviceStore, host: str, port: int) -> None:
    handler = make_handler(config, store)
    server = ThreadingHTTPServer((host, port), handler)
    server.serve_forever()


def make_handler(
    config: DeviceConfig,
    store: DeviceStore,
    update_checker: UpdateChecker | None = None,
) -> type[BaseHTTPRequestHandler]:
    firewall = FirewallController(config, store)
    syncer = TimerAdminSync(store)
    checker = update_checker or UpdateChecker(
        base_url=os.getenv("DTIMER_UPDATE_BASE_URL", DEFAULT_UPDATE_BASE_URL),
        usb_roots=parse_usb_roots(os.getenv("DTIMER_USB_UPDATE_PATHS")),
        fallback_version=os.getenv("DTIMER_APP_VERSION", store.get_setting("app_version", "")),
    )

    class DTimerRequestHandler(BaseHTTPRequestHandler):
        server_version = "DTimerOrangePi/0.1"

        def log_message(self, fmt: str, *args: object) -> None:
            print("%s - - [%s] %s" % (self.address_string(), self.log_date_time_string(), fmt % args))

        def do_GET(self) -> None:
            path = urlparse(self.path).path
            if path in CAPTIVE_PORTAL_PATHS:
                self.redirect_to_portal()
                return

            if path == "/api/status":
                self.handle_public_status()
                return

            if path == "/api/admin/status":
                admin = self.require_admin()
                if admin:
                    cookie = self.get_cookie(SESSION_COOKIE) or ""
                    self.send_json(
                        {
                            "admin": admin,
                            "csrfToken": csrf_token_for_session(cookie, self.session_secret()),
                            "settings": store.settings(),
                            "branding": self.branding_payload(),
                            "stats": store.stats(),
                            "sessions": [session.to_dict() for session in store.recent_sessions()],
                        }
                    )
                return

            if path == "/api/admin/updates":
                admin = self.require_admin()
                if admin:
                    self.send_json(checker.check())
                return

            if path.startswith("/branding/"):
                self.serve_branding_asset(path)
                return

            if path.startswith("/assets/") or path in {"/favicon.ico", "/vite.svg"}:
                self.serve_static_asset(path)
                return

            self.serve_spa()

        def do_POST(self) -> None:
            path = urlparse(self.path).path
            try:
                if path == "/api/login":
                    self.handle_login()
                    return

                if path == "/api/account/passcode":
                    self.handle_portal_passcode_change()
                    return

                if path == "/api/logout":
                    admin = self.require_admin()
                    if admin and self.require_csrf():
                        self.send_response(HTTPStatus.OK)
                        self.send_header("Set-Cookie", self.expired_session_cookie())
                        self.send_header("Content-Type", "application/json")
                        self.end_headers()
                        self.wfile.write(b'{"ok":true}')
                    return

                if path == "/api/admin/password":
                    admin = self.require_admin()
                    if admin and self.require_csrf():
                        self.handle_password_change(admin)
                    return

                if path == "/api/coin/pulse":
                    self.handle_coin_pulse()
                    return

                admin = self.require_admin()
                if not admin or not self.require_csrf():
                    return

                if admin.get("must_change_password"):
                    self.send_json(
                        {"ok": False, "message": "Change the default admin password before changing settings."},
                        HTTPStatus.PRECONDITION_REQUIRED,
                    )
                    return

                if path == "/api/admin/settings":
                    self.handle_settings_update()
                    return

                if path == "/api/admin/portal-passcode":
                    self.handle_admin_portal_passcode()
                    return

                if path == "/api/admin/branding":
                    self.handle_branding_update()
                    return

                if path == "/api/sessions":
                    self.handle_session_create()
                    return

                if path.startswith("/api/sessions/"):
                    self.handle_session_action(path)
                    return

                if path == "/api/sync":
                    self.send_json(syncer.run_once())
                    return

                if path == "/api/firewall/reconcile":
                    self.send_json({"ok": True, "firewall": firewall.reconcile(store.active_sessions())})
                    return

                self.send_json({"ok": False, "message": "Route not found."}, HTTPStatus.NOT_FOUND)
            except ValidationError as exc:
                self.send_json({"ok": False, "message": str(exc)}, HTTPStatus.UNPROCESSABLE_ENTITY)

        def handle_public_status(self) -> None:
            client_ip = client_ip_from_headers(self.client_address, self.headers)
            session = store.find_active_session_for_ip(client_ip)
            self.send_json(
                {
                    "device": store.get_setting("device_name", "DTimer Orange Pi"),
                    "clientIp": client_ip,
                    "clientMac": resolve_client_mac(client_ip),
                    "activeSession": session.to_dict() if session else None,
                    "stats": store.stats(),
                    "branding": self.branding_payload(),
                    "account": {
                        "passcodeConfigured": store.portal_passcode_configured(),
                    },
                    "rates": {
                        "minutesPerCoin": int(store.get_setting("rate_minutes_per_coin", "5") or 5),
                        "coinAmountMinor": int(store.get_setting("coin_amount_minor", "500") or 500),
                        "currency": store.get_setting("currency", "PHP"),
                    },
                }
            )

        def handle_login(self) -> None:
            client_ip = client_ip_from_headers(self.client_address, self.headers)
            payload = self.read_json()
            username = require_string(payload.get("username"), "Username", max_length=80)
            password = require_string(payload.get("password"), "Password", max_length=128)
            identity = f"{client_ip}|{username}"
            locked_until = store.login_locked_until(identity)

            if locked_until:
                self.send_json(
                    {"ok": False, "message": "Too many failed logins. Try again later.", "lockedUntil": locked_until},
                    HTTPStatus.TOO_MANY_REQUESTS,
                )
                return

            admin = store.verify_admin(username, password)
            if not admin:
                store.record_login_failure(identity)
                self.send_json({"ok": False, "message": "Invalid username or password."}, HTTPStatus.UNAUTHORIZED)
                return

            store.clear_login_failures(identity)
            cookie_value = sign_session(admin["username"], self.session_secret())
            csrf = csrf_token_for_session(cookie_value, self.session_secret())
            self.send_response(HTTPStatus.OK)
            self.send_header("Set-Cookie", self.session_cookie(cookie_value))
            self.send_header("Content-Type", "application/json")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.end_headers()
            self.wfile.write(json.dumps({"ok": True, "admin": admin, "csrfToken": csrf}).encode("utf-8"))

        def handle_password_change(self, admin: dict[str, Any]) -> None:
            payload = self.read_json()
            current_password = require_string(payload.get("currentPassword"), "Current password", max_length=128)
            new_password = require_string(payload.get("newPassword"), "New password", max_length=128)
            ok, message = store.change_admin_password(admin["username"], current_password, new_password)
            if not ok:
                self.send_json({"ok": False, "message": message}, HTTPStatus.UNPROCESSABLE_ENTITY)
                return

            self.send_json({"ok": True, "message": message, "admin": store.admin_public(admin["username"])})

        def handle_settings_update(self) -> None:
            payload = self.read_json()
            validated = validate_settings(payload)
            current = store.settings(include_secret=True)
            enabling_network = validated.get("network_enforcement_enabled") == "1"
            unsafe_secret = not validated.get("device_secret") and not current.get("device_secret")
            unsafe_license = not validated.get("license_key") and not current.get("license_key")
            unsafe_mac = not validated.get("mac_address") and not current.get("mac_address")

            if enabling_network and (unsafe_secret or unsafe_license or unsafe_mac):
                self.send_json(
                    {
                        "ok": False,
                        "message": "Configure license key, device secret, and MAC address before enabling network enforcement.",
                    },
                    HTTPStatus.UNPROCESSABLE_ENTITY,
                )
                return

            store.update_settings(validated)
            self.send_json({"ok": True, "settings": store.settings()})

        def handle_admin_portal_passcode(self) -> None:
            payload = self.read_json()
            passcode = validate_portal_passcode(payload.get("newPasscode"), "New passcode")
            store.set_portal_passcode(passcode)
            self.send_json(
                {
                    "ok": True,
                    "message": "Portal passcode updated.",
                    "settings": store.settings(),
                }
            )

        def handle_portal_passcode_change(self) -> None:
            client_ip = client_ip_from_headers(self.client_address, self.headers)
            identity = f"portal-passcode|{client_ip}"
            locked_until = store.login_locked_until(identity)
            if locked_until:
                self.send_json(
                    {"ok": False, "message": "Too many attempts. Try again later.", "lockedUntil": locked_until},
                    HTTPStatus.TOO_MANY_REQUESTS,
                )
                return

            if not store.portal_passcode_configured():
                self.send_json(
                    {"ok": False, "message": "The portal passcode has not been configured by the administrator."},
                    HTTPStatus.CONFLICT,
                )
                return

            payload = self.read_json()
            current_passcode = require_string(payload.get("currentPasscode"), "Current passcode", max_length=63)
            new_passcode = validate_portal_passcode(payload.get("newPasscode"), "New passcode")
            if not store.verify_portal_passcode(current_passcode):
                store.record_login_failure(identity)
                self.send_json({"ok": False, "message": "Current passcode is incorrect."}, HTTPStatus.UNAUTHORIZED)
                return

            store.clear_login_failures(identity)
            store.set_portal_passcode(new_passcode)
            self.send_json({"ok": True, "message": "Your portal passcode was changed."})

        def handle_branding_update(self) -> None:
            payload = self.read_json(MAX_BRANDING_JSON_BYTES)
            kind = require_string(payload.get("kind"), "Branding type", max_length=10)
            if kind not in BRANDING_IMAGE_LIMITS:
                raise ValidationError("Branding type must be logo or banner.")

            setting_key = f"portal_{kind}_file"
            old_filename = store.get_setting(setting_key)
            action = require_string(payload.get("action") or "upload", "Action", max_length=10)
            if action == "reset":
                if old_filename and Path(old_filename).name == old_filename:
                    (config.branding_dir / old_filename).unlink(missing_ok=True)
                values = {setting_key: ""}
                if kind == "logo" and store.get_setting("portal_logo_style") == "custom":
                    values["portal_logo_style"] = "signal"
                store.update_settings(values)
                self.send_json(
                    {
                        "ok": True,
                        "message": f"{kind.title()} restored to default.",
                        "branding": self.branding_payload(),
                        "settings": store.settings(),
                    }
                )
                return

            if action != "upload":
                raise ValidationError("Branding action is invalid.")

            content, extension = decode_branding_image(payload.get("imageData"), kind)
            config.branding_dir.mkdir(parents=True, exist_ok=True)
            filename = f"{kind}.{extension}"
            target = config.branding_dir / filename
            temporary = config.branding_dir / f".{filename}.upload"
            temporary.write_bytes(content)
            temporary.replace(target)

            for existing in config.branding_dir.glob(f"{kind}.*"):
                if existing != target and existing.is_file():
                    existing.unlink(missing_ok=True)

            values = {setting_key: filename}
            if kind == "logo":
                values["portal_logo_style"] = "custom"
            store.update_settings(values)
            self.send_json(
                {
                    "ok": True,
                    "message": f"{kind.title()} uploaded.",
                    "branding": self.branding_payload(),
                    "settings": store.settings(),
                }
            )

        def handle_session_create(self) -> None:
            payload = self.read_json()
            client_ip = validate_ip(payload.get("clientIp") or payload.get("client_ip"), "Client IP")
            client_mac = validate_mac(payload.get("clientMac") or payload.get("client_mac"), required=False) or resolve_client_mac(client_ip)
            minutes = validate_int(payload.get("minutes"), "Minutes", 1, 1440)
            amount_minor = validate_int(payload.get("amountMinor") or payload.get("amount_minor") or 0, "Amount", 0, 1000000)
            session = store.create_paid_session(
                client_ip=client_ip,
                client_mac=client_mac,
                minutes=minutes,
                amount_minor=amount_minor,
                pulse_count=0,
                source="admin",
            )
            firewall.reconcile(store.active_sessions())
            self.send_json({"ok": True, "session": session.to_dict()})

        def handle_coin_pulse(self) -> None:
            expected_token = store.get_setting("coin_api_token")
            provided_token = str(self.headers.get("X-DTimer-Coin-Token", ""))
            if not expected_token or provided_token != expected_token:
                self.send_json({"ok": False, "message": "Coin pulse API is disabled or unauthorized."}, HTTPStatus.UNAUTHORIZED)
                return

            payload = self.read_json()
            client_ip = validate_ip(payload.get("clientIp") or payload.get("client_ip"), "Client IP")
            client_mac = validate_mac(payload.get("clientMac") or payload.get("client_mac"), required=False) or resolve_client_mac(client_ip)
            pulses = validate_int(payload.get("pulseCount") or payload.get("pulse_count") or 1, "Pulse count", 1, 1000)
            minutes = int(store.get_setting("rate_minutes_per_coin", "5") or 5) * pulses
            amount_minor = int(store.get_setting("coin_amount_minor", "500") or 500) * pulses
            session = store.create_paid_session(
                client_ip=client_ip,
                client_mac=client_mac,
                minutes=minutes,
                amount_minor=amount_minor,
                pulse_count=pulses,
                source="coin",
            )
            firewall.reconcile(store.active_sessions())
            self.send_json({"ok": True, "session": session.to_dict()})

        def handle_session_action(self, path: str) -> None:
            parts = path.strip("/").split("/")
            if len(parts) != 4:
                self.send_json({"ok": False, "message": "Route not found."}, HTTPStatus.NOT_FOUND)
                return

            session_id = validate_int(parts[2], "Session ID", 1, 999999999)
            action = parts[3]
            if action == "pause":
                changed = store.pause_session(session_id)
            elif action == "resume":
                changed = store.resume_session(session_id)
            elif action == "block":
                changed = store.end_session(session_id, "blocked")
            else:
                self.send_json({"ok": False, "message": "Route not found."}, HTTPStatus.NOT_FOUND)
                return

            firewall.reconcile(store.active_sessions())
            self.send_json({"ok": changed, "sessions": [session.to_dict() for session in store.recent_sessions()]})

        def require_admin(self) -> dict[str, Any] | None:
            client_ip = client_ip_from_headers(self.client_address, self.headers)
            if not self.admin_ip_allowed(client_ip):
                self.send_json({"ok": False, "message": "Admin access is not allowed from this network."}, HTTPStatus.FORBIDDEN)
                return None

            cookie_value = self.get_cookie(SESSION_COOKIE)
            signed = verify_session(cookie_value, self.session_secret())
            if not signed:
                self.send_json({"ok": False, "message": "Authentication required."}, HTTPStatus.UNAUTHORIZED)
                return None

            admin = store.admin_public(signed.username)
            if not admin:
                self.send_json({"ok": False, "message": "Authentication required."}, HTTPStatus.UNAUTHORIZED)
                return None

            return admin

        def require_csrf(self) -> bool:
            cookie_value = self.get_cookie(SESSION_COOKIE)
            header = self.headers.get("X-CSRF-Token")
            if not verify_csrf_token(cookie_value, header, self.session_secret()):
                self.send_json({"ok": False, "message": "CSRF token is invalid."}, HTTPStatus.FORBIDDEN)
                return False

            return True

        def admin_ip_allowed(self, client_ip: str) -> bool:
            try:
                ip = ipaddress.ip_address(client_ip)
            except ValueError:
                return False

            cidrs = store.get_setting("admin_allowed_cidrs")
            for raw_cidr in cidrs.split(","):
                cidr = raw_cidr.strip()
                if not cidr:
                    continue
                try:
                    if ip in ipaddress.ip_network(cidr, strict=False):
                        return True
                except ValueError:
                    continue

            return False

        def read_json(self, max_bytes: int = MAX_JSON_BYTES) -> dict[str, Any]:
            length = int(self.headers.get("Content-Length", "0") or 0)
            if length > max_bytes:
                raise ValidationError("Request body is too large.")

            raw = self.rfile.read(length) if length else b"{}"
            try:
                data = json.loads(raw.decode("utf-8"))
            except json.JSONDecodeError:
                raise ValidationError("Request body must be valid JSON.") from None

            if not isinstance(data, dict):
                raise ValidationError("Request body must be a JSON object.")

            return data

        def send_json(self, payload: dict[str, Any], status: HTTPStatus = HTTPStatus.OK) -> None:
            body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Referrer-Policy", "same-origin")
            self.end_headers()
            self.wfile.write(body)

        def branding_payload(self) -> dict[str, Any]:
            logo_file = store.get_setting("portal_logo_file")
            banner_file = store.get_setting("portal_banner_file")
            return {
                "name": store.get_setting("portal_brand_name", "DTimerFi"),
                "logoStyle": store.get_setting("portal_logo_style", "signal"),
                "logoUrl": f"/branding/{logo_file}" if logo_file else None,
                "bannerUrl": f"/branding/{banner_file}" if banner_file else None,
            }

        def redirect_to_portal(self) -> None:
            customer_address = os.getenv("DTIMER_CUSTOMER_ADDRESS", "10.0.0.1/20").split("/", 1)[0]
            port = os.getenv("DTIMER_PORT", "8080")
            self.send_response(HTTPStatus.FOUND)
            self.send_header("Location", f"http://{customer_address}:{port}/")
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.end_headers()

        def serve_static_asset(self, path: str) -> None:
            relative = unquote(path.lstrip("/"))
            static_path = (config.static_dir / relative).resolve()
            static_root = config.static_dir.resolve()
            if not str(static_path).startswith(str(static_root)) or not static_path.is_file():
                self.send_response(HTTPStatus.NOT_FOUND)
                self.end_headers()
                return

            content = static_path.read_bytes()
            self.send_response(HTTPStatus.OK)
            self.send_header("Content-Type", mimetypes.guess_type(str(static_path))[0] or "application/octet-stream")
            self.send_header("Content-Length", str(len(content)))
            self.send_header("Cache-Control", "public, max-age=31536000, immutable")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.end_headers()
            self.wfile.write(content)

        def serve_branding_asset(self, path: str) -> None:
            filename = unquote(path.removeprefix("/branding/"))
            allowed = {
                store.get_setting("portal_logo_file"),
                store.get_setting("portal_banner_file"),
            }
            if not filename or filename not in allowed or Path(filename).name != filename:
                self.send_response(HTTPStatus.NOT_FOUND)
                self.end_headers()
                return

            asset = config.branding_dir / filename
            if not asset.is_file():
                self.send_response(HTTPStatus.NOT_FOUND)
                self.end_headers()
                return

            content = asset.read_bytes()
            self.send_response(HTTPStatus.OK)
            self.send_header("Content-Type", mimetypes.guess_type(str(asset))[0] or "application/octet-stream")
            self.send_header("Content-Length", str(len(content)))
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.end_headers()
            self.wfile.write(content)

        def serve_spa(self) -> None:
            index = config.static_dir / "index.html"
            if index.is_file():
                content = index.read_bytes()
            else:
                content = fallback_html().encode("utf-8")

            self.send_response(HTTPStatus.OK)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(len(content)))
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Content-Security-Policy", "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'")
            self.end_headers()
            self.wfile.write(content)

        def session_secret(self) -> str:
            return store.get_setting("web_session_secret")

        def get_cookie(self, name: str) -> str | None:
            raw = self.headers.get("Cookie", "")
            cookies = SimpleCookie()
            cookies.load(raw)
            morsel = cookies.get(name)
            return morsel.value if morsel else None

        def session_cookie(self, value: str) -> str:
            return f"{SESSION_COOKIE}={value}; Path=/; HttpOnly; SameSite=Strict; Max-Age=43200"

        def expired_session_cookie(self) -> str:
            return f"{SESSION_COOKIE}=; Path=/; HttpOnly; SameSite=Strict; Max-Age=0"

    return DTimerRequestHandler


def fallback_html() -> str:
    return """<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DTimer WiFi</title>
  <style>
    body { font-family: system-ui, sans-serif; margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0d1117; color: #f6f8fa; }
    main { max-width: 560px; padding: 32px; }
    code { background: #21262d; padding: 3px 6px; border-radius: 4px; }
  </style>
</head>
<body>
  <main>
    <h1>DTimer WiFi Orange Pi</h1>
    <p>The backend is running. Build the Svelte frontend with <code>npm run build</code> inside this folder before production packaging.</p>
  </main>
</body>
</html>"""
