# Test & Fix Report — LockedIn Platform

Date: 2026-06-15
Tested by: automated crawl + functional POST flows + static analysis, run against the local
PHP 8.2 dev server connected to the live Render PostgreSQL database.

---

## Summary

| Area | Result |
|------|--------|
| GET pages crawled (3 accounts) | 207 checks — **0 server errors (500)** after fixes |
| Functional CRUD (post/group/project) | All **PASS** |
| PHPStan static analysis | **0 issues** in `src/` (31 in `tests/` only, non-blocking) |
| Email pipeline | **Proven working** (needs real SMTP creds on Render) |
| Google OAuth | **Correctly wired** (needs real creds + callback URL) |
| Captcha | Graceful (hidden when keys are placeholders) |

---

## Bugs found and FIXED

### 1. `/community/groups` returned HTTP 500 (all users)
**Cause:** The code (`GroupRepository`) queries a table `group_join_request` via raw SQL,
but that table was **never created** — it has no Doctrine entity, so `doctrine:schema:update`
never makes it. The live database was missing it entirely.
**Fix:** Created the table in the database with proper foreign keys + indexes:
```sql
CREATE TABLE group_join_request (
    id SERIAL PRIMARY KEY,
    group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    user_id  INTEGER NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    status   VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_gjr_group_status ON group_join_request (group_id, status);
CREATE INDEX idx_gjr_user_status  ON group_join_request (user_id, status);
```

### 2. Wrong table name in a JOIN (would break "pending join requests" view)
**File:** `src/Repository/GroupRepository.php` (`findPendingRequestsForGroup`)
**Cause:** SQL used `INNER JOIN user u` but the users table is named `app_user`.
**Fix:** Changed `INNER JOIN user u` → `INNER JOIN app_user u`.

### 3. Test account `testuser1@test.com` was locked out
**Cause:** The login authenticator bans non-admin accounts after 3 failed attempts
(`LoginFormAuthenticator::MAX_LOGIN_ATTEMPTS = 3`). Early test runs tripped this.
**Fix:** Reset the account: `is_active=true, is_banned=false, login_attempts=0`.

---

## Investigations (no code bug — configuration needed)

### Email (why password-reset mail never arrived)
- The app code is **correct**. Emails are routed **synchronously** (`messenger.yaml`:
  `SendEmailMessage: sync`), so no background worker is required.
- Proven end-to-end locally with a test SMTP catcher: the reset code was generated and
  the email was sent and received (From `mahdibenmariem1@gmail.com`, correct subject).
- **Root cause on Render:** the `MAILER_DSN` env var is not set to a working SMTP account.
- **To fix on Render → Environment:**
  1. Enable 2FA on the Gmail account `mahdibenmariem1@gmail.com`.
  2. Create a Gmail **App Password** (16 chars).
  3. Set: `MAILER_DSN=smtp://mahdibenmariem1%40gmail.com:APP_PASSWORD@smtp.gmail.com:587`
     (the sender in `EmailService` is hardcoded to this address — the SMTP login should
     match it or Gmail will rewrite/spam the message).

### Google login
- Flow is correct; `/connect/google` already redirects to `accounts.google.com`.
- **Needs on Render:** real `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`, and in Google
  Cloud Console → Credentials → OAuth client → Authorized redirect URIs add:
  `https://lockedin-ylk1.onrender.com/connect/google/callback`

### Captcha
- Renders only when real reCAPTCHA keys are set; cleanly hidden with placeholder keys.
- **Latent caveat (not currently breaking):** the login template decides visibility from
  the **site key**, while `ReCaptchaService` decides verification from the **secret key**.
  If only one is real, login could always-pass or always-fail. Keep both real or both
  placeholder. (Left as-is to avoid risk; flagged for awareness.)

---

## Things verified WORKING (not bugs)
- All `403` responses in the crawl are correct role-based access control:
  non-admins blocked from `/admin/*`; non-investors blocked from investor-only pages;
  only `MENTOR` accounts can open `/mentorat/sessions/new` (admin & entrepreneur get 403
  by design — `MentoratController` line 276 checks `getRole() !== 'MENTOR'`).
- Community post / group / project creation all persist and render.

---

## Local environment set up (so the app runs on this machine)
- Installed PHP 8.2.31 (winget) + enabled extensions: pdo_pgsql, intl, gd, zip, mbstring,
  sodium, fileinfo, openssl, curl; pointed `curl.cainfo`/`openssl.cafile` at a CA bundle.
- Installed Composer (`composer.phar`) and ran `composer install`.
- Created `.env.local` pointing at the Render database; `MAILER_DSN=null://null` for local;
  disabled reCAPTCHA in `config/packages/dev/karser_recaptcha3.yaml`.
- Run the app: `php -S localhost:8000 -t public/`

## Test tooling added (under `scripts/`)
- `smoke_test.py` — logs in as each account, GETs every parameter-less page, reports 5xx.
- `check_page.py` — targeted single-page checker.
- `crud_test.py` — creates a post/group/project and verifies persistence.
- `smtp_catcher.py` — local SMTP server to view outgoing email during testing.

## Files changed in the repo
- `src/Repository/GroupRepository.php` — JOIN table-name fix.
- `config/packages/dev/karser_recaptcha3.yaml` — disable captcha in dev (new).
- `.env.local` — local config (new, gitignored).
- `scripts/*.py` — test tooling (new).
