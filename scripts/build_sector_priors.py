#!/usr/bin/env python3
"""
Sector base-rate priors — gives EVERY canonical bucket a real, data-backed success
rate, including the buckets the rich Kaggle set was missing (AGRI_FOOD, EDUCATION,
OTHER) by pulling sector+outcome from the Crunchbase dump.

Output: data/processed/sector_priors.json  -> {bucket: success_prior 0..1}

Smoothing: each bucket's rate is pulled toward the global mean with a pseudo-count
(alpha), so thin buckets (e.g. AGRI_FOOD ~41 samples) don't get extreme/unstable priors.
This is a SECTOR-LEVEL aggregate (not row-level target encoding), so leakage is negligible.

Run:  py -3.11 scripts/build_sector_priors.py
"""
import csv
import json
import os
import sys
from collections import Counter

sys.path.insert(0, os.path.dirname(__file__))
from sector_map import canonicalize, bucket_freetext, CANONICAL  # noqa: E402

KAGGLE = "data/raw/kaggle/startup_success_dataset.csv"
CRUNCH = "data/processed/startups_training.csv"
OUT = "data/processed/sector_priors.json"
ALPHA = 50.0  # smoothing strength (pseudo-count toward the global mean)


def main():
    succ, total = Counter(), Counter()

    # Kaggle (rich): outcome Failure/Acquisition/IPO -> 0/1, sector via exact canonicalize
    with open(KAGGLE, encoding="utf-8-sig", newline="") as f:
        for r in csv.DictReader(f):
            o = (r.get("outcome") or "").strip().lower()
            if o not in ("failure", "acquisition", "ipo"):
                continue
            b = canonicalize(r.get("sector"))
            total[b] += 1
            if o in ("acquisition", "ipo"):
                succ[b] += 1

    # Crunchbase (broad sectors): label fail/success, sector via keyword bucket
    if os.path.exists(CRUNCH):
        with open(CRUNCH, encoding="utf-8-sig", newline="") as f:
            for r in csv.DictReader(f):
                if r.get("source") != "crunchbase":
                    continue
                lab = (r.get("label") or "").strip().lower()
                if lab not in ("fail", "success"):
                    continue
                b = bucket_freetext(r.get("sector"))
                total[b] += 1
                if lab == "success":
                    succ[b] += 1

    global_rate = sum(succ.values()) / max(sum(total.values()), 1)
    priors = {}
    for b in CANONICAL:
        n, s = total[b], succ[b]
        priors[b] = round((s + ALPHA * global_rate) / (n + ALPHA), 4)

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    json.dump({"global_rate": round(global_rate, 4), "alpha": ALPHA, "priors": priors},
              open(OUT, "w", encoding="utf-8"), indent=2)

    print(f"global success rate: {global_rate:.3f}")
    print(f"{'bucket':<15}{'n':>8}{'raw%':>8}{'smoothed prior':>16}")
    for b in CANONICAL:
        raw = (100 * succ[b] / total[b]) if total[b] else 0
        print(f"  {b:<13}{total[b]:>8,}{raw:>7.0f}%{priors[b]:>15.3f}")
    print(f"wrote {OUT}")


if __name__ == "__main__":
    main()
