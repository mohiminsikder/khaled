# autopilot

A Claude Code skill for unattended runs: plans work into verifiable phases, self-tests
each one, survives usage-limit exhaustion and power loss at near-zero token cost,
rotates between Claude accounts without a terminal prompt, and runs independent phases
in parallel.

- `autopilot/` — the skill (copy to `~/.claude/skills/`)
- `autopilot.skill` — the same, zipped
- `autopilot-skill.zip` — distributable bundle

Start with `autopilot/references/setup.md`, then `references/resilience.md` before the
first overnight run.
