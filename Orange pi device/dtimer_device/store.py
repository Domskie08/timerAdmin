from __future__ import annotations

import json
import sqlite3
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

from .security import generate_secret, hash_password, password_is_strong, verify_password


DEFAULT_ADMIN_USERNAME = "admin"
DEFAULT_ADMIN_PASSWORD = "admin"
LOGIN_LOCKOUT_FAILURES = 5
LOGIN_LOCKOUT_MINUTES = 15


def utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(microsecond=0)


def isoformat(value: datetime) -> str:
    return value.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def parse_datetime(value: str) -> datetime:
    normalized = value.replace("Z", "+00:00")
    return datetime.fromisoformat(normalized).astimezone(timezone.utc)


@dataclass(frozen=True)
class InternetSession:
    id: int
    client_ip: str
    client_mac: str | None
    minutes: int
    amount_minor: int
    status: str
    started_at: str
    expires_at: str
    source: str
    remaining_seconds: int | None = None

    def to_dict(self) -> dict[str, Any]:
        return {
            "id": self.id,
            "client_ip": self.client_ip,
            "client_mac": self.client_mac,
            "minutes": self.minutes,
            "amount_minor": self.amount_minor,
            "status": self.status,
            "started_at": self.started_at,
            "expires_at": self.expires_at,
            "source": self.source,
            "remaining_seconds": self.remaining_seconds,
        }


class DeviceStore:
    def __init__(self, database_path: Path):
        self.database_path = database_path

    def connect(self) -> sqlite3.Connection:
        self.database_path.parent.mkdir(parents=True, exist_ok=True)
        conn = sqlite3.connect(self.database_path)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA foreign_keys = ON")
        return conn

    @contextmanager
    def connection(self):
        conn = self.connect()
        try:
            yield conn
            conn.commit()
        finally:
            conn.close()

    def bootstrap(self) -> None:
        with self.connection() as conn:
            self._create_schema(conn)
            self._migrate_schema(conn)
            self._ensure_settings(conn)
            self._ensure_default_admin(conn)

    def _create_schema(self, conn: sqlite3.Connection) -> None:
        conn.executescript(
            """
            CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                is_super_admin INTEGER NOT NULL DEFAULT 0,
                must_change_password INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                last_login_at TEXT
            );

            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS login_attempts (
                identity TEXT PRIMARY KEY,
                failures INTEGER NOT NULL DEFAULT 0,
                locked_until TEXT,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS internet_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_ip TEXT NOT NULL,
                client_mac TEXT,
                minutes INTEGER NOT NULL,
                amount_minor INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL,
                source TEXT NOT NULL,
                started_at TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                paused_at TEXT,
                remaining_seconds INTEGER,
                ended_at TEXT,
                local_event_id TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE INDEX IF NOT EXISTS internet_sessions_status_expires_at
                ON internet_sessions (status, expires_at);

            CREATE INDEX IF NOT EXISTS internet_sessions_client_ip_status
                ON internet_sessions (client_ip, status);

            CREATE TABLE IF NOT EXISTS coin_sales (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                local_event_id TEXT NOT NULL UNIQUE,
                internet_session_id INTEGER,
                amount_minor INTEGER NOT NULL,
                currency TEXT NOT NULL DEFAULT 'PHP',
                pulse_count INTEGER NOT NULL DEFAULT 0,
                occurred_at TEXT NOT NULL,
                synced_at TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (internet_session_id) REFERENCES internet_sessions(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS sync_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                created_at TEXT NOT NULL,
                available_at TEXT NOT NULL,
                synced_at TEXT
            );

            CREATE INDEX IF NOT EXISTS sync_queue_pending
                ON sync_queue (synced_at, available_at);
            """
        )

    def _migrate_schema(self, conn: sqlite3.Connection) -> None:
        self._ensure_column(conn, "admins", "must_change_password", "INTEGER NOT NULL DEFAULT 0")
        self._ensure_column(conn, "admins", "last_login_at", "TEXT")
        self._ensure_column(conn, "internet_sessions", "paused_at", "TEXT")
        self._ensure_column(conn, "internet_sessions", "remaining_seconds", "INTEGER")

    def _ensure_column(self, conn: sqlite3.Connection, table: str, column: str, definition: str) -> None:
        columns = {str(row["name"]) for row in conn.execute(f"PRAGMA table_info({table})")}
        if column not in columns:
            conn.execute(f"ALTER TABLE {table} ADD COLUMN {column} {definition}")

    def _ensure_settings(self, conn: sqlite3.Connection) -> None:
        now = isoformat(utcnow())
        defaults = {
            "web_session_secret": generate_secret(),
            "device_name": "DTimer Orange Pi",
            "timeradmin_base_url": "",
            "license_key": "",
            "device_secret": "",
            "mac_address": "",
            "machine_id": "",
            "app_version": "0.1.0",
            "firmware_version": "",
            "wan_interface": "eth0",
            "customer_interface": "eth1",
            "rate_minutes_per_coin": "5",
            "coin_amount_minor": "500",
            "currency": "PHP",
            "portal_brand_name": "DTimerFi",
            "portal_logo_style": "signal",
            "portal_logo_file": "",
            "portal_banner_file": "",
            "portal_passcode_hash": "",
            "offline_mode": "1",
            "network_enforcement_enabled": "0",
            "coin_api_token": "",
            "admin_allowed_cidrs": "127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1/128,fc00::/7,fe80::/10",
        }

        for key, value in defaults.items():
            conn.execute(
                """
                INSERT OR IGNORE INTO settings (key, value, updated_at)
                VALUES (?, ?, ?)
                """,
                (key, value, now),
            )

    def _ensure_default_admin(self, conn: sqlite3.Connection) -> None:
        existing = conn.execute("SELECT COUNT(*) AS count FROM admins").fetchone()["count"]
        if existing:
            return

        now = isoformat(utcnow())
        conn.execute(
            """
            INSERT INTO admins (
                username,
                password_hash,
                is_super_admin,
                must_change_password,
                created_at,
                updated_at
            )
            VALUES (?, ?, 1, 1, ?, ?)
            """,
            (DEFAULT_ADMIN_USERNAME, hash_password(DEFAULT_ADMIN_PASSWORD), now, now),
        )

    def admin_public(self, username: str) -> dict[str, Any] | None:
        with self.connection() as conn:
            row = conn.execute(
                """
                SELECT username, is_super_admin, must_change_password, last_login_at
                FROM admins
                WHERE username = ?
                """,
                (username.strip(),),
            ).fetchone()

        if not row:
            return None

        return {
            "username": str(row["username"]),
            "is_super_admin": bool(row["is_super_admin"]),
            "must_change_password": bool(row["must_change_password"]),
            "last_login_at": row["last_login_at"],
        }

    def verify_admin(self, username: str, password: str) -> dict[str, Any] | None:
        clean_username = username.strip()
        with self.connection() as conn:
            row = conn.execute(
                "SELECT password_hash FROM admins WHERE username = ?",
                (clean_username,),
            ).fetchone()

            if not row or not verify_password(password, row["password_hash"]):
                return None

            now = isoformat(utcnow())
            conn.execute(
                "UPDATE admins SET last_login_at = ?, updated_at = ? WHERE username = ?",
                (now, now, clean_username),
            )

        return self.admin_public(clean_username)

    def change_admin_password(self, username: str, current_password: str, new_password: str) -> tuple[bool, str]:
        clean_username = username.strip()
        strong, message = password_is_strong(new_password, clean_username)
        if not strong:
            return False, message

        with self.connection() as conn:
            row = conn.execute(
                "SELECT password_hash FROM admins WHERE username = ?",
                (clean_username,),
            ).fetchone()

            if not row or not verify_password(current_password, row["password_hash"]):
                return False, "Current password is not correct."

            now = isoformat(utcnow())
            conn.execute(
                """
                UPDATE admins
                SET password_hash = ?, must_change_password = 0, updated_at = ?
                WHERE username = ?
                """,
                (hash_password(new_password), now, clean_username),
            )

        return True, "Password updated."

    def login_locked_until(self, identity: str) -> str | None:
        now = utcnow()
        with self.connection() as conn:
            row = conn.execute(
                "SELECT locked_until FROM login_attempts WHERE identity = ?",
                (identity,),
            ).fetchone()

        if not row or not row["locked_until"]:
            return None

        locked_until = parse_datetime(str(row["locked_until"]))
        if locked_until <= now:
            self.clear_login_failures(identity)
            return None

        return isoformat(locked_until)

    def record_login_failure(self, identity: str) -> None:
        now = utcnow()
        now_iso = isoformat(now)
        with self.connection() as conn:
            row = conn.execute(
                "SELECT failures FROM login_attempts WHERE identity = ?",
                (identity,),
            ).fetchone()
            failures = int(row["failures"]) + 1 if row else 1
            locked_until = isoformat(now + timedelta(minutes=LOGIN_LOCKOUT_MINUTES)) if failures >= LOGIN_LOCKOUT_FAILURES else None
            conn.execute(
                """
                INSERT INTO login_attempts (identity, failures, locked_until, updated_at)
                VALUES (?, ?, ?, ?)
                ON CONFLICT(identity) DO UPDATE SET
                    failures = excluded.failures,
                    locked_until = excluded.locked_until,
                    updated_at = excluded.updated_at
                """,
                (identity, failures, locked_until, now_iso),
            )

    def clear_login_failures(self, identity: str) -> None:
        with self.connection() as conn:
            conn.execute("DELETE FROM login_attempts WHERE identity = ?", (identity,))

    def get_setting(self, key: str, fallback: str = "") -> str:
        with self.connection() as conn:
            row = conn.execute("SELECT value FROM settings WHERE key = ?", (key,)).fetchone()

        return str(row["value"]) if row else fallback

    def settings(self, include_secret: bool = False) -> dict[str, Any]:
        with self.connection() as conn:
            rows = conn.execute("SELECT key, value FROM settings ORDER BY key").fetchall()

        settings = {str(row["key"]): str(row["value"]) for row in rows}
        if include_secret:
            return settings

        device_secret = settings.pop("device_secret", "")
        coin_api_token = settings.pop("coin_api_token", "")
        portal_passcode_hash = settings.pop("portal_passcode_hash", "")
        settings["device_secret_configured"] = bool(device_secret)
        settings["coin_api_token_configured"] = bool(coin_api_token)
        settings["portal_passcode_configured"] = bool(portal_passcode_hash)
        return settings

    def update_settings(self, values: dict[str, str]) -> None:
        allowed = {
            "device_name",
            "timeradmin_base_url",
            "license_key",
            "device_secret",
            "mac_address",
            "machine_id",
            "app_version",
            "firmware_version",
            "wan_interface",
            "customer_interface",
            "rate_minutes_per_coin",
            "coin_amount_minor",
            "currency",
            "portal_brand_name",
            "portal_logo_style",
            "portal_logo_file",
            "portal_banner_file",
            "portal_passcode_hash",
            "offline_mode",
            "network_enforcement_enabled",
            "coin_api_token",
            "admin_allowed_cidrs",
        }
        now = isoformat(utcnow())

        with self.connection() as conn:
            for key, value in values.items():
                if key not in allowed:
                    continue

                conn.execute(
                    """
                    INSERT INTO settings (key, value, updated_at)
                    VALUES (?, ?, ?)
                    ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at
                    """,
                    (key, str(value).strip(), now),
                )

    def portal_passcode_configured(self) -> bool:
        return bool(self.get_setting("portal_passcode_hash"))

    def verify_portal_passcode(self, passcode: str) -> bool:
        encoded = self.get_setting("portal_passcode_hash")
        return bool(encoded) and verify_password(passcode, encoded)

    def set_portal_passcode(self, passcode: str) -> None:
        self.update_settings({"portal_passcode_hash": hash_password(passcode)})

    def create_paid_session(
        self,
        *,
        client_ip: str,
        client_mac: str | None,
        minutes: int,
        amount_minor: int,
        pulse_count: int,
        source: str,
    ) -> InternetSession:
        safe_minutes = max(1, min(int(minutes), 24 * 60))
        safe_amount_minor = max(0, int(amount_minor))
        safe_pulse_count = max(0, int(pulse_count))
        currency = self.get_setting("currency", "PHP").upper()[:3]
        now_dt = utcnow()
        expires_at = now_dt + timedelta(minutes=safe_minutes)
        local_event_id = f"coin-{now_dt.strftime('%Y%m%d%H%M%S')}-{generate_secret()[:10]}"

        with self.connection() as conn:
            cursor = conn.execute(
                """
                INSERT INTO internet_sessions (
                    client_ip,
                    client_mac,
                    minutes,
                    amount_minor,
                    status,
                    source,
                    started_at,
                    expires_at,
                    local_event_id,
                    created_at,
                    updated_at
                )
                VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?)
                """,
                (
                    client_ip,
                    client_mac,
                    safe_minutes,
                    safe_amount_minor,
                    source,
                    isoformat(now_dt),
                    isoformat(expires_at),
                    local_event_id,
                    isoformat(now_dt),
                    isoformat(now_dt),
                ),
            )
            session_id = int(cursor.lastrowid)

            conn.execute(
                """
                INSERT OR IGNORE INTO coin_sales (
                    local_event_id,
                    internet_session_id,
                    amount_minor,
                    currency,
                    pulse_count,
                    occurred_at,
                    created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    local_event_id,
                    session_id,
                    safe_amount_minor,
                    currency,
                    safe_pulse_count,
                    isoformat(now_dt),
                    isoformat(now_dt),
                ),
            )

            self._enqueue_sync_locked(
                conn,
                "coin_sale",
                {
                    "local_event_id": local_event_id,
                    "session_id": str(session_id),
                    "amount_minor": safe_amount_minor,
                    "currency": currency,
                    "pulse_count": safe_pulse_count,
                    "occurred_at": isoformat(now_dt),
                    "user_slot": client_mac or client_ip,
                    "metadata": {
                        "source": source,
                        "client_ip": client_ip,
                        "client_mac": client_mac,
                        "minutes": safe_minutes,
                    },
                },
            )

        return self.get_session(session_id)

    def get_session(self, session_id: int) -> InternetSession:
        with self.connection() as conn:
            row = conn.execute(
                """
                SELECT id, client_ip, client_mac, minutes, amount_minor, status, started_at,
                    expires_at, source, remaining_seconds
                FROM internet_sessions
                WHERE id = ?
                """,
                (session_id,),
            ).fetchone()

        if row is None:
            raise KeyError(f"Internet session {session_id} was not found.")

        return self._session_from_row(row)

    def find_active_session_for_ip(self, client_ip: str) -> InternetSession | None:
        self.expire_due_sessions()
        now = isoformat(utcnow())
        with self.connection() as conn:
            row = conn.execute(
                """
                SELECT id, client_ip, client_mac, minutes, amount_minor, status, started_at,
                    expires_at, source, remaining_seconds
                FROM internet_sessions
                WHERE client_ip = ? AND status = 'active' AND expires_at > ?
                ORDER BY expires_at DESC
                LIMIT 1
                """,
                (client_ip, now),
            ).fetchone()

        return self._session_from_row(row) if row else None

    def expire_due_sessions(self) -> int:
        now = isoformat(utcnow())
        with self.connection() as conn:
            cursor = conn.execute(
                """
                UPDATE internet_sessions
                SET status = 'expired', ended_at = ?, updated_at = ?
                WHERE status = 'active' AND expires_at <= ?
                """,
                (now, now, now),
            )

        return int(cursor.rowcount)

    def pause_session(self, session_id: int) -> bool:
        session = self.get_session(session_id)
        if session.status != "active":
            return False

        remaining = max(0, int((parse_datetime(session.expires_at) - utcnow()).total_seconds()))
        now = isoformat(utcnow())
        with self.connection() as conn:
            cursor = conn.execute(
                """
                UPDATE internet_sessions
                SET status = 'paused', paused_at = ?, remaining_seconds = ?, updated_at = ?
                WHERE id = ? AND status = 'active'
                """,
                (now, remaining, now, session_id),
            )

        return cursor.rowcount > 0

    def resume_session(self, session_id: int) -> bool:
        session = self.get_session(session_id)
        if session.status != "paused":
            return False

        remaining = max(1, int(session.remaining_seconds or 1))
        now_dt = utcnow()
        expires_at = now_dt + timedelta(seconds=remaining)
        now = isoformat(now_dt)
        with self.connection() as conn:
            cursor = conn.execute(
                """
                UPDATE internet_sessions
                SET status = 'active',
                    paused_at = NULL,
                    remaining_seconds = NULL,
                    expires_at = ?,
                    updated_at = ?
                WHERE id = ? AND status = 'paused'
                """,
                (isoformat(expires_at), now, session_id),
            )

        return cursor.rowcount > 0

    def end_session(self, session_id: int, status: str = "blocked") -> bool:
        if status not in {"blocked", "expired"}:
            status = "blocked"

        now = isoformat(utcnow())
        with self.connection() as conn:
            cursor = conn.execute(
                """
                UPDATE internet_sessions
                SET status = ?, ended_at = ?, updated_at = ?
                WHERE id = ? AND status IN ('active', 'paused')
                """,
                (status, now, now, session_id),
            )

        return cursor.rowcount > 0

    def active_sessions(self) -> list[InternetSession]:
        self.expire_due_sessions()
        now = isoformat(utcnow())
        with self.connection() as conn:
            rows = conn.execute(
                """
                SELECT id, client_ip, client_mac, minutes, amount_minor, status, started_at,
                    expires_at, source, remaining_seconds
                FROM internet_sessions
                WHERE status = 'active' AND expires_at > ?
                ORDER BY expires_at ASC
                """,
                (now,),
            ).fetchall()

        return [self._session_from_row(row) for row in rows]

    def recent_sessions(self, limit: int = 30) -> list[InternetSession]:
        self.expire_due_sessions()
        with self.connection() as conn:
            rows = conn.execute(
                """
                SELECT id, client_ip, client_mac, minutes, amount_minor, status, started_at,
                    expires_at, source, remaining_seconds
                FROM internet_sessions
                ORDER BY id DESC
                LIMIT ?
                """,
                (max(1, min(limit, 200)),),
            ).fetchall()

        return [self._session_from_row(row) for row in rows]

    def stats(self) -> dict[str, int]:
        self.expire_due_sessions()
        today_start = utcnow().replace(hour=0, minute=0, second=0, microsecond=0)
        today_start_iso = isoformat(today_start)

        with self.connection() as conn:
            active = conn.execute(
                "SELECT COUNT(*) AS count FROM internet_sessions WHERE status = 'active' AND expires_at > ?",
                (isoformat(utcnow()),),
            ).fetchone()["count"]
            paused = conn.execute(
                "SELECT COUNT(*) AS count FROM internet_sessions WHERE status = 'paused'",
            ).fetchone()["count"]
            total_sales = conn.execute("SELECT COALESCE(SUM(amount_minor), 0) AS total FROM coin_sales").fetchone()["total"]
            today_sales = conn.execute(
                "SELECT COALESCE(SUM(amount_minor), 0) AS total FROM coin_sales WHERE occurred_at >= ?",
                (today_start_iso,),
            ).fetchone()["total"]
            pending_sync = conn.execute(
                "SELECT COUNT(*) AS count FROM sync_queue WHERE synced_at IS NULL",
            ).fetchone()["count"]

        return {
            "active_sessions": int(active),
            "paused_sessions": int(paused),
            "total_sales_amount_minor": int(total_sales or 0),
            "today_sales_amount_minor": int(today_sales or 0),
            "pending_sync": int(pending_sync),
        }

    def pending_sync_events(self, limit: int = 50) -> list[sqlite3.Row]:
        now = isoformat(utcnow())
        with self.connection() as conn:
            return conn.execute(
                """
                SELECT id, event_type, payload_json, attempts
                FROM sync_queue
                WHERE synced_at IS NULL AND available_at <= ?
                ORDER BY id ASC
                LIMIT ?
                """,
                (now, max(1, min(limit, 200))),
            ).fetchall()

    def mark_synced(self, queue_id: int) -> None:
        now = isoformat(utcnow())
        with self.connection() as conn:
            conn.execute(
                "UPDATE sync_queue SET synced_at = ? WHERE id = ?",
                (now, queue_id),
            )

    def mark_sync_failed(self, queue_id: int, error: str) -> None:
        now_dt = utcnow()
        retry_at = now_dt + timedelta(minutes=5)
        with self.connection() as conn:
            conn.execute(
                """
                UPDATE sync_queue
                SET attempts = attempts + 1, last_error = ?, available_at = ?
                WHERE id = ?
                """,
                (error[:500], isoformat(retry_at), queue_id),
            )

    def _enqueue_sync_locked(self, conn: sqlite3.Connection, event_type: str, payload: dict[str, Any]) -> None:
        now = isoformat(utcnow())
        conn.execute(
            """
            INSERT INTO sync_queue (event_type, payload_json, created_at, available_at)
            VALUES (?, ?, ?, ?)
            """,
            (event_type, json.dumps(payload, separators=(",", ":")), now, now),
        )

    def _session_from_row(self, row: sqlite3.Row) -> InternetSession:
        return InternetSession(
            id=int(row["id"]),
            client_ip=str(row["client_ip"]),
            client_mac=str(row["client_mac"]) if row["client_mac"] else None,
            minutes=int(row["minutes"]),
            amount_minor=int(row["amount_minor"]),
            status=str(row["status"]),
            started_at=str(row["started_at"]),
            expires_at=str(row["expires_at"]),
            source=str(row["source"]),
            remaining_seconds=int(row["remaining_seconds"]) if row["remaining_seconds"] is not None else None,
        )
