#!/usr/bin/env python3
"""
Build the Model 1 (Startup Scoring Engine) training table.

KEY DESIGN DECISION: LockedIn startups are PRE-investment, so funding_total /
funding_rounds / investor_type are ~0 at submission and CANNOT be model inputs.
We therefore train on ONLY funding-independent features that the platform actually
collects from a founder — so training features == inference features.

Feature alignment (report Model 1 input -> training col -> platform form field):
  sector                       sector                  -> projet.secteur
  team size                    team_size               -> donnees_business.taille_equipe
  founding team experience     founder_experience_years-> donnees_business.experience_equipe
  traction                     product_traction_users  -> donnees_business.traction
  market size                  market_size_billion     -> donnees_business.taille_marche
  (financial signals)          revenue_million,        -> revenus_attendus / couts_estimes
                               burn_rate_million
  founder background           founder_background      -> (optional, could be added later)
  label                        outcome (Failure/Acq/IPO) -> success(1)/fail(0)

Source: data/raw/kaggle/startup_success_dataset.csv (100k rows, clean numerics).
Output: data/processed/scoring_training_features.csv

Run:  py -3.11 scripts/build_scoring_features.py
"""
import csv
import json
import os
import sys

sys.path.insert(0, os.path.dirname(__file__))
from sector_map import canonicalize  # noqa: E402

SRC = "data/raw/kaggle/startup_success_dataset.csv"
OUT = "data/processed/scoring_training_features.csv"
PRIORS = "data/processed/sector_priors.json"

# inference-compatible feature columns (NO funding_total / funding_rounds / investor_type).
# `sector_canonical` = taxonomy-mapped bucket (raw `sector` kept for reference).
# `sector_success_prior` = data-backed historical success rate for that bucket (covers the
# buckets Kaggle lacked, via the Crunchbase dump) — see build_sector_priors.py.
FEATURES = ["sector", "sector_canonical", "sector_success_prior",
            "team_size", "founder_experience_years",
            "product_traction_users", "market_size_billion",
            "revenue_million", "burn_rate_million", "founder_background"]

LABEL_MAP = {"failure": 0, "acquisition": 1, "ipo": 1}


def main():
    if not os.path.exists(SRC):
        raise SystemExit(f"missing {SRC}")
    priors = json.load(open(PRIORS, encoding="utf-8"))["priors"] if os.path.exists(PRIORS) else {}
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    n, kept = 0, 0
    from collections import Counter
    labels = Counter()
    with open(SRC, encoding="utf-8-sig", newline="") as f, \
         open(OUT, "w", newline="", encoding="utf-8") as out:
        r = csv.DictReader(f)
        w = csv.writer(out)
        w.writerow(FEATURES + ["label"])
        for row in r:
            n += 1
            lab = LABEL_MAP.get((row.get("outcome") or "").strip().lower())
            if lab is None:
                continue
            canon = canonicalize(row.get("sector"))
            row["sector_canonical"] = canon
            row["sector_success_prior"] = priors.get(canon, "")
            w.writerow([row.get(c, "") for c in FEATURES] + [lab])
            labels[lab] += 1
            kept += 1
    print(f"  read {n:,} rows -> kept {kept:,}")
    print(f"  label balance: fail(0)={labels[0]:,}  success(1)={labels[1]:,}")
    print(f"  features ({len(FEATURES)}): {FEATURES}")
    print(f"  wrote {OUT}")


if __name__ == "__main__":
    main()
