from __future__ import annotations

import base64
import hashlib
import hmac
import os
import time
from dataclasses import dataclass


HASH_NAME = "sha256"
PBKDF2_ROUNDS = 240_000
SESSION_TTL_SECONDS = 12 * 60 * 60
CSRF_TTL_SECONDS = SESSION_TTL_SECONDS


def hash_password(password: str) -> str:
    salt = os.urandom(16)
    digest = hashlib.pbkdf2_hmac(HASH_NAME, password.encode("utf-8"), salt, PBKDF2_ROUNDS)

    return "pbkdf2_sha256${}${}${}".format(
        PBKDF2_ROUNDS,
        base64.urlsafe_b64encode(salt).decode("ascii"),
        base64.urlsafe_b64encode(digest).decode("ascii"),
    )


def verify_password(password: str, encoded: str) -> bool:
    try:
        algorithm, rounds, salt_b64, digest_b64 = encoded.split("$", 3)
        if algorithm != "pbkdf2_sha256":
            return False

        salt = base64.urlsafe_b64decode(salt_b64.encode("ascii"))
        expected = base64.urlsafe_b64decode(digest_b64.encode("ascii"))
        actual = hashlib.pbkdf2_hmac(HASH_NAME, password.encode("utf-8"), salt, int(rounds))

        return hmac.compare_digest(actual, expected)
    except (ValueError, TypeError, OSError):
        return False


def generate_secret() -> str:
    return base64.urlsafe_b64encode(os.urandom(32)).decode("ascii").rstrip("=")


@dataclass(frozen=True)
class SignedSession:
    username: str
    issued_at: int


def sign_session(username: str, secret: str, now: int | None = None) -> str:
    issued_at = int(now if now is not None else time.time())
    nonce = generate_secret()
    payload = f"{username}|{issued_at}|{nonce}"
    signature = hmac.new(secret.encode("utf-8"), payload.encode("utf-8"), hashlib.sha256).hexdigest()

    return f"{payload}|{signature}"


def verify_session(cookie_value: str | None, secret: str, now: int | None = None) -> SignedSession | None:
    if not cookie_value:
        return None

    try:
        username, issued_at_raw, nonce, signature = cookie_value.split("|", 3)
        issued_at = int(issued_at_raw)
    except ValueError:
        return None

    payload = f"{username}|{issued_at}|{nonce}"
    expected = hmac.new(secret.encode("utf-8"), payload.encode("utf-8"), hashlib.sha256).hexdigest()

    if not hmac.compare_digest(signature, expected):
        return None

    current = int(now if now is not None else time.time())
    if issued_at > current + 60 or current - issued_at > SESSION_TTL_SECONDS:
        return None

    return SignedSession(username=username, issued_at=issued_at)


def csrf_token_for_session(cookie_value: str, secret: str) -> str:
    return hmac.new(
        secret.encode("utf-8"),
        f"csrf|{cookie_value}".encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()


def verify_csrf_token(cookie_value: str | None, token: str | None, secret: str) -> bool:
    if not cookie_value or not token:
        return False

    expected = csrf_token_for_session(cookie_value, secret)
    return hmac.compare_digest(expected, token)


def password_is_strong(password: str, username: str = "") -> tuple[bool, str]:
    if len(password) < 8:
        return False, "Password must be at least 8 characters."

    lowered = password.lower()
    if lowered in {"admin", "password", "dtimer", "orange"}:
        return False, "Password is too easy to guess."

    if username and lowered == username.lower():
        return False, "Password cannot match the username."

    has_letter = any(ch.isalpha() for ch in password)
    has_digit = any(ch.isdigit() for ch in password)
    if not has_letter or not has_digit:
        return False, "Password must include letters and numbers."

    return True, ""
