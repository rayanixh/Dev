# REA setup status

[REA](https://github.com/morluto/rea) ("Reverse Engineer Anything") is an MCP
server plus CLI that lets an agent inspect binaries, apps, and artifacts. This
file records exactly what is installed in this workspace, what is verified
working, and what a blocked egress policy prevents.

## Installed

| Component | Version | Where |
| --- | --- | --- |
| `rea` CLI | 6.7.0 (`rea-agents`) | `/usr/local/bin/rea` → npm global |
| MCP server registration | `rea mcp` | `~/.claude.json` → `mcpServers.rea` |
| Agent skill | v42, 207 tools | `~/.agents/skills/`, `~/.claude/skills/`, `.agents/skills/` (committed) |

Verified by a real stdio handshake against `rea mcp`: the server reports
`rea 6.7.0` and advertises **207 tools**, including `open_binary`,
`analyze_javascript_application`, `inspect_android_package`, and
`inspect_managed_artifact`.

## Analysis engines

| Provider | State | Why |
| --- | --- | --- |
| Ghidra 12.1.4 | **missing** | binary is only published on GitHub's release CDN |
| JADX (Android) | **missing** | pinned JAR is only published on GitHub's release CDN |
| IDA | not configured | requires a licensed IDA install plus `REA_IDA_MCP_CONFIG` |
| Kuna | not configured | requires a caller-supplied `kuna` binary |
| JDK 21 | **missing** | Temurin build is on the same blocked CDN; `apt-get` is blocked too |

The egress policy in this sandbox allows `github.com`, `api.github.com`,
`registry.npmjs.org`, and PyPI. GitHub answers metadata requests and returns
`302` redirects for release assets, but the asset host
`release-assets.githubusercontent.com` / `objects.githubusercontent.com` is
unreachable (`SSL_ERROR_SYSCALL`). Every REA engine is distributed there, so
none can be installed from inside the sandbox.

A JDK is not a workaround: `jdk4py` from PyPI is the only reachable JDK package
and it ships a **JRE without `javac`**, which REA's readiness check rejects for
both Ghidra and the JADX bridge.

## What works today

Everything that needs no native engine. Confirmed with a real run:

```bash
rea analyze-javascript-application /path/to/app --json
# → evidence_id ev_..., inventory manifest, Electron boundary graph
```

Available target types: extracted JavaScript/Electron trees and ASAR,
archive/package inventory (ZIP/APK/IPA/MSIX/DMG), Go and Rust binary metadata,
IL2CPP `global-metadata.dat`, Godot PCK, .NET assemblies (static), WASM, EVM
bytecode, SQLite databases, PE resources, HAR/SAZ network captures.

## What does not work yet

- Native decompilation (pseudocode, xrefs, assembly) — needs Ghidra/Hopper/IDA.
- Android code inspection, e.g. of `toffee_atv_apk.apk` — needs JADX plus a JDK.
  Archive inventory of that APK does still work without JADX.

## Finishing the setup

Run on any machine with ordinary internet access:

```bash
bash scripts/setup-rea-toolkit.sh          # installs JDK 21, Ghidra 12.1.4, JADX JAR, CLI
bash scripts/setup-rea-toolkit.sh --check  # report state without changing anything
```

The script pins every version and URL to what REA documents and verifies, checks
the JADX digest against REA's own pinned SHA-256, and writes `/opt/rea-toolkit/env.sh`
plus a `rea` wrapper so non-login shells still see `GHIDRA_INSTALL_DIR`,
`JAVA_HOME`, and `REA_JADX_MCP_JAR`.

If downloads must happen elsewhere, seed `/opt/rea-toolkit/downloads` with the
three archives listed in the script header and run it with `--offline`.

Then re-check providers:

```bash
rea doctor
```

## Environment variables

Set by `/opt/rea-toolkit/env.sh`:

```bash
export GHIDRA_INSTALL_DIR=/opt/rea-toolkit/ghidra_12.1.4_PUBLIC
export JAVA_HOME=/opt/rea-toolkit/jdk-21.0.9+10
export REA_JADX_MCP_JAR=/opt/rea-toolkit/downloads/jadx-headless-mcp-0.7.1-all.jar
```

`rea doctor` (or `binary_session` from an agent) reports any provider that is
still unavailable and the remediation for it.
