# LockedIn — Dali Branch Integration Handoff

Everything done on the **`Dali`** branch since we pulled from GitHub, so we can merge into
`main` without missing anything. Audience: Mahdi (+ his Claude) doing the integration.

**Branch:** `Dali` · **4 commits ahead of `main`** · **67 files (+3,220 / −314)**
`main` has NOT moved, so the merge is clean (fast-forward possible).

```
19aee23  Model 1 outcome label: nullable Projet.outcome override + view fallback
c922b45  Deploy: auto-recreate v_project_training view after schema:update
705d204  Repoint event log to public.platform_event (deploy fix from Mahdi's side)
9e883ad  Data/ML foundation + Model 4 + security & performance hardening
```

---

## 1. Timeline — what we did, in order

1. **Got it running + fixed real bugs** — local PHP 8.2 env; fixed `/community/groups` 500
   (missing `group_join_request` table), a wrong table-name JOIN in `GroupRepository`, a locked test account.
2. **Collected Phase-1 data** (World Bank, Disrupt Africa, AfDB, MAGNiTT, Kaggle/Crunchbase) → loaded into a dedicated **`ml_data`** schema.
3. **Feature alignment** — found the public data is funding-based/enterprise-scale; built funding-independent training features + added the matching inputs to the submission form.
4. **Auto-learn foundation** — event logging, live training view, retrain trigger.
5. **v0 models** — Model 2 (matching +geography), Model 4 (macro risk, live), Model 5 (submission quality).
6. **Security + performance hardening** — TLS, CSRF, rate-limiting, headers, OPcache, etc.
7. **Deploy coordination** — repointed the event log to `public.platform_event` (Mahdi's fix), made the deploy recreate the view, added the `outcome` label override.

---

## 2. Database changes (READ THIS BEFORE MERGING)

### New columns on existing `public` tables (additive, nullable — safe)
Added on the **Dali entities**; Doctrine `schema:update` will create them automatically on deploy:
| Table | Column | Type | Purpose |
|---|---|---|---|
| `projet` | `pays` | VARCHAR(100) | geography (Model 1 + macro join) |
| `projet` | `outcome` | VARCHAR(20) | manual ML label override (funded/operating/failed/cancelled) |
| `donnees_business` | `taille_equipe` | INT | team size |
| `donnees_business` | `objectif_financement` | DOUBLE | funding target |
| `donnees_business` | `traction` | INT | early traction |
| `donnees_business` | `experience_equipe` | INT | founder experience (years) |
| `investor_profile` | `preferred_country` | VARCHAR(100) | geography matching (Model 2) |

> ⚠️ When `main` (without these entity fields) is deployed, `schema:update` **drops** these columns.
> They only persist once **`Dali` is merged/deployed**. (I re-added them manually on the live DBs as a stopgap.)

### `platform_event` lives in `public` (Mahdi's change, integrated)
The event log is `public.platform_event` (app-managed). Our code (`MlEventLogger`, the view, the
retrain trigger) all reference it with its columns: `event_type, user_id, user_role, entity_id,
entity_type, payload (json), occurred_at`.

### `ml_data` schema (ours — the models/data)
Tables: `scoring_training_features` (100k), `model1_training` (100k), `startups_training` (168k),
`startups_failures` (1.2k), `worldbank_long` (54k), `worldbank_wide` (9k, 9 indicators),
`afdb_activities` (5.6k), `disrupt_africa_kpis`, `magnitt_mena_kpis`. **Not Doctrine-managed**
(created by `scripts/load_to_db.py`), so `schema:update` ignores it.

### View `ml_data.v_project_training`
Reads `public.projet` + `donnees_business` + `public.platform_event` → one scoring-feature row per
project, with `outcome_label` = explicit `projet.outcome` if set, else derived (`funded` from
`offer_accepted`, `stalled` from 90-day inactivity). The deploy **drops then recreates** it (see §4).

### 🔴 Neon migration is INCOMPLETE (urgent — Render expires July 8)
The Render→Neon migration copied **structure only, not data**: `ml_data` was missing (I restored it
from local files), the `ml` schema (Tunisian data) has **empty tables**, and `public` is nearly empty
(2 users, 0 projects). **Action: a full `pg_dump` Render → Neon for the `public` and `ml` data before July 8.**

---

## 3. Code changes

### New files (35) — none conflict with `main`
- **ML services:** `MlEventLogger`, `SubmissionQualityService` (Model 5 v0), `SectorTaxonomy`,
  `SimpleRateLimiter`, `Support/Tls`, `Investment/MacroRiskService` (Model 4 v0).
- **App:** `HealthController` (`/health`), `EventSubscriber/SecurityHeadersSubscriber`.
- **Python pipeline (`scripts/`):** `scrape/{worldbank,disrupt_africa,afdb,magnitt}.py`,
  `consolidate.py`, `build_training_set.py`, `build_scoring_features.py`, `build_sector_priors.py`,
  `sector_map.py`, `load_to_db.py`, `ml/{build_model1_dataset,retrain_trigger}.py`, `sql/ml_autolearn.sql`.
- **ML serving:** `ml-service/` (FastAPI skeleton).
- **Infra:** `docker/opcache.ini`, `Dockerfile.frankenphp`.
- **Docs:** `docs/{PROJECT_JOURNAL,DATA_ML_CHANGELOG,SECURITY_PERF_AUDIT,TEST_AND_FIX_REPORT}.md`, `data/README.md`.

### Modified files (26)
- **Entities:** `Projet` (+pays, +outcome), `DonneesBusiness` (+4 fields), `InvestorProfile` (+preferred_country).
- **Controllers:** `ProjetController` (form fields, event logging, Model 4 wiring, rate-limit),
  `InvestmentController` (event logging + macro snapshot), `MentoratController`, `HomeController`,
  `ProfileController`, `InvestmentAdvancedController`, `TeamMatcherController` (rate-limiting + events),
  `Admin/AdminApprentissageController` (upload hardening), `CommunityController` (upload size cap).
- **Services:** `ProjetScoringService` (uses new fields), `InvestmentMatchingService` (+geography);
  TLS hardened in `GeminiService`, `AvisRatingService`, `CoursQuizService`, `InvestmentChatbotService`,
  `EconomicApiService`, `ProfanityCheckService` (now secure-by-default via `Tls::verify()`).
- **Templates:** `projet/form` (+5 inputs), `projet/exchange_rates` (macro-risk block), `investment/matching` (+country).
- **Config:** `framework.yaml` (session cookie SameSite=Lax/Secure/HttpOnly), `Dockerfile`
  (OPcache config + view recreation), `.gitignore`, `GroupRepository` (JOIN fix).

---

## 4. Integration checklist (merge `Dali` → `main`)

1. **Merge `Dali` → `main`** (fast-forward; no conflicts since main didn't move).
2. **Deploy** → `doctrine:schema:update` adds the 7 new columns automatically (entities define them).
3. The Dockerfile CMD now: `schema:update` → **recreate view** (`doctrine:query:sql "$(cat scripts/sql/ml_autolearn.sql)"`) → `create-admin` → serve. Confirm `ml_data` schema exists on the target DB (it does on Neon now).
4. **Finish the Neon data migration** (`public` + `ml` data from Render) before **July 8**.
5. **Run the World Bank refresh yearly** (`scripts/scrape/worldbank.py` → `consolidate.py` → `load_to_db.py`).
6. **Rotate the DB password** (shared in plaintext during setup) and confirm `COMMUNITY_GROQ_INSECURE` is unset/false in prod (TLS verification depends on it).

## 5. ML models status (no models trained yet — by decision)
- **Model 1 (Scoring):** dataset ready (`scoring_training_features`/`model1_training`, AUC 0.80 in tests). Train when ready.
- **Model 2 (Matching):** rule-based v0 live (sector/budget/risk/horizon/geography). ML version waits on interaction data.
- **Model 4 (Risk):** live rule-based lookup on real World Bank data (`MacroRiskService`).
- **Model 5 (Fraud/Quality):** rule-based v0 live (`SubmissionQualityService`).
- **Auto-learn loop:** events logged + outcome labels (funded/stalled/manual) + retrain trigger — accumulating.
- **FastAPI (`ml-service/`):** serving skeleton; swap the stub for the trained model later.

## 6. Tunisian data (Mahdi's `ml` schema)
Separate `ml` schema with 8 tables for the Tunisian sources. Populated on Render: `startup_tn` (156),
`ins_regions` (72), `macro_indicators` (32); the other 5 are empty. **Eventually merge `ml` into the
Model 1 pipeline** (`ml.startup_tn` → scoring features once it has outcome labels; `ml.macro_indicators`
→ complements `ml_data.worldbank_wide`).
