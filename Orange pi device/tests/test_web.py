import http.client
import json
import shutil
import sys
import tempfile
import threading
import unittest
from http.server import ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from dtimer_device.config import DeviceConfig  # noqa: E402
from dtimer_device.store import DeviceStore  # noqa: E402
from dtimer_device.web import make_handler  # noqa: E402


class FakeUpdateChecker:
    def check(self):
        return {
            "ok": True,
            "checkedAt": "2026-07-30T00:00:00+00:00",
            "installedVersion": "1.0.2",
            "hasNewer": True,
            "updates": [
                {
                    "id": "usb:test",
                    "source": "usb",
                    "sourceLabel": "USB - TEST",
                    "title": "DTimer Orange Pi package",
                    "version": "1.1.0",
                    "isNewer": True,
                }
            ],
            "sources": [{"id": "usb", "label": "USB storage", "ok": True, "count": 1}],
        }


class WebApiTest(unittest.TestCase):
    def setUp(self):
        self.tmpdir = Path(tempfile.mkdtemp())
        self.config = DeviceConfig.from_data_dir(self.tmpdir / "data")
        self.store = DeviceStore(self.config.database_path)
        self.store.bootstrap()
        self.server = ThreadingHTTPServer(
            ("127.0.0.1", 0),
            make_handler(self.config, self.store, update_checker=FakeUpdateChecker()),
        )
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.port = self.server.server_address[1]

    def tearDown(self):
        self.server.shutdown()
        self.thread.join(timeout=5)
        self.server.server_close()
        shutil.rmtree(self.tmpdir)

    def request(self, method, path, body=None, headers=None):
        conn = http.client.HTTPConnection("127.0.0.1", self.port, timeout=5)
        payload = json.dumps(body or {}) if body is not None else None
        request_headers = headers or {}
        if body is not None:
            request_headers = {"Content-Type": "application/json", **request_headers}
        conn.request(method, path, body=payload, headers=request_headers)
        response = conn.getresponse()
        raw = response.read().decode("utf-8")
        data = json.loads(raw) if raw else {}
        set_cookie = response.getheader("Set-Cookie")
        conn.close()
        return response.status, data, set_cookie

    def test_login_requires_csrf_for_admin_mutations_and_forces_password_change(self):
        status, data, cookie = self.request("POST", "/api/login", {"username": "admin", "password": "admin"})

        self.assertEqual(200, status)
        self.assertTrue(data["admin"]["must_change_password"])
        self.assertIn("dtimer_admin_session", cookie)

        session_cookie = cookie.split(";", 1)[0]
        headers = {"Cookie": session_cookie}
        status, data, _ = self.request("POST", "/api/admin/settings", {"device_name": "Unsafe"}, headers)
        self.assertEqual(403, status)

        csrf = data.get("csrfToken") or self.request("GET", "/api/admin/status", headers=headers)[1]["csrfToken"]
        headers["X-CSRF-Token"] = csrf
        status, data, _ = self.request("POST", "/api/admin/settings", {"device_name": "Locked"}, headers)
        self.assertEqual(428, status)

        status, data, _ = self.request(
            "POST",
            "/api/admin/password",
            {"currentPassword": "admin", "newPassword": "StrongPass1"},
            headers,
        )
        self.assertEqual(200, status)
        self.assertFalse(data["admin"]["must_change_password"])

    def test_device_secret_is_redacted_from_admin_status(self):
        self.store.change_admin_password("admin", "admin", "StrongPass1")
        self.store.update_settings({"device_secret": "a" * 64})
        status, data, cookie = self.request("POST", "/api/login", {"username": "admin", "password": "StrongPass1"})
        self.assertEqual(200, status)

        session_cookie = cookie.split(";", 1)[0]
        status, data, _ = self.request("GET", "/api/admin/status", headers={"Cookie": session_cookie})

        self.assertEqual(200, status)
        self.assertNotIn("device_secret", data["settings"])
        self.assertTrue(data["settings"]["device_secret_configured"])

    def test_authenticated_admin_can_check_online_and_usb_updates(self):
        status, _, _ = self.request("GET", "/api/admin/updates")
        self.assertEqual(401, status)

        status, _, cookie = self.request("POST", "/api/login", {"username": "admin", "password": "admin"})
        self.assertEqual(200, status)

        session_cookie = cookie.split(";", 1)[0]
        status, data, _ = self.request(
            "GET",
            "/api/admin/updates",
            headers={"Cookie": session_cookie},
        )

        self.assertEqual(200, status)
        self.assertEqual("1.0.2", data["installedVersion"])
        self.assertTrue(data["hasNewer"])
        self.assertEqual("usb", data["updates"][0]["source"])

    def test_admin_can_configure_branding_and_portal_passcode(self):
        self.store.change_admin_password("admin", "admin", "StrongPass1")
        status, login, cookie = self.request("POST", "/api/login", {"username": "admin", "password": "StrongPass1"})
        self.assertEqual(200, status)

        headers = {
            "Cookie": cookie.split(";", 1)[0],
            "X-CSRF-Token": login["csrfToken"],
        }
        status, data, _ = self.request(
            "POST",
            "/api/admin/settings",
            {"portal_brand_name": "DTimerFi North", "portal_logo_style": "monogram"},
            headers,
        )
        self.assertEqual(200, status)
        self.assertEqual("DTimerFi North", data["settings"]["portal_brand_name"])

        status, data, _ = self.request(
            "POST",
            "/api/admin/portal-passcode",
            {"newPasscode": "Portal123"},
            headers,
        )
        self.assertEqual(200, status)
        self.assertTrue(data["settings"]["portal_passcode_configured"])
        self.assertNotIn("portal_passcode_hash", data["settings"])

        png_data = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII="
        status, data, _ = self.request(
            "POST",
            "/api/admin/branding",
            {"kind": "logo", "action": "upload", "imageData": png_data},
            headers,
        )
        self.assertEqual(200, status)
        self.assertEqual("/branding/logo.png", data["branding"]["logoUrl"])
        self.assertTrue((self.config.branding_dir / "logo.png").is_file())

        status, public, _ = self.request("GET", "/api/status")
        self.assertEqual(200, status)
        self.assertEqual("DTimerFi North", public["branding"]["name"])
        self.assertTrue(public["account"]["passcodeConfigured"])

    def test_portal_account_requires_current_passcode_before_replacement(self):
        self.store.set_portal_passcode("Portal123")

        status, data, _ = self.request(
            "POST",
            "/api/account/passcode",
            {"currentPasscode": "WrongPass1", "newPasscode": "Changed123"},
        )
        self.assertEqual(401, status)
        self.assertEqual("Current passcode is incorrect.", data["message"])

        status, data, _ = self.request(
            "POST",
            "/api/account/passcode",
            {"currentPasscode": "Portal123", "newPasscode": "Changed123"},
        )
        self.assertEqual(200, status)
        self.assertTrue(data["ok"])
        self.assertTrue(self.store.verify_portal_passcode("Changed123"))


if __name__ == "__main__":
    unittest.main()
