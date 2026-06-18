# LockedIn — Data & ML Alignment Changelog

What was done to get Phase 1 data ready and to align the app's startup-scoring
inputs with the model's training features. **No models were trained** (per decision —
data + plumbing only). Website visual design was left unchanged.

Owner: Dali (data/ML). Date: June 2026.

---

## 1. External data collected (scripts in `scripts/scrape/`)

| Source | Script | Output | Notes |
|---|---|---|---|
| Disrupt Africa | `disrupt_africa.py` | 3 report PDFs → text + `disrupt_africa_kpis.csv` | African market context (2022–24). Aggregate, not row-level (their DB is paid). |
| African Dev Bank | `afdb.py` | `afdb_activities.csv` (5,633) | Pulled via the free IATI/d-portal API (org `XM-DAC-46002`). |
| MAGNiTT | `magnitt.py` | `magnitt_mena_kpis.csv` (4) | MENA annual totals. PDF is image-only + deals are paywalled, so headline figures only. |
| World Bank (extra) | `worldbank_extra.py` | exchange rate / political stability / FDI | **DONE** — loaded as numeric cols in `worldbank_wide` (9 indicators total). WGI code is `GOV_WGI_PV.EST`. |

## 2. Datasets consolidated (`scripts/consolidate.py`, `scripts/build_training_set.py`, `scripts/build_scoring_features.py`)

Raw inputs copied into `data/raw/` (your Desktop `Data/` folder + Downloads):
- 10 Kaggle startup CSVs → `data/raw/kaggle/`
- 6 World Bank WDI CSVs → `data/raw/worldbank/`

Processed outputs in `data/processed/`:

| File | Rows | Purpose |
|---|---|---|
| `scoring_training_features.csv` | 100,000 | **Model 1 training set, funding-independent** (see §4) |
| `startups_training.csv` | 168,092 | unified cross-source startup table (success/fail/operating) |
| `startups_failures_combined.csv` | 1,224 | failure reasons (Model 3 NLP / Model 5) |
| `worldbank_wide.csv` | 15,200 | country×year × 6 indicators (Model 4) |
| `worldbank_long.csv` | 54,442 | tidy form |
| `afdb_activities.csv` | 5,633 | AfDB investments |
| `disrupt_africa_kpis.csv` / `magnitt_mena_kpis.csv` | 3 / 4 | market context |

## 3. Loaded into PostgreSQL (`scripts/load_to_db.py`)

All tables live under a dedicated **`ml_data`** schema (the app's `public` tables were
never touched). DB password read from `DATABASE_URL_ML` env var — never committed.

```
ml_data.scoring_training_features   100,000
ml_data.startups_training           168,092
ml_data.worldbank_long               54,442
ml_data.worldbank_wide               15,200
ml_data.afdb_activities               5,633
ml_data.startups_failures             1,224
ml_data.magnitt_mena_kpis                 4
ml_data.disrupt_africa_kpis               3
```
Indexes: `scoring_training_features(label)`, `startups_training(label,source)`,
`worldbank_long(country_code,indicator_code,year)`, `worldbank_wide(country_code,year)`.

## 4. Feature alignment — the important part

**Problem found:** the public datasets predict success from FUNDING history
(funding_total, rounds, investor_type). LockedIn startups are PRE-investment, so those
are ~0 at submission and unusable as inputs. The platform form also didn't collect the
inputs the report's Model 1 actually lists.

**Fix A — training side:** `scoring_training_features.csv` uses ONLY funding-independent
features, so training features == what a founder can submit:
`sector, team_size, founder_experience_years, product_traction_users, market_size_billion,
revenue_million, burn_rate_million, founder_background` + binary `label` (fail 55,610 /
success 44,390).

**Fix B — app side:** added the missing Model 1 inputs to the submission form.

### Schema changes (additive, nullable — safe, applied to live DB)
- `projet.pays` (VARCHAR) — geography (also the key for the World Bank macro join → Model 4)
- `donnees_business.taille_equipe` (INT) — team size (headcount)
- `donnees_business.objectif_financement` (DOUBLE) — funding **target** sought
- `donnees_business.traction` (INT) — early traction (users / pilots)
- `donnees_business.experience_equipe` (INT) — founding-team experience (years)

### Code changes
- `src/Entity/Projet.php` — `$pays` + getter/setter.
- `src/Entity/DonneesBusiness.php` — 4 new fields + getters/setters.
- `src/Controller/ProjetController.php` — `hydrateProjet`/`hydrateDonnees` now read the
  5 new POST fields (empty → null; existing flow unaffected).
- `templates/front/projet/form.html.twig` — added a Country select + an "AI scoring
  signals (optional)" block (team size / founder experience / funding target / traction).
  Uses the existing card/Bootstrap styling — no design change.
- `src/Service/ProjetScoringService.php` — `computeTeamScore` now rewards founder
  experience + team size when provided (backward compatible: null → unchanged).

All 4 PHP files pass `php -l`. New form fields are optional, so nothing breaks for
existing projects or users who skip them.

## 5. How to reproduce / refresh
```bash
py -3.11 scripts/scrape/disrupt_africa.py
py -3.11 scripts/scrape/afdb.py
py -3.11 scripts/scrape/magnitt.py
py -3.11 scripts/scrape/worldbank_extra.py        # run from a network that can reach api.worldbank.org
py -3.11 scripts/consolidate.py
py -3.11 scripts/build_training_set.py
py -3.11 scripts/build_scoring_features.py
DATABASE_URL_ML="postgresql://..." py -3.11 scripts/load_to_db.py
```

## 5b. Data-quality findings (from the code/data scan)

**Sector taxonomy mismatch — NOW FIXED (taxonomy + base-rate priors).**
The richest training set (`startup_success_dataset` → `scoring_training_features`) has only
**7 synthetic, tech-centric sectors**: Crypto, Climate, Health, SaaS, Fintech, Ecommerce, AI.
The platform's submission form offers **15 real-economy sectors** (Technology, Health,
Education, Finance, Commerce, Agriculture, Tourism, Real Estate, Transport, Energy,
Food & Beverage, Fashion & Textile, Industry, Services, Crafts). **Only "Health" overlaps.**

Implications:
- A founder choosing Agriculture / Tourism / Crafts has a sector the model never saw → the
  `sector` feature won't generalise for LockedIn's actual (Tunisian/African) sector mix.
- That synthetic dataset is **not representative** of this market; don't over-trust it.

Recommended fixes (pick per timeline, do NOT distort the product to fit the data):
1. Lean on the **numeric, sector-agnostic features** (team size, founder experience, market
   size, traction, financials) for v1 — these transfer across sectors.
2. Map both sides to a coarse shared taxonomy (e.g. Tech, Finance, Health, Commerce,
   Agri/Food, Services, Other) and train on the mapped column.
3. Supplement sector signal with **real African data** — Disrupt Africa reports already give
   sector-level funding splits; the friend's Tunisian sources add local sector labels.
4. As real submissions accumulate (now logged via `ml_data.v_project_training`), retrain on
   *our own* sector distribution — the auto-learn loop fixes this over time.

## 6. Sector taxonomy + base-rate priors (the Crunchbase fix)
- `scripts/sector_map.py` + `src/Service/SectorTaxonomy.php` — one shared crosswalk maps both
  Kaggle and platform sectors into 8 canonical buckets. `scoring_training_features.sector_canonical`.
- The "empty" buckets (Agriculture, Education, Tourism/Crafts…) are filled from the **Crunchbase
  dump we already had** (real `category_list` + outcome): OTHER 5,559, EDUCATION 214, AGRI_FOOD 41.
- `scripts/build_sector_priors.py` → `sector_priors.json`: a smoothed historical success rate per
  bucket, added as the `sector_success_prior` feature (continuous, so it generalises to all 8
  buckets even though Kaggle only populated 5). Mirrored in PHP `SectorTaxonomy::successPrior()`.

## 7. Full data audit (tour) — findings
- **FIXED — wrong column types:** the 3 new WB indicators (fx, political stability, FDI) and
  `sector_success_prior` had loaded as TEXT; retyped to numeric (`DOUBLE`/`NUMERIC`) for Model 4.
- **VERIFIED clean:** every `scoring_training_features` feature is 100% populated; World Bank
  covers our African/MENA markets (TUN/EGY/NGA/ZAF… have fx, political stability, FDI for 2023);
  AfDB 5,633 rows have commitments + usable descriptions.
- **🔴 MAJOR — synthetic scale mismatch (`startup_success_dataset`).** Its rows describe
  *enterprise-scale* companies, not pre-seed startups: median team **151**, revenue **$621B**,
  traction **265k users**. Real LockedIn founders (team ~4, revenue ~0.2M, traction ~1k) fall at/below
  the training **minimum** of every feature → absolute thresholds won't transfer.
  - Good news: the data has **real directional signal** — success vs fail separates on founder
    experience (+27%), traction (+59%), revenue (+103%), team (+11%).
  - Dead features: **market_size_billion** and **burn_rate_million** show ~0% gap (no signal) → drop/down-weight.
  - **RESOLVED (as far as public data allows):** `scripts/ml/build_model1_dataset.py` →
    `model1_training.csv` / `ml_data.model1_training`. Dropped the 2 dead features; kept
    `founder_experience_years` RAW (its 0–24 scale matches real founders — the transferable anchor);
    log1p-transformed team/traction/revenue so a **logistic regression** extrapolates "more = better"
    down to real-startup scale. Honest **5-fold CV AUC = 0.80** (strong in-distribution).
  - Remaining caveat (cannot be coded away): that 0.80 is measured on the synthetic population;
    real-world accuracy will be lower until our own submissions validate/retrain it. `founder_exp`
    is the reliable part (scale-aligned); revenue/traction weights are directionally right but
    scale-optimistic. Ship with low confidence; the moat is our own data.
- **Model 3 (NLP) data:** we have unlabelled text (Disrupt Africa reports, failure "why_they_failed",
  project descriptions) but no labelled business-plan sections yet — fine, Model 3 is a later phase.

## 8. Shipped (no training — "getting things ready")
- **Model 4 (Risk) v0 — LIVE.** `src/Service/Investment/MacroRiskService.php` reads the real
  `ml_data.worldbank_wide` indicators for a project's country and returns a dynamic risk score
  with a per-factor breakdown (inflation, GDP growth, political stability, FDI, unemployment).
  Wired into the project economic page (`/projets/{id}/exchange-rates`); renders live (e.g. Tunisia
  64/100 Moderate), graceful when no country/data. No training, no external API call.
- **Outcome-label capture — DONE (both sides).** `ml_data.v_project_training.outcome_label`:
  `'funded'` (POSITIVE) once an `offer_accepted` event references the project; `'stalled'` (NEGATIVE)
  when a project is >90 days old with zero activity in the last 90 days (recent activity resets the
  clock); NULL when unknown/too recent. Both branches verified. Closes the auto-learn loop's data side
  with real positives AND negatives.

## 9. Open items (NOT done on purpose)
- **Models not trained yet** (per decision). `model1_training` is ready (AUC 0.80); heed §7:
  v1 should be monotonic + low-confidence, with the 2 dead features already dropped.
- **Serve Model 1** — train + load into FastAPI `/score`, point `ProjetScoringService` at it.
- **Rotate the Render DB password** — shared in plaintext; treat as compromised.
- Optionally make the new form fields required once founders are used to them.
