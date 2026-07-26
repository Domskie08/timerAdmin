import sys
import time
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from dtimer_device.security import (  # noqa: E402
    csrf_token_for_session,
    hash_password,
    password_is_strong,
    sign_session,
    verify_csrf_token,
    verify_password,
    verify_session,
)


class SecurityTest(unittest.TestCase):
    def test_password_hash_verifies_and_rejects_wrong_password(self):
        encoded = hash_password("StrongPass1")

        self.assertTrue(verify_password("StrongPass1", encoded))
        self.assertFalse(verify_password("wrong", encoded))
        self.assertNotIn("StrongPass1", encoded)

    def test_signed_session_rejects_tampering_and_expiry(self):
        secret = "local-secret"
        cookie = sign_session("admin", secret, now=1_000)

        self.assertEqual("admin", verify_session(cookie, secret, now=1_100).username)
        self.assertIsNone(verify_session(cookie + "x", secret, now=1_100))
        self.assertIsNone(verify_session(cookie, secret, now=int(time.time()) + 100_000))

    def test_csrf_token_is_bound_to_session_cookie(self):
        secret = "local-secret"
        cookie = sign_session("admin", secret)
        token = csrf_token_for_session(cookie, secret)

        self.assertTrue(verify_csrf_token(cookie, token, secret))
        self.assertFalse(verify_csrf_token(cookie + "x", token, secret))
        self.assertFalse(verify_csrf_token(cookie, "bad", secret))

    def test_password_strength_blocks_weak_setup_passwords(self):
        self.assertFalse(password_is_strong("admin", "admin")[0])
        self.assertFalse(password_is_strong("abcdefgh", "admin")[0])
        self.assertTrue(password_is_strong("StrongPass1", "admin")[0])


if __name__ == "__main__":
    unittest.main()
