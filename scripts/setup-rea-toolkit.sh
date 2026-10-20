#!/usr/bin/env bash
# Sets up the complete REA (Reverse Engineer Anything) toolchain on Linux x86_64:
#   - global `rea` CLI          (npm rea-agents, pinned)
#   - JDK 21                    (required by Ghidra and by the JADX bridge)
#   - Ghidra 12.1.4 PUBLIC      (native binary provider)
#   - jadx-headless-mcp 0.7.1   (Android APK provider, SHA-256 pinned by REA)
#   - the bundled agent skill   (see .agents/skills/)
#
# Every version and URL below is pinned to what morluto/rea documents and
# verifies (docs/installation.md, docs/android-analysis.md). Re-running is safe:
# completed steps are detected and skipped.
#
# Usage:
#   bash scripts/setup-rea-toolkit.sh                 # install everything
#   bash scripts/setup-rea-toolkit.sh --check         # report state, change nothing
#   bash scripts/setup-rea-toolkit.sh --no-cli        # skip npm install
#   bash scripts/setup-rea-toolkit.sh --offline       # only use pre-seeded archives
#
# Engines land in $REA_TOOLKIT_ROOT (default /opt/rea-toolkit). That path is
# outside the repository on purpose: Ghidra alone is ~1.2 GB unpacked with tens
# of thousands of files, so it must never be committed.
#
# Offline seeding: drop these files into $REA_TOOLKIT_ROOT/downloads and re-run
# with --offline. Checksums are still verified.
#   OpenJDK21U-jdk_x64_linux_hotspot_21.0.9_10.tar.gz  (+ .sha256.txt)
#   ghidra_12.1.4_PUBLIC_20260921.zip
#   jadx-headless-mcp-0.7.1-all.jar
set -euo pipefail

ROOT="${REA_TOOLKIT_ROOT:-/opt/rea-toolkit}"
REA_VERSION="${REA_VERSION:-6.7.0}"
INSTALL_CLI=true
OFFLINE=false
CHECK_ONLY=false

JDK_TAG="jdk-21.0.9+10"
JDK_FILE="OpenJDK21U-jdk_x64_linux_hotspot_21.0.9_10.tar.gz"
JDK_DIR="jdk-21.0.9+10"
JDK_URL="https://github.com/adoptium/temurin21-binaries/releases/download/${JDK_TAG}/${JDK_FILE}"

GHIDRA_TAG="Ghidra_12.1.4_build"
GHIDRA_FILE="ghidra_12.1.4_PUBLIC_20260921.zip"
GHIDRA_DIR="ghidra_12.1.4_PUBLIC"
GHIDRA_URL="https://github.com/NationalSecurityAgency/ghidra/releases/download/${GHIDRA_TAG}/${GHIDRA_FILE}"

JADX_FILE="jadx-headless-mcp-0.7.1-all.jar"
JADX_URL="https://github.com/1013503897/jadx-headless-mcp/releases/download/v0.7.1/${JADX_FILE}"
# Pinned in REA's src/android/JadxRelease.ts; REA refuses any other digest.
JADX_SHA256="6e5eacf500b64292bfb73c49797c1958f6ee44646e43e868039ae7feb573ff75"

while (($# > 0)); do
  case "$1" in
    --root) ROOT="$2"; shift 2 ;;
    --rea-version) REA_VERSION="$2"; shift 2 ;;
    --no-cli) INSTALL_CLI=false; shift ;;
    --offline) OFFLINE=true; shift ;;
    --check) CHECK_ONLY=true; shift ;;
    -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done

CACHE="$ROOT/downloads"
SUDO=""
as_root() { if [ "$(id -u)" = 0 ]; then "$@"; else $SUDO "$@"; fi; }
log() { printf '\n==> %s\n' "$*"; }
have_jdk() { [ -x "$ROOT/$JDK_DIR/bin/java" ] && [ -x "$ROOT/$JDK_DIR/bin/javac" ]; }
have_ghidra() { [ -f "$ROOT/$GHIDRA_DIR/support/analyzeHeadless" ]; }
have_jadx() { [ -f "$CACHE/$JADX_FILE" ] && [ "$(sha256sum "$CACHE/$JADX_FILE" | cut -d' ' -f1)" = "$JADX_SHA256" ]; }

# --- --check ---------------------------------------------------------------------
if [ "$CHECK_ONLY" = true ]; then
  printf '%-10s %-7s %s\n' COMPONENT STATE DETAIL
  if command -v rea >/dev/null 2>&1; then
    printf '%-10s %-7s %s\n' rea OK "v$(rea --version 2>/dev/null | tail -1) at $(command -v rea)"
  else
    printf '%-10s %-7s %s\n' rea MISSING "npm install --global rea-agents@$REA_VERSION"
  fi
  if have_jdk; then
    printf '%-10s %-7s %s\n' jdk OK "\"$("$ROOT/$JDK_DIR/bin/javac" -version 2>&1)\" at $ROOT/$JDK_DIR"
  else
    printf '%-10s %-7s %s\n' jdk MISSING "needs JDK 21 with javac (a JRE-only build is rejected by REA)"
  fi
  if have_ghidra; then
    printf '%-10s %-7s %s\n' ghidra OK "$ROOT/$GHIDRA_DIR"
  else
    printf '%-10s %-7s %s\n' ghidra MISSING "$GHIDRA_URL"
  fi
  if have_jadx; then
    printf '%-10s %-7s %s\n' jadx.jar OK "$CACHE/$JADX_FILE"
  else
    printf '%-10s %-7s %s\n' jadx.jar MISSING "$JADX_URL"
  fi
  printf '%-10s %-7s %s\n' skill "$( [ -f "$ROOT/skill/SKILL.md" ] || [ -f /home/user/.agents/skills/reverse-engineer-anything/SKILL.md ] && echo OK || echo MISSING)" \
    "npx -y rea-agents@$REA_VERSION setup --skill-client shared --yes"
  exit 0
fi

# --- prerequisites ---------------------------------------------------------------
log "Checking prerequisites"
for cmd in curl unzip tar sha256sum node npm; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "missing required command: $cmd" >&2; exit 1; }
done
[ "$(uname -s)" = Linux ] && [ "$(uname -m)" = x86_64 ] || {
  echo "this script targets Linux x86_64 (Ghidra/JADX ship x86_64 native bridge bits)" >&2; exit 1; }

node_major="$(node -p 'process.versions.node.split(".")[0]')"
node_minor="$(node -p 'process.versions.node.split(".")[1]')"
if ! { (( node_major == 22 && node_minor >= 19 )) || (( node_major == 24 && node_minor >= 11 )) || (( node_major >= 26 )); }; then
  echo "REA needs Node 22.19+, 24.11+, or 26+; found $(node -v)" >&2; exit 1
fi

if ! mkdir -p "$ROOT" 2>/dev/null || [ ! -w "$ROOT" ]; then
  SUDO="sudo -n"
  $SUDO true 2>/dev/null || { echo "$ROOT is not writable and passwordless sudo is unavailable; pass --root" >&2; exit 1; }
  as_root mkdir -p "$ROOT"
fi
as_root mkdir -p "$CACHE"
[ -w "$ROOT" ] || SUDO="sudo -n"

# GitHub serves release binaries from a separate CDN host that is commonly
# blocked by egress allowlists even when github.com itself is reachable. Detect
# that up front instead of failing mid-download with an opaque TLS error.
cdn_reachable() {
  local code
  code="$(curl -sSL -o /dev/null -w '%{http_code}' --max-time 20 \
    --connect-timeout 10 "https://github.com/1013503897/jadx-headless-mcp/releases/download/v0.7.1/jadx-headless-mcp.sha256" \
    2>/dev/null || true)"
  [ "$code" = "200" ]
}
if [ "$OFFLINE" = false ] && ! { have_jdk && have_ghidra && have_jadx; }; then
  if ! cdn_reachable; then
    cat >&2 <<'EOF'
ERROR: GitHub release downloads are blocked in this environment.

github.com resolves and answers redirects (302), but the asset host
release-assets.githubusercontent.com / objects.githubusercontent.com is not
reachable. Ghidra, the Temurin JDK and the JADX JAR are only published there,
so no provider can be installed from inside this sandbox.

Run this script on a machine with ordinary internet access, or seed the cache:

  mkdir -p /opt/rea-toolkit/downloads
  # fetch the three files listed at the top of this script there, then:
  bash scripts/setup-rea-toolkit.sh --offline

The `rea` CLI and the agent skill install fine without release downloads
(npm registry and PyPI are reachable).
EOF
    exit 3
  fi
fi

# fetch URL DEST [EXPECTED_SHA256]
fetch() {
  local url="$1" dest="$2" sha="${3:-}"
  if [ -f "$dest" ]; then
    if [ -z "$sha" ] || [ "$(sha256sum "$dest" | cut -d' ' -f1)" = "$sha" ]; then
      echo "    using cached $(basename "$dest")"; return 0
    fi
    echo "    checksum mismatch on cached $(basename "$dest"); refetching"
    rm -f "$dest"
  fi
  if [ "$OFFLINE" = true ]; then
    echo "    MISSING and --offline was given: $(basename "$dest")" >&2; exit 4
  fi
  echo "    downloading $(basename "$dest")"
  curl -fL --proto '=https' --tlsv1.2 --retry 3 --retry-delay 2 \
    --connect-timeout 20 -o "$dest.part" "$url"
  mv "$dest.part" "$dest"
  if [ -n "$sha" ]; then
    local got; got="$(sha256sum "$dest" | cut -d' ' -f1)"
    [ "$got" = "$sha" ] || { echo "SHA-256 mismatch for $dest: got $got" >&2; rm -f "$dest"; exit 1; }
    echo "    sha256 verified"
  fi
}

# --- JDK 21 --------------------------------------------------------------------
log "JDK 21 (Temurin ${JDK_TAG#jdk-})"
if have_jdk; then
  echo "    already installed"
else
  fetch "${JDK_URL}.sha256.txt" "$CACHE/$JDK_FILE.sha256.txt"
  expected="$(cut -d' ' -f1 "$CACHE/$JDK_FILE.sha256.txt")"
  fetch "$JDK_URL" "$CACHE/$JDK_FILE" "$expected"
  as_root tar -xzf "$CACHE/$JDK_FILE" -C "$ROOT"
fi
"$ROOT/$JDK_DIR/bin/java" -version 2>&1 | sed 's/^/    /'
"$ROOT/$JDK_DIR/bin/javac" -version 2>&1 | sed 's/^/    /'

# --- Ghidra --------------------------------------------------------------------
log "Ghidra ${GHIDRA_DIR#ghidra_}"
if have_ghidra; then
  echo "    already installed"
else
  fetch "$GHIDRA_URL" "$CACHE/$GHIDRA_FILE"
  # Ghidra publishes no checksum file; verify archive integrity instead.
  unzip -tqq "$CACHE/$GHIDRA_FILE" >/dev/null || { echo "corrupt Ghidra zip" >&2; exit 1; }
  as_root unzip -qq "$CACHE/$GHIDRA_FILE" -d "$ROOT"
fi
grep -E '^application\.java\.(min|max)=' "$ROOT/$GHIDRA_DIR/Ghidra/application.properties" | sed 's/^/    /'

# --- JADX headless MCP JAR -----------------------------------------------------
log "jadx-headless-mcp 0.7.1"
if have_jadx; then
  echo "    already installed"
else
  fetch "$JADX_URL" "$CACHE/$JADX_FILE" "$JADX_SHA256"
fi

# --- environment ---------------------------------------------------------------
ENV_FILE="$ROOT/env.sh"
log "Writing $ENV_FILE"
as_root tee "$ENV_FILE" >/dev/null <<EOF
# REA toolchain environment (generated by scripts/setup-rea-toolkit.sh)
export REA_TOOLKIT_ROOT="$ROOT"
export GHIDRA_INSTALL_DIR="$ROOT/$GHIDRA_DIR"
export JAVA_HOME="$ROOT/$JDK_DIR"
export REA_JADX_MCP_JAR="$CACHE/$JADX_FILE"
export PATH="\$JAVA_HOME/bin:\$REA_TOOLKIT_ROOT/bin:\$PATH"
EOF
as_root ln -sf "$ENV_FILE" /etc/profile.d/rea-toolkit.sh 2>/dev/null || true

# --- CLI -----------------------------------------------------------------------
if [ "$INSTALL_CLI" = true ]; then
  log "rea CLI (rea-agents@$REA_VERSION)"
  npm install --global "rea-agents@$REA_VERSION" 2>&1 | tail -3 | sed 's/^/    /'
fi

# A wrapper keeps the provider settings working in non-login shells, which is
# the usual case for agent tool calls: those never source /etc/profile.
REA_ENTRY="$(npm root -g)/rea-agents/scripts/rea.mjs"
BIN_DIR="$ROOT/bin"
as_root mkdir -p "$BIN_DIR"
as_root tee "$BIN_DIR/rea" >/dev/null <<EOF
#!/bin/sh
. "$ENV_FILE"
exec node "$REA_ENTRY" "\$@"
EOF
as_root chmod +x "$BIN_DIR/rea"
as_root ln -sf "$BIN_DIR/rea" /usr/local/bin/rea

# --- verify --------------------------------------------------------------------
log "Verifying providers"
set +e
. "$ENV_FILE"
node "$REA_ENTRY" doctor --json | node -e '
let s="";process.stdin.on("data",c=>s+=c).on("end",()=>{
  const d=JSON.parse(s);
  for(const p of d.providerInspections||[]){
    const bad=(p.checks||[]).filter(c=>!c.ok);
    console.log(`    ${p.available?"OK  ":"FAIL"}  ${p.id}${bad.length?": "+bad[0].detail:" " }`);
  }
  console.log(`    skill: ${d.identity?.skill?.state??"unknown"} (v${d.identity?.skill?.installed_version??"?"})`);
});'
set -e

cat <<EOF

Done. To use this toolchain in a new shell:
  . $ENV_FILE

Or just call \`rea\` (the wrapper sets the environment for you):
  rea doctor
  rea inspect-android-package /path/to/app.apk --json
EOF
