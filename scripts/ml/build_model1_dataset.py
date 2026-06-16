#!/usr/bin/env python3
"""
Model 1 — final, scale-robust training table.

Fixes the audit's "enterprise-scale" problem so the synthetic data's real DIRECTIONAL
signal transfers to pre-seed startups:

  - DROP dead features: market_size_billion, burn_rate_million (~0% separation = no signal).
  - KEEP founder_experience_years RAW — its scale (0–24 yrs) already matches real founders
    and it's the strongest aligned signal (+27% success separation). This is the anchor.
  - LOG-TRANSFORM the scale-broken-but-directional features (team_size, product_traction_users,
    revenue_million) with log1p. A monotonic/linear model on logs extrapolates "more = better"
    down to real-startup magnitudes instead of relying on brittle enterprise-scale thresholds.
  - KEEP sector_success_prior (covers all 8 sector buckets via the Crunchbase fix).

Train a LOGISTIC REGRESSION (monotonic, extrapolates) on this — NOT a tree (trees can't
extrapolate below the training range, which is exactly our problem). Output a calibrated
probability + LOW confidence; the trustworthy model comes from our own data over time.

Output: data/processed/model1_training.csv
Run:    py -3.11 scripts/ml/build_model1_dataset.py
"""
import csv
import math
import os

SRC = "data/processed/scoring_training_features.csv"
OUT = "data/processed/model1_training.csv"

OUT_COLS = ["founder_experience_years", "log_team_size", "log_traction",
            "log_revenue", "sector_success_prior", "label"]


def f(x):
    try:
        return float(x)
    except (TypeError, ValueError):
        return 0.0


def main():
    if not os.path.exists(SRC):
        raise SystemExit(f"missing {SRC} — run build_scoring_features.py first")
    n = 0
    with open(SRC, encoding="utf-8-sig", newline="") as fin, \
         open(OUT, "w", newline="", encoding="utf-8") as fout:
        r = csv.DictReader(fin)
        w = csv.writer(fout)
        w.writerow(OUT_COLS)
        for row in r:
            w.writerow([
                int(f(row.get("founder_experience_years"))),
                round(math.log1p(f(row.get("team_size"))), 4),
                round(math.log1p(f(row.get("product_traction_users"))), 4),
                round(math.log1p(f(row.get("revenue_million"))), 4),
                row.get("sector_success_prior") or "",
                row.get("label"),
            ])
            n += 1
    print(f"  wrote {OUT}: {n:,} rows, features={OUT_COLS[:-1]}")
    print("  dropped (no signal): market_size_billion, burn_rate_million")
    print("  train with: LogisticRegression (monotonic, extrapolates to real scale)")


if __name__ == "__main__":
    main()
