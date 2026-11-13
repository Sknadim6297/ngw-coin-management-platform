# Coin Locking Maturity Scheduler (Phase 5)

Command:

```bash
php spark coins:check-maturity --limit 100
```

Behavior:
- Finds `active` locks with `matures_at <= now`
- Marks them `matured` (eligible for member unlock)
- Appends immutable `lock.matured` audit events
- **Never** credits coins, ROI, interest, or mining rewards
- Idempotent; uses `ops_job_locks` (`coins_check_maturity`)
- Bounded batches (`--limit`, max 500)

Do **not** enable production scheduling until ops sign-off.

---

## Windows Task Scheduler

1. Task Scheduler → Create Task.
2. General: run whether user is logged on or not; least-privilege service account.
3. Triggers: e.g. hourly.
4. Actions → Start a program:
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `spark coins:check-maturity --limit 100`
   - Start in: `C:\xampp\htdocs\coin-platform`
5. Settings: stop if longer than 10 minutes; do not start a new instance if one is running.

## Plesk scheduled tasks

```text
C:\Program Files (x86)\Plesk\Additional\PleskPHP82\php.exe C:\inetpub\vhosts\example.com\httpdocs\coin-platform\spark coins:check-maturity --limit 100
```

Set working directory to the CodeIgniter project root.

## Verification

```bash
php spark coins:check-maturity --limit 10
```

Expect `scanned=` / `matured=` output and the note that no rewards were generated.
