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


class WebApiTest(unittest.TestCase):
    def setUp(self):
        self.tmpdir = Path(tempfile.mkdtemp())
        self.config = DeviceConfig.from_data_dir(self.tmpdir / "data")
        self.store = DeviceStore(self.config.database_path)
        self.store.bootstrap()
        self.server = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(self.config, self.store))
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


if __name__ == "__main__":
    unittest.main()
