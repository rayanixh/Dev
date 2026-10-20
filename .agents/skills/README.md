# Agent skills

Skills in this directory are project-scoped copies installed from upstream sources,
so agents that scan the repository find them without user-level configuration.

| Skill | Source | Version |
| --- | --- | --- |
| `reverse-engineer-anything` | [morluto/rea](https://github.com/morluto/rea) (`rea-agents`) | skill v42, 207 MCP tools |

## Installed copies

- `./reverse-engineer-anything` — project scope (committed here)
- `~/.agents/skills/reverse-engineer-anything` — user scope (`rea setup --skill-client shared`)
- `~/.claude/skills/reverse-engineer-anything` — Claude Code user scope (`rea setup --skill-client claude_code`)

## Refresh

```bash
npx -y rea-agents@latest setup --skill-client shared --yes
npx -y rea-agents@latest setup --skill-client claude_code --yes
cp -R ~/.agents/skills/reverse-engineer-anything .agents/skills/
npx -y rea-agents@latest doctor --skill --json   # expect identity.skill.state = "aligned"
```

## Notes

`rea setup` installs the skill only if you scope it with `--skill-client` /
`--client`. Registering the MCP server (which is what actually exposes REA's
tools to an agent) is a separate step:

```bash
npx -y rea-agents@latest setup --client claude_code --dry-run --json  # review plan
npx -y rea-agents@latest setup --client claude_code --yes             # apply
```

Native binary analysis additionally needs a provider (Hopper, Ghidra via
`GHIDRA_INSTALL_DIR`, or IDA). Android APK inspection needs caller-supplied JADX
plus a full JDK. Static JavaScript/Electron analysis needs neither.

Current install state, verified tooling, and engine setup:
[docs/rea-setup.md](../../docs/rea-setup.md). Install the engines with
`bash scripts/setup-rea-toolkit.sh`.
