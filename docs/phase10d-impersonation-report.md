# Phase 10D — Secure Admin Login as Member

**Date:** 2026-11-17  
**Status:** Implemented (safe isolation). **Not deployed.** Financial kill-switches untouched.

## 1. Architecture

Path-scoped **support-view** isolation:

- Admin Shield session (`ci_session`) remains the Admin identity on all tabs.
- A separate HttpOnly cookie `ngw_sv` is scoped to path `/support-view` (plus app base path if any).
- Impersonated member UI is served only under `/support-view/*`.
- Member data is loaded by **target user id** from the impersonation session — Shield is never swapped to the member.

Flow:

1. Admin POST `/admin/members/{id}/impersonate` (+ CSRF + admin password reauth)  
2. Server creates single-use grant (60s, hashed token)  
3. New-tab launch page auto-POSTs grant token to `/support-view/start`  
4. Grant consumed → active session cookie set → redirect `/support-view/dashboard`  
5. Admin tab stays on `/admin/*` with unchanged Shield session

## 2. Admin button location

- `/admin/members` table Actions: **View** + **Login as Member** (authorized only)
- `/admin/members/{id}` profile header: **Login as Member**
- Modal requires admin password; form `target="_blank"`

## 3. Impersonation routes

| Method | Route | Purpose |
|--------|-------|---------|
| POST | `/admin/members/{id}/impersonate` | Create grant (admin filter) |
| POST | `/support-view/start` | Claim grant, set cookie |
| POST | `/support-view/end` | End session, clear cookie |
| GET | `/support-view/ended` | Safe ended page |
| GET | `/support-view/dashboard|profile|purchases|…` | Read-only portal |

No GET grant creation. No long-lived tokens in URLs.

## 4. Session isolation

| Cookie | Path | Holds |
|--------|------|-------|
| `ci_session` | `/` | Admin Shield login (unchanged) |
| `ngw_sv` | `/support-view` | Impersonation session token (hashed at rest) |

Admin `/admin` tabs do not receive `ngw_sv`. Normal `/member` routes do not receive `ngw_sv`.

## 5. Permission checks

- Permission: `admin.members.impersonate` (covered by `admin.*` for Administrator group)
- Finance roles do **not** receive this permission
- Target must be a `member` group user; not admin/finance; not root sponsor; not self
- Live admin session + permission re-checked on every support-view request
- Rate limit: 20 grants / admin / hour
- Reauth: admin password required per grant

## 6. Read-only restrictions

Server-side `SupportViewFilter`:

- Blocks non-GET/HEAD except POST `start` / `end`
- Mutating member actions under `/support-view` redirect with error
- UI hides Buy Coins / Withdrawals / Security / Logout; shows End Member View
- Persistent **ADMIN VIEW** banner

## 7. Audit logging

Actions (actor = Admin user id; never labeled as member activity):

- `admin.impersonation_requested`
- `admin.impersonation_started`
- `admin.impersonation_member_viewed`
- `admin.impersonation_ended`
- `admin.impersonation_expired`
- `admin.impersonation_denied`

Metadata includes target member id, session/grant refs, IP/UA via AuditLogger. Raw tokens are never logged.

## 8. Files changed / added

- Migration `2026-11-17-960001_Phase10DAdminImpersonation.php`
- `ImpersonationService`, `SupportViewFilter`
- Controllers: `Admin\ImpersonationController`, `SupportView\SessionController`, `SupportView\PortalController`
- Views: members index/show, launch, ended; `layouts/member.php` support mode
- Config: Routes, Filters, AuthGroups, Services
- Logout hooks revoke active support sessions
- Tests: `Phase10DImpersonationTest.php`
- Docs: this report

## 9. Database migrations

Tables:

- `admin_impersonation_grants`
- `admin_impersonation_sessions`

## 10. Focused test results

`Phase10DImpersonationTest` — **11 passed** (coverage driver warning only).

Covered: authorized grant, unauthorized finance/member, admin/root blocked, invalid target, expired/reused grant, admin session intact + scoped data, mutating POST blocked, end/revoke, HTTP launch, cookie path.

## 11. Manual browser verification

Not executed in this automation pass. Verify locally:

1. Login Admin → Members → Login as Member → password → new tab dashboard  
2. Confirm Admin tab still Admin  
3. Confirm banner + End Session  
4. Confirm POST actions blocked  
5. Logout Admin → support-view access revoked

## 12. Security limitations

- Shared Shield cookie across tabs is intentional; isolation relies on **not** swapping user + path-scoped support cookie
- Profile HTML may still render edit forms; POSTs are blocked server-side
- `forceGlobalSecureRequests=false` locally → Secure cookie flag follows HTTPS detection
- Idle 15m / max 30m; grant TTL 60s
- Production should enable HTTPS so `Secure` cookie flag is always on

## 13. Deployment requirements

- Run migration `2026-11-17-960001`
- Confirm only Administrator (or explicitly granted) roles have `admin.members.impersonate`
- Prefer HTTPS in production
- Do **not** enable financial kill-switches for this phase

## FINAL ACCEPTANCE

| Criterion | Status |
|-----------|--------|
| Login as Member button | YES |
| New tab member panel | YES (`/support-view`) |
| Original Admin stays logged in | YES (no Shield swap) |
| Correct member data | YES (target-scoped) |
| Financial/sensitive actions blocked | YES (server filter) |
| End Member View | YES |
| No session collision | YES (path-scoped isolation) |

**Verdict:** Phase 10D acceptance criteria met with secure isolation (not insecure session swapping). **DO NOT DEPLOY** as a financial activation.
