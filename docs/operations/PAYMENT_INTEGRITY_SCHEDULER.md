# Payment Ops Schedulers (Phase 4D–4E)

Commands:

```bash
php spark payment:integrity-monitor --limit 50
php spark payment:deliver-alerts --limit 25
```

Integrity monitor:
- Bounded batch scans, DB lock, raises/dedupes internal financial alerts
- **Never** mutates inventory, ownership, allocations, or deposits

Alert delivery:
- Processes `notification_outbox` with retries/backoff
- **No-op** unless `financeNotifications.emailEnabled`, recipients, and Email SMTP/`fromEmail` are configured
- Recipients stored redacted; secrets never logged

Do **not** configure production scheduling until ops sign-off.

---

## Windows Task Scheduler

1. Open Task Scheduler → Create Task.
2. General: run whether user is logged on or not; use a least-privilege service account that can read the app and write logs.
3. Triggers: e.g. every 15 minutes.
4. Actions → Start a program:
   - Program: `C:\xampp\php\php.exe` (adjust path)
   - Arguments: `spark payment:integrity-monitor --limit 50`
   - Start in: `C:\xampp\htdocs\coin-platform`
5. Settings: stop if runs longer than 10 minutes; do not start a new instance if one is running (app also uses `ops_job_locks`).

## Plesk scheduled tasks

1. Domains → Scheduled Tasks → Add Task.
2. Task type: Run a PHP script **or** Run a command.
3. Command example:

```text
C:\Program Files (x86)\Plesk\Additional\PleskPHP82\php.exe C:\inetpub\vhosts\example.com\httpdocs\coin-platform\spark payment:integrity-monitor --limit 50
```

4. Set working directory to the CodeIgniter project root.
5. Schedule conservatively (15–60 minutes).

## IIS / app pool notes

- Ensure the task identity can read `.env` and write `writable/logs`.
- PHP `intl`, `curl`, `bcmath` must be enabled for the CLI SAPI as well as the web SAPI.
- Recycle of the IIS app pool does **not** stop Task Scheduler jobs.

## Alert delivery task (optional)

Only schedule after SMTP + `financeNotifications.*` are explicitly configured:

```text
Program: C:\xampp\php\php.exe
Arguments: spark payment:deliver-alerts --limit 25
Start in: C:\xampp\htdocs\coin-platform
```

Idempotent: dedupe keys, attempt caps, exponential backoff, `ops_job_locks`, redacted recipient/error logs.

## Reconciliation reporting

```bash
php spark payment:integrity-monitor --limit 50
```

Review Admin → Finance Overview for pending investigations, reserved/consumed/released allocations, remediation proposals, and notification delivery failures. No automatic ledger repair.

## Verification

```bash
php spark payment:integrity-monitor --limit 10
php spark payment:deliver-alerts --limit 5
```

Expect CLI output with `no_ledger_mutation` semantics. Alert delivery is a no-op when email is disabled. Alerts appear under Admin → Financial Alerts.
