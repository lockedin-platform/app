# LockedIn — Security & Performance Audit

Date: June 2026. Scope: code + config + deploy. Anything **FIXED** is already applied & verified.

---

## Security

### Fixed
- **Info-disclosure debug route removed.** `DebugController` exposed `/debug-me`, echoing the
  logged-in user's email + roles + internal role field. Pure dev leftover → **file deleted.**
- **Upload size limit added.** Community image upload had no size cap (disk-fill / DoS risk).
  Added a **5 MB** limit in `CommunityController::storePostUpload` (on top of the existing real
  MIME check + random filename).
- **Admin course upload hardened.** Added `isValid()` check + **20 MB** size cap in
  `AdminApprentissageController::handleDocumentUpload` (it already validated MIME).
- **Security headers added on every response** (`SecurityHeadersSubscriber`): `X-Content-Type-Options:
  nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`.
  Verified live. (CSP/Permissions-Policy intentionally NOT forced — would break inline styles, CDN
  assets, and the Face-ID camera; introduce deliberately later.)

### Verified safe (no action needed)
- **No SQL injection.** All raw SQL (`GroupRepository`, `MlEventLogger`) uses bound parameters
  (`:name`), never string interpolation.
- **No XSS via `|raw`.** Every `|raw` in templates is the safe `|json_encode|raw` pattern (correct
  JS-context escaping). The only plain `|raw` is the admin-composed email broadcast body
  (intentional rich HTML, admin-only) — acceptable. No user-controlled data is rendered raw in the browser.
- **No hardcoded secrets** in `src/`/`config/`; **no secrets committed** in `.env`.
- **No debug leftovers** (`dd()`/`dump()`/`var_dump()`) in `src/`.
- **Prod runs `APP_ENV=prod`** (set in the Dockerfile CMD) — dev profiler/stack traces are NOT
  exposed in production. (The `APP_ENV=dev` in committed `.env` is just the Symfony default,
  overridden at runtime.)
- **Uploads** validate the real MIME type (`image/*`) and store under a generated random name
  (no path traversal from client filename).

### Added
- **`/health` endpoint** (`HealthController`) — public, fast, DB-ping; 200 healthy / 503 if DB down.
  Point Render's health check at `/health` (instead of `/`, which does real work).
- **`.gitignore` hardened** — now ignores `__pycache__`, `.venv/`, `composer.phar`, `cacert.pem`.

### Recommended (not blocking)
- **Rotate the Render DB password** — shared in plaintext repeatedly; treat as compromised.
- **Run `composer audit` in CI / on the server.** It's blocked from this machine by a local
  SSL/firewall (Avast) cert issue, so the dependency-CVE scan couldn't complete here.
- **Rate-limit the AI + login endpoints.** `globalAiChat` / `chatbotAsk` call external LLM APIs with
  no throttle (cost/abuse risk); login could use IP throttling. Needs `composer require symfony/rate-limiter`
  (not installed; install blocked locally by the same SSL issue) → do it on a clean network.
- **Admin course upload** (`AdminApprentissageController`) — admin-gated, but add the same
  size + MIME validation as the community upload for consistency.
- **Login lockout trade-off** — `LoginFormAuthenticator` bans an account after 3 failed attempts.
  Good against brute force, but enables a mild **account-lockout DoS** (an attacker can lock a
  known victim by failing 3×). Consider IP-based throttling instead of/with account banning.

---

## Performance / latency

### Fixed
- **OPcache was OFF in production.** The app is served by `php -S` (CLI SAPI), where OPcache is
  disabled by default (`opcache.enable_cli=0`) — so PHP **recompiled every file on every request.**
  Added `docker/opcache.ini` (`enable_cli=1`, `validate_timestamps=0`, tuned memory + realpath
  cache) and wired it into the Dockerfile. **Real, immediate latency win on the current setup.**

### Verified clean
- **Foreign-key indexes:** audited all FK columns in `public` — **0 unindexed** (Doctrine already
  indexes relation columns), so joins/lookups aren't missing indexes.

### Recommended (the big one) — now PREPARED
- **🔴 Replace `php -S` as the production server.** The built-in PHP dev server is
  **single-threaded** — it handles **one request at a time**, so any concurrency queues up. This is
  the main reason the deployed site felt "very slow."
  **→ `Dockerfile.frankenphp` is now ready** (separate file, working Dockerfile untouched): build &
  test it, then point Render at it. FrankenPHP gives real concurrency + OPcache + optional worker
  mode. Highest-impact perf change available; do it before real traffic.
- **Stop `doctrine:schema:update --force` on every container start.** It runs on each boot
  (slow startup) and can silently alter the schema (drift risk). Use **Doctrine migrations** and run
  them as a one-off deploy step, not in the server CMD.

### Opportunity
- **`EconomicApiService` calls external APIs per request** (World Bank + FX) — slow and can fail
  (it timed out for us). For the macro indicators, the new **`MacroRiskService` reads the cached
  `ml_data.worldbank_wide`** instead — faster + reliable. Point the remaining economic-risk callers
  at it; keep the live API only for the genuinely real-time FX rate.

---

## Round 2 — deep hardening pass (all FIXED & verified)
- **🔴 Insecure TLS removed everywhere.** ~10 outbound calls had `verify_peer => false` /
  `verify_host => false` hardcoded — including AI calls that send the **API key in the
  Authorization header** (Gemini, Groq via `AvisRatingService`/`CoursQuizService`/
  `InvestmentChatbotService`, audio transcription in `MentoratController`), plus World Bank/FX
  and profanity calls. All now route through `App\Service\Support\Tls::verify()` —
  **verification ON by default**, disabled only if the explicit `COMMUNITY_GROQ_INSECURE`
  dev flag is set. Prod is now MITM-safe; no key/data sent over an unverified channel.
- **CSRF hardened at the cookie level.** Most forms (Projet, Community) had no CSRF token.
  Set the session cookie to `SameSite=Lax` + `Secure=auto` + `HttpOnly` in `framework.yaml` —
  Lax blocks the session cookie on cross-site POST, defeating classic CSRF across **all** forms
  at once (and HttpOnly hardens against cookie theft via XSS).
- **AI endpoints rate-limited.** All **6** chatbot/AI endpoints (global, project, team-matcher,
  investment, investment-risk, profile) now use `SimpleRateLimiter` (cache-based, no new package):
  15–20 req/min per IP/user → returns 429. Verified live (16th request blocked).
- **Access control verified.** All 8 admin controllers carry `ROLE_ADMIN`, AND `security.yaml`
  has a global `^/admin → ROLE_ADMIN` rule (defense in depth). Ownership checks present on
  project/offer/mentorat actions.
- **Caching status:** `ExchangeRateService` + `NewsApiService` cache (1h). `EconomicApiService`
  is superseded by the cached `MacroRiskService`. Weather/translation use secure-first + cert
  fallback. (Adding cache to the remaining live calls is a follow-up latency win.)

## Code quality (minor, noted not fixed)
Dead code flagged in `InvestmentController`: unused private methods `buildActivityTicker`,
`redirectAfterOfferAction`; unused vars `$engine`, `$contractRepo`; one unnecessary `use`. Harmless
(no security/perf impact) — clean up opportunistically.
