from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class DeviceConfig:
    data_dir: Path
    database_path: Path
    firewall_state_path: Path
    active_sessions_path: Path
    branding_dir: Path
    static_dir: Path
    project_dir: Path

    @classmethod
    def from_data_dir(cls, data_dir: Path) -> "DeviceConfig":
        project_dir = Path(__file__).resolve().parents[1]
        resolved_data_dir = data_dir.expanduser().resolve()

        return cls(
            data_dir=resolved_data_dir,
            database_path=resolved_data_dir / "dtimer-orange-pi.sqlite3",
            firewall_state_path=resolved_data_dir / "firewall-state.json",
            active_sessions_path=resolved_data_dir / "active-sessions.json",
            branding_dir=resolved_data_dir / "branding",
            static_dir=project_dir / "frontend" / "dist",
            project_dir=project_dir,
        )
