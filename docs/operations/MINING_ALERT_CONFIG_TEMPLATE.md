# Mining alert configuration template (Phase 13C)

**Template only.** No recipient, address, webhook or channel is configured, and none may be invented. Alerts today are LOCAL:
`writable/logs/mining-post-alerts.log`, the CI4 error log and the in-process event `mining_post_alert`. Nothing is sent externally.

## Alert classes (raised by `MiningPostAlertService`, mock-tested in Phase 13C)

| Failure | Alert code | Scheduler exit | Severity (proposed) |
|---|---|---:|---|
| Missed scheduled run | `missing_scheduled_run` / `stale_running_day_run` / `day_run_not_completed` (watchdog `checkScheduledRun`) | n/a | HIGH |
| Failed run | `nonzero_exit` | 50 | HIGH |
| Partial run | `partial_run` | 40 | HIGH |
| Missing approved rate | `missing_rate` | 30 | HIGH |
| Missing historical evidence | `blocked_historical_data` | 40 | HIGH |
| Reconciliation mismatch | `reconciliation_failed` | 40 | CRITICAL |
| Duplicate invocation / lock contention | `lock_contention` | 20 | MEDIUM |
| Timeout | `job_timeout` | 40 | HIGH |
| Excessive retry | `excessive_retry` | 0/40 | MEDIUM |
| Long runtime | `long_runtime` | any | MEDIUM |
| Database loss | `database_connection_failure` | 50 | CRITICAL |

Normal outcomes (exit 0, 10, 20) stay silent.

## Destination template (fill in only after business approval)

```yaml
mining_alerts:
  enabled: false                      # keep false until approved
  channel: PENDING_BUSINESS_APPROVAL  # e.g. e-mail | admin-notification | webhook
  recipients:
    - PLACEHOLDER_NOT_CONFIGURED
  severity_routing:
    CRITICAL: PLACEHOLDER_NOT_CONFIGURED
    HIGH: PLACEHOLDER_NOT_CONFIGURED
    MEDIUM: PLACEHOLDER_NOT_CONFIGURED
  watchdog:
    check_business_date: previous-completed-day
    check_after_local_time: PENDING_BUSINESS_APPROVAL   # Asia/Kolkata
```

Wiring a real channel = listen to the `mining_post_alert` event (`app/Config/Events.php`); a separate, explicitly authorized step.
