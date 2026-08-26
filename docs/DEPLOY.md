# Deploying Counter to the live server

Unattended runs need to reach the server without a human typing anything. That means **SSH keys**,
not a password.

## Why not the password

A password cannot be used unattended without one of two bad options: an interactive prompt (which
hangs the run — exactly what `BatchMode=yes` exists to prevent), or the password stored in plaintext
somewhere the automation can read it. Storing it in the repository is worse still: **git history is
permanent**, so deleting the file later does not remove the secret, and public-facing repositories
are scraped for credentials continuously.

A key solves all of it: no prompt, nothing secret in the repo, and it can be revoked on its own
without changing anything else.

**If a password was ever pasted into a chat, a ticket, or a commit — rotate it.** Assume it is
compromised, because you cannot un-send it.

## 1. One-time setup

Generate a key **used only for this deploy**, so it can be revoked without touching your login:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/counter_deploy -C "counter-deploy" -N ""
```

Install the public half on the server. This is the one and only time the password is used —
interactively, by you, never stored:

```bash
ssh-copy-id -i ~/.ssh/counter_deploy.pub -p 65002 u477370720@82.180.152.46
```

If `ssh-copy-id` is unavailable, append `~/.ssh/counter_deploy.pub` to `~/.ssh/authorized_keys` on
the server by hand — Hostinger also accepts a public key pasted into hPanel → Advanced → SSH Access.

Confirm it works without a prompt:

```bash
ssh -i ~/.ssh/counter_deploy -p 65002 -o BatchMode=yes u477370720@82.180.152.46 'echo ok'
```

`BatchMode=yes` is the real test: it disables every prompt, so it succeeds only if the key alone is
enough. **Then rotate the account password** — nothing needs it any more.

## 2. Configure

```bash
cp .env.deploy.example .env.deploy
```

Fill in host, port, user, WordPress path and key path. `.env.deploy` is gitignored and must stay
that way. `git check-ignore -v .env.deploy` will confirm it.

The example file has no password variable on purpose.

## 3. Use

```bash
scripts/deploy.sh status     # plugin version, WP version, disk
scripts/deploy.sh pull       # live plugin -> ./live/ for inspection
scripts/deploy.sh push       # ./counter/ -> server, backing up first
scripts/deploy.sh selftest   # run the suite remotely; non-zero exit on failure
```

`push` copies the existing plugin to `counter.bak-<timestamp>` before writing, and prints the
rollback command. `tests/` is excluded — it never ships.

`selftest` needs **task A0** in `COUNTERV2.md` (`wp counter selftest`). Until A0 lands the suite is
reachable only from the Health screen in a browser, which an unattended run cannot use.

## 4. In an unattended run

Per `COUNTERV2.md` §0.2:

```bash
scripts/deploy.sh push && scripts/deploy.sh selftest
```

Non-zero from `selftest` means **do not proceed to the next task**. Roll back with the command
`push` printed, record it in `docs/BLOCKED.md`, and move on.

Two things the run must never do: deploy without a passing local suite first, and deploy during shop
hours without saying so in the summary. A failed deploy on a live till stops the shop selling.

## 5. If a key is lost or the server is rebuilt

Remove the public key from `~/.ssh/authorized_keys` on the server, delete the local pair, and repeat
§1. Nothing else in the repo changes — the key is the only secret, and it lives outside the repo.
