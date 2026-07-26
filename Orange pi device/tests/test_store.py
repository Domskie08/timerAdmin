import shutil
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from dtimer_device.store import DEFAULT_ADMIN_PASSWORD, DEFAULT_ADMIN_USERNAME, DeviceStore  # noqa: E402


class DeviceStoreTest(unittest.TestCase):
    def setUp(self):
        self.tmpdir = Path(tempfile.mkdtemp())
        self.store = DeviceStore(self.tmpdir / "device.sqlite3")
        self.store.bootstrap()

    def tearDown(self):
        shutil.rmtree(self.tmpdir)

    def test_first_boot_creates_default_super_admin_with_password_change_required(self):
        admin = self.store.verify_admin(DEFAULT_ADMIN_USERNAME, DEFAULT_ADMIN_PASSWORD)

        self.assertIsNotNone(admin)
        self.assertTrue(admin["is_super_admin"])
        self.assertTrue(admin["must_change_password"])

    def test_admin_password_change_clears_forced_change_flag(self):
        ok, message = self.store.change_admin_password("admin", "wrong", "StrongPass1")
        self.assertFalse(ok)
        self.assertIn("Current password", message)

        ok, _ = self.store.change_admin_password("admin", "admin", "StrongPass1")
        self.assertTrue(ok)

        admin = self.store.verify_admin("admin", "StrongPass1")
        self.assertIsNotNone(admin)
        self.assertFalse(admin["must_change_password"])
        self.assertIsNone(self.store.verify_admin("admin", "admin"))

    def test_login_lockout_after_repeated_failures(self):
        identity = "127.0.0.1|admin"
        for _ in range(5):
            self.store.record_login_failure(identity)

        self.assertIsNotNone(self.store.login_locked_until(identity))
        self.store.clear_login_failures(identity)
        self.assertIsNone(self.store.login_locked_until(identity))

    def test_paid_session_lifecycle_and_sync_queue(self):
        session = self.store.create_paid_session(
            client_ip="192.168.8.10",
            client_mac="AA:BB:CC:11:22:33",
            minutes=5,
            amount_minor=500,
            pulse_count=1,
            source="coin",
        )

        self.assertEqual("active", session.status)
        self.assertEqual(1, self.store.stats()["active_sessions"])
        self.assertEqual(1, len(self.store.pending_sync_events()))

        self.assertTrue(self.store.pause_session(session.id))
        paused = self.store.get_session(session.id)
        self.assertEqual("paused", paused.status)
        self.assertGreater(paused.remaining_seconds, 0)

        self.assertTrue(self.store.resume_session(session.id))
        self.assertEqual("active", self.store.get_session(session.id).status)

        self.assertTrue(self.store.end_session(session.id, "blocked"))
        self.assertEqual("blocked", self.store.get_session(session.id).status)

    def test_normal_settings_do_not_expose_device_secret(self):
        self.store.update_settings({"device_secret": "a" * 64, "coin_api_token": "coin-token"})
        settings = self.store.settings()

        self.assertNotIn("device_secret", settings)
        self.assertNotIn("coin_api_token", settings)
        self.assertTrue(settings["device_secret_configured"])
        self.assertTrue(settings["coin_api_token_configured"])


if __name__ == "__main__":
    unittest.main()
