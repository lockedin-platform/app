# LockedIn — Training Data (Phase 1 Data Infrastructure)

Data collected for the ML models in the Summer 2026 Strategy Report (Phase 2).
Everything here is **free / public**. Re-run the scripts to refresh.

```
data/
  raw/          # original downloads, untouched
    disrupt_africa/   3 annual funding report PDFs (2022-2024)
    magnitt/          FY2023 MENA report PDF (image-only)
    worldbank/        World Bank WDI CSVs (SDMX format)
    kaggle/           your Kaggle startup CSVs (drop more here)
  processed/    # cleaned, model-ready outputs
```

## Processed files & which model they feed

| File | Rows | Source | Feeds |
|------|------|--------|-------|
| `scoring_training_features.csv` | **100,000** | startup_success_dataset | **Model 1 – Scoring (funding-independent, inference-aligned)** — see `docs/DATA_ML_CHANGELOG.md` §4 |
| `startups_training.csv` | **168,092** | all Kaggle sources harmonised | unified cross-source view (label: success 51.6k / fail 63.1k / operating 53.4k) |
| `startups_failures_combined.csv` | 1,224 | 7 Kaggle failure files | **Model 5 – Fraud/Quality**, Model 3 NLP (failure reasons) |
| `worldbank_wide.csv` | 15,200 (country×year) | World Bank | **Model 4 – Macro Risk** (6 indicators, one col each) |
| `worldbank_long.csv` | 54,442 | World Bank | tidy/queryable version of the above |
| `afdb_activities.csv` | 5,633 | African Dev Bank (IATI) | Model 4 context, RAG knowledge |
| `disrupt_africa_kpis.csv` | 3 | Disrupt Africa | African market context (funding/yr, #startups, avg deal) |
| `disrupt_africa_2022/23/24.txt` | full text | Disrupt Africa | **Phase 3 RAG corpus** (African market) |
| `magnitt_mena_kpis.csv` | 4 | MAGNiTT (public figures) | MENA market context |

> For the first Scoring model, train primarily on `data/raw/kaggle/startup_success_dataset.csv`
> (100k rows, richest numeric features). Use `startups_training.csv` for the unified
> cross-source view + Crunchbase (`source=crunchbase`) as a validation hold-out.
> Build with `py -3.11 scripts/build_training_set.py`.

## How to reproduce

```bash
py -3.11 scripts/scrape/disrupt_africa.py   # downloads PDFs + extracts text/KPIs
py -3.11 scripts/scrape/afdb.py             # pulls AfDB activities via IATI/d-portal
py -3.11 scripts/scrape/magnitt.py          # writes MENA aggregate KPIs
py -3.11 scripts/consolidate.py             # builds worldbank_*.csv + startups_combined.csv
```

`consolidate.py` is **incremental**: drop any new CSV into `data/raw/kaggle/`
(your other startup success/failure datasets) or `data/raw/worldbank/`
(e.g. GDP-per-capita) and re-run — it auto-merges everything.

## Loaded into PostgreSQL (Render)

All processed tables are loaded into the production DB under a dedicated **`ml_data`**
schema (isolated from the app's `public` tables). Reload with:

```bash
DATABASE_URL_ML="postgresql://..." py -3.11 scripts/load_to_db.py
```

| ml_data table | rows |
|---|---|
| `startups_training` | 168,092 (label: fail 63,072 / operating 53,382 / success 51,638) |
| `worldbank_long` | 54,442 |
| `worldbank_wide` | 15,200 |
| `afdb_activities` | 5,633 |
| `startups_failures` | 1,224 |
| `magnitt_mena_kpis` | 4 |
| `disrupt_africa_kpis` | 3 |

Indexes: `startups_training(label)`, `startups_training(source)`,
`worldbank_long(country_code,indicator_code,year)`, `worldbank_wide(country_code,year)`.
The DB password is never stored in the repo — it is read from `DATABASE_URL_ML` at runtime.

## Status vs the Strategy Report data table

DONE (my half — African/MENA + Kaggle/Crunchbase):
- World Bank Open Data ✅ (5 indicators wide+long; add GDP/capita CSV when you have it)
- Disrupt Africa ✅ (3 yrs text + KPIs)
- African Development Bank ✅ (5,633 activities)
- MAGNiTT ✅ aggregate KPIs (row-level deals are paywalled; PDF is image-only)
- Kaggle / Crunchbase ✅ `startup_data.csv` in; `big_startup_secsees` IS the Crunchbase dump

NEEDS YOU:
- Send the rest of your Kaggle startup CSVs (the failure datasets + success dataset
  + big_startup_secsees) → drop in `data/raw/kaggle/`, re-run consolidate → one combined CSV.
- A Kaggle login is required to auto-download new sets; that's why I couldn't fetch
  a fresh 2024-25 validation set for you.

NOT MY HALF (your friend Elyes — Tunisian sources):
- Startup Tunisia, Startup Act, BIAT/Amen, Carthage Business Angels, BVMT, BCT, INS, incubators.
