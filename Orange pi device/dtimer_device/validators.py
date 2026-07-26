from __future__ import annotations

import ipaddress
import re
from urllib.parse import urlparse


MAC_RE = re.compile(r"^[0-9A-Fa-f]{2}([:-]?[0-9A-Fa-f]{2}){5}$")


class ValidationError(ValueError):
    pass


def require_string(value: object, field: str, max_length: int = 255, required: bool = True) -> str:
    text = str(value or "").strip()
    if required and not text:
        raise ValidationError(f"{field} is required.")

    if len(text) > max_length:
        raise ValidationError(f"{field} is too long.")

    return text


def optional_bool(value: object) -> str:
    if str(value).lower() in {"1", "true", "yes", "on"}:
        return "1"

    return "0"


def validate_int(value: object, field: str, minimum: int, maximum: int) -> int:
    try:
        parsed = int(value)
    except (TypeError, ValueError):
        raise ValidationError(f"{field} must be a number.") from None

    if parsed < minimum or parsed > maximum:
        raise ValidationError(f"{field} must be between {minimum} and {maximum}.")

    return parsed


def validate_ip(value: object, field: str = "IP address") -> str:
    text = require_string(value, field, max_length=64)
    try:
        return str(ipaddress.ip_address(text))
    except ValueError:
        raise ValidationError(f"{field} is invalid.") from None


def validate_mac(value: object, required: bool = False) -> str:
    text = require_string(value, "MAC address", max_length=32, required=required).upper()
    if not text:
        return ""

    compact = text.replace("-", "").replace(":", "")
    if not MAC_RE.match(text):
        raise ValidationError("MAC address is invalid.")

    return ":".join(compact[index : index + 2] for index in range(0, 12, 2))


def validate_license_key(value: object, required: bool = False) -> str:
    text = require_string(value, "License key", max_length=12, required=required)
    if text and (not text.isdigit() or len(text) != 12):
        raise ValidationError("License key must be 12 digits.")

    return text


def validate_device_secret(value: object, required: bool = False) -> str:
    text = require_string(value, "Device secret", max_length=128, required=required)
    if text and len(text) != 64:
        raise ValidationError("Device secret must be 64 characters.")

    return text


def validate_url(value: object, required: bool = False) -> str:
    text = require_string(value, "TimerAdmin URL", max_length=255, required=required)
    if not text:
        return ""

    parsed = urlparse(text)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise ValidationError("TimerAdmin URL must start with http:// or https://.")

    return text.rstrip("/")


def validate_cidr_list(value: object) -> str:
    text = require_string(value, "Allowed admin CIDRs", max_length=500, required=False)
    if not text:
        return ""

    networks = []
    for part in text.split(","):
        candidate = part.strip()
        if not candidate:
            continue

        try:
            networks.append(str(ipaddress.ip_network(candidate, strict=False)))
        except ValueError:
            raise ValidationError(f"Allowed admin CIDR {candidate} is invalid.") from None

    return ",".join(networks)


def validate_settings(payload: dict[str, object]) -> dict[str, str]:
    validated: dict[str, str] = {}

    if "device_name" in payload:
        validated["device_name"] = require_string(payload.get("device_name"), "Device name", max_length=80)
    if "timeradmin_base_url" in payload:
        validated["timeradmin_base_url"] = validate_url(payload.get("timeradmin_base_url"), required=False)
    if "license_key" in payload:
        validated["license_key"] = validate_license_key(payload.get("license_key"), required=False)
    if "device_secret" in payload:
        validated["device_secret"] = validate_device_secret(payload.get("device_secret"), required=False)
    if "mac_address" in payload:
        validated["mac_address"] = validate_mac(payload.get("mac_address"), required=False)
    if "machine_id" in payload:
        validated["machine_id"] = require_string(payload.get("machine_id"), "Machine ID", max_length=100, required=False)
    if "app_version" in payload:
        validated["app_version"] = require_string(payload.get("app_version"), "App version", max_length=50, required=False)
    if "firmware_version" in payload:
        validated["firmware_version"] = require_string(payload.get("firmware_version"), "Firmware version", max_length=50, required=False)
    if "wan_interface" in payload:
        validated["wan_interface"] = require_string(payload.get("wan_interface"), "WAN interface", max_length=30)
    if "customer_interface" in payload:
        validated["customer_interface"] = require_string(payload.get("customer_interface"), "Customer interface", max_length=30)
    if "rate_minutes_per_coin" in payload:
        validated["rate_minutes_per_coin"] = str(validate_int(payload.get("rate_minutes_per_coin"), "Minutes per coin", 1, 1440))
    if "coin_amount_minor" in payload:
        validated["coin_amount_minor"] = str(validate_int(payload.get("coin_amount_minor"), "Coin amount", 1, 1000000))
    if "currency" in payload:
        validated["currency"] = require_string(payload.get("currency"), "Currency", max_length=3).upper()
    if "offline_mode" in payload:
        validated["offline_mode"] = optional_bool(payload.get("offline_mode"))
    if "network_enforcement_enabled" in payload:
        validated["network_enforcement_enabled"] = optional_bool(payload.get("network_enforcement_enabled"))
    if "coin_api_token" in payload:
        validated["coin_api_token"] = require_string(payload.get("coin_api_token"), "Coin API token", max_length=128, required=False)
    if "admin_allowed_cidrs" in payload:
        validated["admin_allowed_cidrs"] = validate_cidr_list(payload.get("admin_allowed_cidrs"))

    return validated
