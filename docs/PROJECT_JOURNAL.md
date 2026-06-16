# LockedIn — Project Journal

A complete, chronological record of everything done on this machine: from pulling the
repo, getting it running locally, testing & fixing the platform, collecting the Phase-1
training data, loading it into the database, and aligning the app + data so the ML models
(Strategy Report, Phase 2) can actually work and **auto-learn from the live site**.

> Roles (from the Strategy Report): **Dali** = technical architecture / ML / infra (this work).
> **Mahdi** = strategy/investors. **Elyes** = BD/ops + the *Tunisian* data sources (first table).
> This journal covers Dali's half: the **African/MENA + Kaggle/Crunchbase** data and the platform/ML plumbing.

---

## Table of contents
1. Environment — getting the repo running locally
2. Bugs found & fixed
3. Integrations investigated (email, captcha, Google)
4. Test tooling added
5. Phase-1 data collection (external sources)
6. Consolidation into model-ready tables
7. Loading into PostgreSQL (`ml_data` schema)
8. The feature-alignment problem (and the fix)
9. App + table changes (collect the right inputs)
10. ML auto-learn foundation (log everything from day one)
11. Code quality pass
12. Open items & security
13. File/script index

---

## 1. Environment — getting the repo running locally
The machine had **PHP 5.4**; the app needs **8.2+**. Steps taken:
- Installed **PHP 8.2.31** (winget) and enabled extensions: `pdo_pgsql, intl, gd, zip, mbstring,
  sodium, fileinfo, openssl, curl`; set `extension_dir` + pointed `curl.cainfo`/`openssl.cafile`
  at a CA bundle (`cacert.pem`).
- Installed **Composer** (`composer.phar`); worked around SSL ("unable to get local issuer
  certificate") via the CA bundle + `composer config --global secure-http false`; ran
  `composer install` **with** dev dependencies (dev mode needs DebugBundle).
- Created **`.env.local`** (gitignored) pointing `DATABASE_URL` at the Render PostgreSQL DB,
  `APP_ENV=dev`, `MAILER_DSN=null://null`, captcha disabled in dev.
- Run command: `php -S localhost:8000 -t public/` (single-threaded — see note in §4).
- Confirmed local is functionally identical to Render; Render was just slow (cold free tier).

## 2. Bugs found & fixed
1. **`/community/groups` HTTP 500** — code queried table `group_join_request` (raw SQL, no
   Doctrine entity) that was never created. **Fixed:** created the table + FKs + indexes.
2. **Wrong JOIN table name** in `GroupRepository::findPendingRequestsForGroup` — used
   `INNER JOIN user u` but the table is `app_user`. **Fixed:** `INNER JOIN app_user u`.
3. **Test account locked** — `testuser1@test.com` was auto-banned after 3 failed logins
   (`LoginFormAuthenticator::MAX_LOGIN_ATTEMPTS`). **Fixed:** reset `is_active/is_banned/login_attempts`.

## 3. Integrations investigated (no code bug — config needed)
- **Email:** code is correct; emails route **synchronously** (`messenger.yaml SendEmailMessage: sync`).
  Proven end-to-end locally with an SMTP catcher. Root cause of "no reset email" on Render =
  `MAILER_DSN` not set to real SMTP creds (needs a Gmail App Password).
- **Google login:** OAuth flow wired correctly; needs real client id/secret + callback URL on Render.
- **Captcha:** renders only with real reCAPTCHA keys; cleanly hidden with placeholders.

## 4. Test tooling added (`scripts/`)
- `smoke_test.py` — logs in as each role, GETs every parameter-less page, reports 5xx.
- `check_page.py` — targeted single-page checker.
- `crud_test.py` — creates post/group/project and verifies persistence.
- `smtp_catcher.py` — local SMTP server to view outgoing email.
- Note: the `php -S` dev server is single-threaded and deadlocks on pages that make
  server-side localhost calls; AI/external pages are skipped in the crawl.

## 5. Phase-1 data collection (external sources) — `scripts/scrape/`
| Source | What we got | How | Notes |
|---|---|---|---|
| **World Bank** | 6 indicators (GDP growth, GDP/capita, CPI inflation, unemployment, school enrolment, business-reg rating) × 262 countries × 1961–2024 | your WDI CSVs | model-ready macro panel |
| **Disrupt Africa** | 2022–24 funding reports → clean text + KPIs (633→406→200 startups; $3.3B→$2.4B→$1.1B) | free report PDFs, column-aware text extraction | aggregate, not row-level (their DB is paid) |
| **African Dev Bank** | 5,633 investment activities | free IATI/d-portal API (`XM-DAC-46002`) | titles, commitments, spend, dates |
| **MAGNiTT** | MENA annual totals 2021–24 | published headline figures | PDF is image-only + deals paywalled |
| **Kaggle** | 10 startup success/failure datasets | your downloads | 100k-row outcome set + 7 failure sets |
| **Crunchbase** | `big_startup_secsees` (66k companies w/ status) | = the Kaggle Crunchbase dump | the "validation" role |
| **World Bank (extra)** | exchange rate (5,176) / political stability (5,050) / FDI (6,020) for Model 4 | `worldbank_extra.py` | **DONE** — `worldbank_wide` now has 9 indicators; loaded to DB. (WGI code is `GOV_WGI_PV.EST`.) |

## 6. Consolidation into model-ready tables — `data/processed/`
Built by `consolidate.py`, `build_training_set.py`, `build_scoring_features.py`:
- `worldbank_wide.csv` (15,200 country×year × 6 indicators) + `worldbank_long.csv` (54,442).
- `startups_training.csv` (168,092 rows, harmonised across all Kaggle sources; labels
  success 51.6k / fail 63.1k / operating 53.4k).
- `startups_failures_combined.csv` (1,224; failure reasons).
- `scoring_training_features.csv` (100,000; funding-independent features — see §8).
- `afdb_activities.csv`, `disrupt_africa_kpis.csv`, `magnitt_mena_kpis.csv`.

## 7. Loading into PostgreSQL — `scripts/load_to_db.py`
All under a dedicated **`ml_data`** schema (the app's `public` tables untouched). DB password
read from `DATABASE_URL_ML` at runtime — never committed.

| table | rows |
|---|---|
| scoring_training_features | 100,000 |
| startups_training | 168,092 |
| worldbank_long / wide | 54,442 / 15,200 |
| afdb_activities | 5,633 |
| startups_failures | 1,224 |
| magnitt_mena_kpis / disrupt_africa_kpis | 4 / 3 |
Indexes on label/source/country/year.

## 8. The feature-alignment problem (the important insight)
Public startup datasets predict success from **funding history** (funding_total, rounds,
investor_type). **LockedIn startups are pre-investment**, so those are ~0 at submission and
cannot be model inputs. The platform form also didn't collect the inputs the report's
**Model 1** lists (*sector, team size, funding target, traction, geography, founder experience*).

**Fix (two sides):**
- **Training:** `scoring_training_features.csv` uses ONLY funding-independent features →
  `sector, team_size, founder_experience_years, product_traction_users, market_size_billion,
  revenue_million, burn_rate_million, founder_background` + binary `label`
  (fail 55,610 / success 44,390). Now *training features == inference features*.
- **App:** collect those inputs on the submission form (§9).
- **Reframe:** Model 1 outputs an investment-**readiness/quality** score 0–100 (with confidence),
  not a literal "will IPO/die" prediction — which is what the platform actually needs and what
  pre-funding data can support.

## 9. App + table changes (collect the right inputs)
**Schema (additive, nullable — safe; applied to live DB):**
- `projet.pays` — geography (also the join key to World Bank macro → Model 4)
- `donnees_business.taille_equipe` — team size (headcount)
- `donnees_business.objectif_financement` — funding **target** (sought)
- `donnees_business.traction` — early traction (users/pilots)
- `donnees_business.experience_equipe` — founder experience (years)

**Code:**
- `src/Entity/Projet.php`, `src/Entity/DonneesBusiness.php` — new fields + getters/setters.
- `src/Controller/ProjetController.php` — `hydrateProjet`/`hydrateDonnees` read the 5 new fields.
- `templates/front/projet/form.html.twig` — Country select + "AI scoring signals (optional)"
  block (team size / founder experience / funding target / traction). **Same design/styling.**
- `src/Service/ProjetScoringService.php` — `computeTeamScore` now rewards founder experience +
  team size (backward compatible).
All optional → nothing breaks for existing projects.

## 10. ML auto-learn foundation ("log everything from day one")
So models improve from real platform behaviour, not just the static Kaggle data:
- **`ml_data.platform_event`** — generic event log (event_type, entity, user, JSONB payload, ts).
- **`ml_data.v_project_training`** — read-only VIEW turning every live project into a row in
  the scoring-feature schema (label NULL until outcome known).
- **`src/Service/MlEventLogger.php`** — fire-and-forget logger (never throws; swallows errors so
  it can't break a user request).
- Wired events (all live): `project_submitted`, `project_evaluated`, `investor_applied`,
  `investor_offer_made` (deal initiated), `offer_accepted` (positive deal outcome),
  `offer_rejected`, `application_rejected`, `mentor_session_booked`, `mentor_request_declined`.
  Covers the PDF's full "log everything" list (projects, investor actions, deals, mentor sessions).
- **Verified end-to-end:** submitting a project via the form writes a `project_submitted`
  event with the full feature payload and the project appears in `v_project_training`.
- SQL is idempotent in `scripts/sql/ml_autolearn.sql`.

## 10b. FastAPI ML service skeleton (`ml-service/`)
The Python serving process (PDF stack: "FastAPI — ML model serving"). Runs separately;
Symfony calls it over HTTP.
- `ml-service/main.py` — `GET /health`, `POST /score` (accepts the funding-independent feature
  set, returns score 0–100 + confidence + driver breakdown). **No model yet** → transparent
  heuristic tagged `"model":"stub"` so Symfony can integrate against a stable contract now.
- `requirements.txt`, `README.md`. Verified working via FastAPI TestClient
  (`/health` ok; `/score` returns a scored response).
- Next: train XGBoost on `ml_data.scoring_training_features`, `joblib`-save, load in `main.py`,
  replace the stub body — API contract unchanged. Then point `ProjetScoringService` at it
  (keep the rule-based scorer as fallback).

## 11. Code quality pass
- `php -l` across all of `src/` → **0 syntax errors**.
- PHPStan (level 5): fixed the 4 real findings via `instanceof User` narrowing
  (`HomeController`, `InvestmentController`) and removing a dead `??` (`TeamMatcherController`).
  Remaining items are config-level "ignored pattern" notes, not code bugs.
- Full smoke crawl re-run after all changes (3 roles, every parameter-less page):
  **0 server errors (5xx), 0 exceptions.** The only 2 timeouts are `/investissement/...ajax`
  and `/investissement/portfolio` — the known single-threaded `php -S` deadlock (those pages
  make server-side calls); they work on Render's multi-worker server. Not a regression.
- Verified clean: `lint:twig` (form template), `lint:container` (MlEventLogger autowires),
  `doctrine:schema:validate` (mapping correct), `crud_test.py` (post/group/project all pass).

## 12. Open items & security
- ⚠️ **Rotate the Render DB password** — it has been pasted in plaintext several times; treat
  as compromised. Update it in Render → Environment and in `.env.local`.
- **Models not trained yet** (by decision) — `scoring_training_features` is ready for XGBoost.
- **World Bank extra indicators** pending (network timeout here) — `worldbank_extra.py` ready.
- Optionally make the new form fields required once founders are used to them.
- Wire `MlEventLogger` into investor actions (viewed/applied/accepted/rejected), deals, and
  mentor sessions to complete the report's "log everything" list.

## 13. File / script index
**Scrapers** (`scripts/scrape/`): `disrupt_africa.py`, `afdb.py`, `magnitt.py`, `worldbank_extra.py`
**Pipeline** (`scripts/`): `consolidate.py`, `build_training_set.py`, `build_scoring_features.py`,
`load_to_db.py`, `sql/ml_autolearn.sql`
**Tests** (`scripts/`): `smoke_test.py`, `check_page.py`, `crud_test.py`, `smtp_catcher.py`
**Data**: `data/raw/**` (originals), `data/processed/**` (model-ready), `data/README.md` (manifest)
**Docs**: `docs/PROJECT_JOURNAL.md` (this file), `docs/DATA_ML_CHANGELOG.md`, `docs/TEST_AND_FIX_REPORT.md`
**App code touched**: `src/Entity/Projet.php`, `src/Entity/DonneesBusiness.php`,
`src/Controller/ProjetController.php`, `src/Controller/HomeController.php`,
`src/Controller/InvestmentController.php`, `src/Controller/TeamMatcherController.php`,
`src/Service/ProjetScoringService.php`, `src/Service/MlEventLogger.php`,
`templates/front/projet/form.html.twig`

## How to reproduce the whole data pipeline
```bash
py -3.11 scripts/scrape/disrupt_africa.py
py -3.11 scripts/scrape/afdb.py
py -3.11 scripts/scrape/magnitt.py
py -3.11 scripts/scrape/worldbank_extra.py     # from a network that can reach api.worldbank.org
py -3.11 scripts/consolidate.py
py -3.11 scripts/build_training_set.py
py -3.11 scripts/build_scoring_features.py
DATABASE_URL_ML="postgresql://..." py -3.11 scripts/load_to_db.py
DATABASE_URL_ML="postgresql://..." psql ... -f scripts/sql/ml_autolearn.sql
```
