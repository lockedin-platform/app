#!/usr/bin/env python3
"""
Load all processed datasets into the Render PostgreSQL DB, under a dedicated
`ml_data` schema (kept fully separate from the app's `public` tables).

Connection string is read from the DATABASE_URL_ML env var (never hardcoded).

Run:
  DATABASE_URL_ML="postgresql://..." py -3.11 scripts/load_to_db.py
"""
import csv
import os
import re
import sys
import psycopg2

OUT = "data/processed"

# friendly short names for the World Bank wide indicator columns
WB_WIDE_RENAME = {
    "GDP growth (annual %)": "gdp_growth_pct",
    "GDP per capita (current US$)": "gdp_per_capita_usd",
    "Inflation, consumer prices (annual %)": "inflation_cpi_pct",
    "Unemployment, total (% of total labor force) (national estimate)": "unemployment_pct",
    "School enrollment, secondary (% gross)": "school_enroll_secondary_pct",
    "CPIA business regulatory environment rating (1=low to 6=high)": "cpia_business_reg_rating",
    # the 3 Model-4 indicators added via worldbank_extra.py (must be numeric, not TEXT)
    "Official exchange rate (LCU per US$, period average)": "official_exchange_rate_usd",
    "Political Stability and Absence of Violence/Terrorism: Estimate": "political_stability_estimate",
    "Foreign direct investment, net inflows (% of GDP)": "fdi_net_inflows_pct_gdp",
}

# explicit non-text column types per table (everything else -> TEXT)
TYPES = {
    "worldbank_long": {"year": "INTEGER", "value": "DOUBLE PRECISION"},
    "worldbank_wide": {"year": "INTEGER", **{v: "DOUBLE PRECISION" for v in WB_WIDE_RENAME.values()}},
    "afdb_activities": {"commitment_usd": "NUMERIC", "spend_usd": "NUMERIC"},
    "disrupt_africa_kpis": {"year": "INTEGER", "funded_startups": "INTEGER",
                            "total_funding_usd": "BIGINT", "avg_deal_usd": "BIGINT"},
    "magnitt_mena_kpis": {"year": "INTEGER", "total_funding_usd": "BIGINT",
                          "num_deals": "INTEGER", "is_approx": "INTEGER"},
    "startups_training": {"founded_year": "INTEGER", "funding_total_usd": "NUMERIC",
                          "funding_rounds": "INTEGER", "team_size": "INTEGER",
                          "revenue_musd": "NUMERIC"},
    "scoring_training_features": {"team_size": "INTEGER", "founder_experience_years": "INTEGER",
                                  "product_traction_users": "INTEGER", "market_size_billion": "NUMERIC",
                                  "revenue_million": "NUMERIC", "burn_rate_million": "NUMERIC",
                                  "sector_success_prior": "NUMERIC",
                                  "label": "INTEGER"},
    "model1_training": {"founder_experience_years": "INTEGER", "log_team_size": "DOUBLE PRECISION",
                        "log_traction": "DOUBLE PRECISION", "log_revenue": "DOUBLE PRECISION",
                        "sector_success_prior": "NUMERIC", "label": "INTEGER"},
}

# csv file -> table name
FILES = {
    "worldbank_long.csv": "worldbank_long",
    "worldbank_wide.csv": "worldbank_wide",
    "afdb_activities.csv": "afdb_activities",
    "disrupt_africa_kpis.csv": "disrupt_africa_kpis",
    "magnitt_mena_kpis.csv": "magnitt_mena_kpis",
    "startups_training.csv": "startups_training",
    "startups_failures_combined.csv": "startups_failures",
    "scoring_training_features.csv": "scoring_training_features",
    "model1_training.csv": "model1_training",
}


def san(name):
    n = re.sub(r"[^0-9a-zA-Z]+", "_", name.strip().lower()).strip("_")
    if not n:
        n = "col"
    if n[0].isdigit():
        n = "c_" + n
    return n[:63]


def load(cur, csv_path, table):
    with open(csv_path, encoding="utf-8") as f:
        header = next(csv.reader(f))
    # map header -> column name (friendly for WB wide, else sanitised)
    colnames, seen = [], {}
    for h in header:
        c = WB_WIDE_RENAME.get(h) or san(h)
        if c in seen:
            seen[c] += 1
            c = f"{c}_{seen[c]}"
        else:
            seen[c] = 0
        colnames.append(c)
    types = TYPES.get(table, {})
    defs = ", ".join(f'"{c}" {types.get(c, "TEXT")}' for c in colnames)

    cur.execute(f"DROP TABLE IF EXISTS ml_data.{table}")
    cur.execute(f"CREATE TABLE ml_data.{table} ({defs})")
    with open(csv_path, "r", encoding="utf-8") as f:
        cur.copy_expert(
            f"COPY ml_data.{table} FROM STDIN WITH (FORMAT csv, HEADER true, NULL '')", f)
    cur.execute(f"SELECT count(*) FROM ml_data.{table}")
    return cur.fetchone()[0], len(colnames)


def main():
    dsn = os.environ.get("DATABASE_URL_ML")
    if not dsn:
        sys.exit("set DATABASE_URL_ML env var")
    conn = psycopg2.connect(dsn, connect_timeout=30, sslmode="require")
    conn.autocommit = False
    cur = conn.cursor()
    cur.execute("CREATE SCHEMA IF NOT EXISTS ml_data")
    print("schema ml_data ready")
    for fname, table in FILES.items():
        p = f"{OUT}/{fname}"
        if not os.path.exists(p):
            print(f"  SKIP {fname} (missing)")
            continue
        try:
            n, ncols = load(cur, p, table)
            conn.commit()
            print(f"  ml_data.{table}: {n:,} rows, {ncols} cols")
        except Exception as e:
            conn.rollback()
            print(f"  ERROR {table}: {e}")
    cur.close()
    conn.close()
    print("done")


if __name__ == "__main__":
    main()
