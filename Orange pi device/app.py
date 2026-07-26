#!/usr/bin/env python3
"""DTimer WiFi Orange Pi local controller.

This entrypoint intentionally uses only the Python standard library so it can
run on lightweight Debian/Ubuntu server images across Orange Pi boards.
"""

from __future__ import annotations

import argparse
import os
from pathlib import Path

from dtimer_device.config import DeviceConfig
from dtimer_device.store import DeviceStore
from dtimer_device.web import serve


def main() -> None:
    parser = argparse.ArgumentParser(description="Run the DTimer WiFi Orange Pi local web app.")
    parser.add_argument("--host", default=os.getenv("DTIMER_HOST", "0.0.0.0"))
    parser.add_argument("--port", type=int, default=int(os.getenv("DTIMER_PORT", "8080")))
    parser.add_argument(
        "--data-dir",
        default=os.getenv("DTIMER_DATA_DIR", str(Path(__file__).with_name("data"))),
        help="Directory for SQLite database and local runtime state.",
    )
    parser.add_argument(
        "--init-only",
        action="store_true",
        help="Initialize the SQLite database and exit. Used by the Debian package post-install script.",
    )
    args = parser.parse_args()

    config = DeviceConfig.from_data_dir(Path(args.data_dir))
    store = DeviceStore(config.database_path)
    store.bootstrap()

    if args.init_only:
        print(f"Initialized DTimer WiFi database at {config.database_path}")
        return

    print(f"DTimer WiFi Orange Pi app listening on http://{args.host}:{args.port}")
    print("Default first setup login is username 'admin' and password 'admin'.")
    serve(config=config, store=store, host=args.host, port=args.port)


if __name__ == "__main__":
    main()
