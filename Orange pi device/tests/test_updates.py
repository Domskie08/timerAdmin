import json
import shutil
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from dtimer_device.updates import UpdateChecker  # noqa: E402


class FakeHttpResponse:
    def __init__(self, payload, raw=False):
        self.payload = payload.encode("utf-8") if raw else json.dumps(payload).encode("utf-8")

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc_value, traceback):
        return False

    def read(self, _size):
        return self.payload


class UpdateCheckerTest(unittest.TestCase):
    def setUp(self):
        self.tmpdir = Path(tempfile.mkdtemp())

    def tearDown(self):
        shutil.rmtree(self.tmpdir)

    @patch("dtimer_device.updates.urlopen")
    def test_online_check_lists_every_release_and_marks_newer_versions(self, mocked_urlopen):
        mocked_urlopen.return_value = FakeHttpResponse(
            {
                "updates": [
                    {
                        "id": 2,
                        "title": "DTimer 1.2.0",
                        "version": "1.2.0",
                        "description": "New release",
                        "downloadUrl": "https://dtimerapp.online/api/v1/updates/2/download",
                    },
                    {
                        "id": 1,
                        "title": "DTimer 0.9.0",
                        "version": "0.9.0",
                        "downloadUrl": "https://dtimerapp.online/api/v1/updates/1/download",
                    },
                ]
            }
        )
        checker = UpdateChecker(
            usb_roots=(self.tmpdir / "missing",),
            installed_version="1.0.0",
        )

        updates, source = checker.online_updates("1.0.0")

        self.assertTrue(source["ok"])
        self.assertEqual(2, source["count"])
        self.assertTrue(updates[0]["isNewer"])
        self.assertFalse(updates[1]["isNewer"])

    @patch("dtimer_device.updates.urlopen")
    def test_online_check_reads_full_history_from_support_page_until_api_is_deployed(self, mocked_urlopen):
        support_page = """
        <html><body>
          <script data-page="app" type="application/json">
            {"component":"SupportPage","props":{"updates":[
              {"id":2,"title":"DTimer 1.2.0","version":"1.2.0"},
              {"id":1,"title":"DTimer 1.1.0","version":"1.1.0"}
            ]}}
          </script>
        </body></html>
        """

        def respond(request, timeout):
            self.assertEqual(8, timeout)
            if request.full_url.endswith("/api/v1/updates"):
                raise HTTPError(request.full_url, 404, "Not Found", {}, None)
            return FakeHttpResponse(support_page, raw=True)

        mocked_urlopen.side_effect = respond
        checker = UpdateChecker(installed_version="1.0.0")

        updates, source = checker.online_updates("1.0.0")

        self.assertTrue(source["ok"])
        self.assertTrue(source["endpoint"].endswith("/support"))
        self.assertEqual(["1.2.0", "1.1.0"], [update["version"] for update in updates])

    @patch("dtimer_device.updates.urlopen")
    def test_online_check_falls_back_to_live_latest_endpoint(self, mocked_urlopen):
        latest = {
            "has_update": True,
            "update": {
                "id": 3,
                "title": "DTimer 1.3.0",
                "version": "1.3.0",
                "downloadUrl": "https://dtimerapp.online/api/v1/updates/3/download",
            },
        }

        def respond(request, timeout):
            self.assertEqual(8, timeout)
            if request.full_url.endswith("/api/v1/updates"):
                raise HTTPError(request.full_url, 404, "Not Found", {}, None)
            return FakeHttpResponse(latest)

        mocked_urlopen.side_effect = respond
        checker = UpdateChecker(installed_version="1.0.0")

        updates, source = checker.online_updates("1.0.0")

        self.assertTrue(source["ok"])
        self.assertTrue(source["endpoint"].endswith("/updates/latest"))
        self.assertEqual("1.3.0", updates[0]["version"])

    def test_usb_check_lists_dtimer_deb_packages(self):
        usb_root = self.tmpdir / "usb"
        package = usb_root / "updates" / "dtimer-orange-pi_1.4.0_all.deb"
        package.parent.mkdir(parents=True)
        package.write_bytes(b"test package")
        checker = UpdateChecker(
            usb_roots=(usb_root,),
            installed_version="1.0.0",
        )

        with patch.object(
            checker,
            "read_deb_metadata",
            return_value={
                "package": "dtimer-orange-pi",
                "version": "1.4.0",
                "architecture": "all",
                "verified": True,
            },
        ):
            updates, source = checker.usb_updates("1.0.0")

        self.assertTrue(source["ok"])
        self.assertEqual(1, source["count"])
        self.assertEqual("usb", updates[0]["source"])
        self.assertEqual("updates/dtimer-orange-pi_1.4.0_all.deb", updates[0]["location"].replace("\\", "/"))
        self.assertTrue(updates[0]["isNewer"])


if __name__ == "__main__":
    unittest.main()
