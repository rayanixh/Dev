#!/usr/bin/env python3
"""Static reconnaissance for an Android APK, with no JADX or JDK required.

Covers the first pass of a reverse-engineering investigation: identity and
signing, manifest surface, component exposure, deep links, DEX inventory, and
the network endpoints baked into the bytecode. It reads the file only; nothing
is executed, installed, or uploaded.

Setup (PyPI is enough, no Android SDK needed):

    python3 -m venv /tmp/rev && /tmp/rev/bin/pip install androguard
    /tmp/rev/bin/python scripts/apk-recon.py path/to/app.apk

Options:
    --json          machine-readable output
    --hosts-only    just the network endpoint list
"""

from __future__ import annotations

import argparse
import collections
import json
import re
import sys
import zipfile

try:
    from loguru import logger

    logger.remove()  # androguard is extremely chatty at DEBUG level
    from androguard.core.apk import APK
    from androguard.core.axml import AXMLPrinter
    from androguard.core.dex import DEX
except ImportError:
    sys.exit("missing dependency: pip install androguard")

# Documentation/standards hosts that say nothing about the app's own backend.
NOISE = (
    "w3.org", "xml.org", "xmlpull", "dashif", "schema", "github.com",
    "developer.android", "firebase.google.com", "developers.facebook",
    "www.google.com", "goo.gl", "example.com", "aomedia.org",
    "developer.apple.com", "adobe.com", "localhost", "default.url",
)
THIRD_PARTY_SDK = re.compile(
    r"^(com/(google|facebook|amazon|clevertap|mixpanel)|androidx?/|kotlin/|okhttp3/|"
    r"org/(jetbrains|json|apache|reactivestreams)/)"
)


def as_text(s):
    return s if isinstance(s, str) else s.get()


def recon(path: str) -> dict:
    az = zipfile.ZipFile(path)
    apk = APK(path)
    out: dict = {}

    out["identity"] = {
        "package": apk.get_package(),
        "app_name": apk.get_app_name(),
        "version_name": apk.get_androidversion_name(),
        "version_code": apk.get_androidversion_code(),
        "min_sdk": apk.get_min_sdk_version(),
        "target_sdk": apk.get_target_sdk_version(),
    }

    certs = []
    try:
        for c in apk.get_certificates():
            certs.append({
                "subject": c.subject.human_friendly,
                "issuer": c.issuer.human_friendly,
                "sha256": c.sha256.hex(),
                "valid_from": str(c.not_valid_before),
                "valid_to": str(c.not_valid_after),
                "self_signed": c.subject.human_friendly == c.issuer.human_friendly,
            })
    except Exception as exc:  # noqa: BLE001 - cert parsing is best effort
        certs.append({"error": str(exc)})
    out["signing"] = certs

    xml = AXMLPrinter(az.read("AndroidManifest.xml")).get_xml().decode("utf-8", "replace")

    launcher = []
    for block in re.findall(r"<activity[^>]*?>.*?</activity>", xml, re.S):
        if "LAUNCHER" in block:
            m = re.search(r'android:name="([^"]+)"', block)
            if m:
                launcher.append(m.group(1))
    out["launcher_activities"] = launcher

    exported = {}
    for kind in ("activity", "service", "receiver", "provider"):
        names = []
        for block in re.findall(rf"<{kind}\b[^>]*>", xml):
            if 'android:exported="true"' in block:
                m = re.search(r'android:name="([^"]+)"', block)
                if m:
                    names.append(m.group(1))
        exported[{"activity": "activities", "service": "services",
                  "receiver": "receivers", "provider": "providers"}[kind]] = names
    out["exported_components"] = exported

    out["deep_links"] = {
        "schemes": sorted(set(re.findall(r'<data[^>]*android:scheme="([^"]+)"', xml))),
        "hosts": sorted(set(re.findall(r'<data[^>]*android:host="([^"]+)"', xml))),
    }

    flags = {}
    for kw in ("usesCleartextTraffic", "networkSecurityConfig", "allowBackup",
               "debuggable", "extractNativeLibs"):
        hit = re.search(rf'android:{kw}="([^"]+)"', xml)
        flags[kw] = hit.group(1) if hit else "(default)"
    out["manifest_flags"] = flags
    out["permissions"] = sorted(apk.get_permissions() or [])

    files = apk.get_files()
    out["native_libs"] = sorted(f for f in files if f.endswith(".so"))
    out["dex_files"] = sorted(f for f in files if f.endswith(".dex"))

    urls: set[str] = set()
    packages: collections.Counter = collections.Counter()
    modules: collections.Counter = collections.Counter()
    drm: set[str] = set()
    total_classes = 0
    for dex_name in out["dex_files"]:
        dex = DEX(az.read(dex_name))
        for s in dex.get_strings():
            v = as_text(s)
            if isinstance(v, str) and ("http://" in v or "https://" in v):
                urls.update(re.findall(r"https?://[A-Za-z0-9._~:/?#\[\]@!$&'()*+,;=%-]{4,120}", v))
        for c in dex.get_classes():
            total_classes += 1
            cn = c.get_name().lstrip("L").rstrip(";")
            parts = cn.split("/")
            if len(parts) >= 3:
                packages["/".join(parts[:3])] += 1
            if len(parts) >= 4:
                modules["/".join(parts[:4])] += 1
            if re.search(r"(?i)(widevine|clearkey|playready|drm)", cn):
                drm.add(cn)

    out["dex"] = {
        "total_classes": total_classes,
        "top_packages": packages.most_common(12),
        "top_modules": modules.most_common(15),
        "third_party_class_count": sum(
            n for p, n in packages.items() if THIRD_PARTY_SDK.match(p + "/")
        ),
        "drm_classes": sorted(drm)[:10],
    }

    hosts = collections.Counter()
    app_urls = []
    for u in sorted(urls):
        host = re.sub(r"^https?://", "", u).split("/")[0]
        hosts[host] += 1
        if not any(n in u for n in NOISE):
            app_urls.append(u)
    out["network"] = {
        "distinct_hosts": len(hosts),
        "top_hosts": hosts.most_common(20),
        "app_urls": app_urls,
    }
    return out


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("apk")
    p.add_argument("--json", action="store_true")
    p.add_argument("--hosts-only", action="store_true")
    a = p.parse_args()
    data = recon(a.apk)

    if a.json:
        print(json.dumps(data, indent=2, default=str))
        return 0

    if a.hosts_only:
        for u in data["network"]["app_urls"]:
            print(u)
        return 0

    i = data["identity"]
    print("== IDENTITY")
    for k in ("package", "app_name", "version_name", "version_code", "min_sdk", "target_sdk"):
        print(f"  {k:13} {i[k]}")

    print("\n== SIGNING")
    for c in data["signing"]:
        if "error" in c:
            print("  error:", c["error"])
        else:
            print(f"  {c['subject']}")
            print(f"  self-signed: {c['self_signed']} | valid {c['valid_from'][:10]} -> {c['valid_to'][:10]}")
            print(f"  cert sha256: {c['sha256']}")

    print("\n== LAUNCHER")
    for n in data["launcher_activities"]:
        print("  ", n)

    print("\n== EXPORTED COMPONENTS")
    for kind, names in data["exported_components"].items():
        print(f"  {kind} ({len(names)})")
        for n in names:
            print("     ", n)

    print("\n== DEEP LINKS")
    print("  schemes:", data["deep_links"]["schemes"])
    print("  hosts  :", data["deep_links"]["hosts"])

    print("\n== MANIFEST FLAGS")
    for k, v in data["manifest_flags"].items():
        print(f"  {k:23} {v}")

    print(f"\n== PERMISSIONS ({len(data['permissions'])})")
    for perm in data["permissions"]:
        print("  ", perm)

    print("\n== ARTIFACT CONTENTS")
    print("  dex:", data["dex_files"])
    print("  native libs:", data["native_libs"] or "none")

    d = data["dex"]
    print(f"\n== CODE ({d['total_classes']} classes)")
    print("  top packages:")
    for name, n in d["top_packages"]:
        print(f"    {n:6}  {name}")
    print("  top modules:")
    for name, n in d["top_modules"]:
        print(f"    {n:6}  {name}")
    print("  drm-related classes:", len(d["drm_classes"]))

    n = data["network"]
    print(f"\n== NETWORK ({n['distinct_hosts']} distinct hosts)")
    for h, c in n["top_hosts"]:
        print(f"    {c:4}  {h}")
    print("  app-specific URLs:")
    for u in n["app_urls"][:25]:
        print("     ", u)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
