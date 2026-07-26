import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from dtimer_device.validators import (  # noqa: E402
    ValidationError,
    validate_cidr_list,
    validate_license_key,
    validate_mac,
    validate_settings,
    validate_url,
)


class ValidatorsTest(unittest.TestCase):
    def test_license_key_must_be_twelve_digits_when_present(self):
        self.assertEqual("123456789012", validate_license_key("123456789012"))
        with self.assertRaises(ValidationError):
            validate_license_key("abc")

    def test_mac_is_normalized(self):
        self.assertEqual("AA:BB:CC:11:22:33", validate_mac("aa-bb-cc-11-22-33"))
        with self.assertRaises(ValidationError):
            validate_mac("not-a-mac")

    def test_timeradmin_url_must_be_http_or_https(self):
        self.assertEqual("https://example.com", validate_url("https://example.com/"))
        with self.assertRaises(ValidationError):
            validate_url("file:///tmp/test")

    def test_cidr_list_is_normalized(self):
        self.assertEqual("192.168.0.0/24,10.0.0.0/8", validate_cidr_list("192.168.0.0/24, 10.0.0.0/8"))
        with self.assertRaises(ValidationError):
            validate_cidr_list("bad-cidr")

    def test_settings_validation_keeps_known_fields_only(self):
        validated = validate_settings(
            {
                "device_name": "Shop Pi",
                "license_key": "123456789012",
                "network_enforcement_enabled": True,
                "unknown": "ignored",
            }
        )

        self.assertEqual("Shop Pi", validated["device_name"])
        self.assertEqual("123456789012", validated["license_key"])
        self.assertEqual("1", validated["network_enforcement_enabled"])
        self.assertNotIn("unknown", validated)


if __name__ == "__main__":
    unittest.main()
