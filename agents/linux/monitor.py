#!/usr/bin/env python3
"""Minimal Linux agent for Dream Hosting Server Monitor (standard library only)."""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import platform
import shutil
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

VERSION = "0.1.0"


def api_post(base_url: str, path: str, payload: dict, credential: str | None = None) -> dict:
    headers = {"Accept": "application/json", "Content-Type": "application/json"}
    if credential:
        headers["Authorization"] = f"Bearer {credential}"
    request = urllib.request.Request(
        f"{base_url.rstrip('/')}{path}",
        data=json.dumps(payload).encode("utf-8"),
        headers=headers,
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=15) as response:
        return json.loads(response.read().decode("utf-8"))


def require_secure_url(base_url: str) -> None:
    parsed = urllib.parse.urlparse(base_url)
    if parsed.scheme == "https":
        return
    if parsed.scheme == "http" and parsed.hostname in {"localhost", "127.0.0.1", "::1"}:
        return
    raise ValueError("The monitor URL must use HTTPS (HTTP is allowed only for localhost).")


def load_or_enroll(base_url: str, config_path: Path) -> dict:
    if config_path.exists():
        config = json.loads(config_path.read_text(encoding="utf-8"))
        if config.get("base_url") != base_url.rstrip("/"):
            raise ValueError("The saved agent credential belongs to a different monitor URL.")
        return config

    enrollment_token = os.environ.get("DREAM_MONITOR_ENROLLMENT_TOKEN")
    if not enrollment_token:
        raise RuntimeError("Set DREAM_MONITOR_ENROLLMENT_TOKEN for the first enrollment.")

    enrolled = api_post(
        base_url,
        "/api/v1/agent/enroll",
        {
            "enrollment_token": enrollment_token,
            "hostname": platform.node()[:255] or "linux-host",
            "platform": "linux",
            "agent_version": VERSION,
        },
    )
    config = {
        "base_url": base_url.rstrip("/"),
        "server_id": enrolled["server_id"],
        "credential": enrolled["credential"],
        "credential_id": enrolled["credential_id"],
    }
    config_path.parent.mkdir(parents=True, exist_ok=True)
    descriptor = os.open(config_path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
        json.dump(config, handle)
    os.chmod(config_path, 0o600)
    os.environ.pop("DREAM_MONITOR_ENROLLMENT_TOKEN", None)
    print(f"Enrolled server {config['server_id']}; credential saved with owner-only permissions.")
    return config


def cpu_snapshot() -> tuple[int, int]:
    with open("/proc/stat", "r", encoding="ascii") as handle:
        values = [int(value) for value in handle.readline().split()[1:]]
    return sum(values), values[3] + (values[4] if len(values) > 4 else 0)


def cpu_percent(previous: tuple[int, int]) -> tuple[float, tuple[int, int]]:
    time.sleep(1)
    current = cpu_snapshot()
    total_delta = current[0] - previous[0]
    idle_delta = current[1] - previous[1]
    if total_delta <= 0:
        return 0.0, current
    return round(max(0.0, min(100.0, (1 - idle_delta / total_delta) * 100)), 2), current


def memory_bytes() -> tuple[int, int]:
    values: dict[str, int] = {}
    with open("/proc/meminfo", "r", encoding="ascii") as handle:
        for line in handle:
            key, raw_value = line.split(":", 1)
            if key in {"MemTotal", "MemAvailable"}:
                values[key] = int(raw_value.strip().split()[0]) * 1024
    total = values["MemTotal"]
    available = values.get("MemAvailable", 0)
    return total, max(0, total - available)


def disk_samples() -> list[dict]:
    samples: list[dict] = []
    seen: set[str] = set()
    ignored = {"proc", "sysfs", "tmpfs", "devtmpfs", "devpts", "cgroup", "cgroup2", "overlay", "squashfs", "nsfs"}
    try:
        with open("/proc/mounts", "r", encoding="utf-8") as handle:
            mounts = [line.split() for line in handle if len(line.split()) >= 3]
    except OSError:
        mounts = []
    for entry in mounts:
        mountpoint, filesystem = entry[1].replace("\\040", " "), entry[2]
        if filesystem in ignored or mountpoint in seen:
            continue
        seen.add(mountpoint)
        try:
            usage = shutil.disk_usage(mountpoint)
        except OSError:
            continue
        if usage.total > 0:
            samples.append({
                "filesystem": mountpoint[:255],
                "total_bytes": usage.total,
                "used_bytes": usage.used,
            })
        if len(samples) >= 32:
            break
    return samples


def collect_sample(previous_cpu: tuple[int, int]) -> tuple[dict, tuple[int, int]]:
    cpu, current_cpu = cpu_percent(previous_cpu)
    memory_total, memory_used = memory_bytes()
    return {
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
        "cpu_percent": cpu,
        "memory_total_bytes": memory_total,
        "memory_used_bytes": memory_used,
        "disks": disk_samples(),
    }, current_cpu


def main() -> int:
    parser = argparse.ArgumentParser(description="Collect Linux CPU, memory, and disk metrics.")
    parser.add_argument("--url", default=os.environ.get("DREAM_MONITOR_URL"), help="Monitor base URL (HTTPS required outside localhost)")
    parser.add_argument("--config", type=Path, default=Path.home() / ".config/dream-hosting-monitor/agent.json")
    parser.add_argument("--interval", type=int, default=60)
    parser.add_argument("--once", action="store_true", help="Send one sample and exit")
    args = parser.parse_args()
    if not args.url:
        parser.error("Set DREAM_MONITOR_URL or provide --url.")
    if args.interval < 15:
        parser.error("The minimum sample interval is 15 seconds.")
    require_secure_url(args.url)

    try:
        config = load_or_enroll(args.url, args.config)
    except (RuntimeError, ValueError, urllib.error.URLError, json.JSONDecodeError) as error:
        print(f"Enrollment failed: {error}", file=sys.stderr)
        return 1

    previous_cpu = cpu_snapshot()
    while True:
        try:
            sample, previous_cpu = collect_sample(previous_cpu)
            response = api_post(
                config["base_url"],
                "/api/v1/agent/metrics",
                {"batch_uuid": str(uuid.uuid4()), "agent_version": VERSION, "samples": [sample]},
                config["credential"],
            )
            print(f"Metrics {response.get('status', 'sent')} at {sample['collected_at']}")
        except (OSError, ValueError, urllib.error.URLError, json.JSONDecodeError) as error:
            print(f"Metric submission failed; will retry next interval: {error}", file=sys.stderr)
        if args.once:
            break
        time.sleep(args.interval)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
