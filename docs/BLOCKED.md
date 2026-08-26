# Blocked

Written by the agent during an unattended run. One entry per task that could not be completed.

**A blocked task never halts the run** — its partial work is reverted, an entry is written here, and
the run moves to the next task. This file is what gets read afterwards.

Format:

```
## <task id> — <title>
**Date**      when
**Attempted** what was tried
**Error**     the exact message or failing check label
**Needs**     what a human must decide or supply
**Reverted**  yes / no, and what was left behind if no
```

_(empty — nothing blocked yet)_
