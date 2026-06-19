#!/usr/bin/env python3
"""
Model 3 — Fraud & Quality Detection. Trained UNSUPERVISED anomaly detector (no labels needed).

The rule-based SubmissionQualityService catches obvious junk (gibberish, duplicates, absurd
numbers). This adds a learned layer: an IsolationForest trained on the distribution of real
startup feature vectors, so submissions that are statistical outliers (very unusual
team/traction/experience/financials combos) get flagged for review even if they pass the rules.

Saves the fitted pipeline; FastAPI serves an anomaly score per submission.

Run:  py -3.11 scripts/ml/train_fraud_detector.py
"""
import csv
import os
import numpy as np
from sklearn.ensemble import IsolationForest
import joblib

FEATURES = ["founder_experience_years", "log_team_size", "log_traction",
            "log_revenue", "sector_success_prior"]
SRC = "data/processed/model1_training.csv"
OUT = "ml-service/models/fraud_detector.joblib"


def rank01(col: np.ndarray) -> np.ndarray:
    order = col.argsort()
    ranks = np.empty_like(order, dtype=float)
    ranks[order] = np.arange(len(col))
    return ranks / max(len(col) - 1, 1)


def main():
    if not os.path.exists(SRC):
        raise SystemExit(f"missing {SRC}")
    X = []
    for r in csv.DictReader(open(SRC, encoding="utf-8")):
        try:
            X.append([float(r[f]) for f in FEATURES])
        except (ValueError, KeyError):
            continue
    X = np.array(X)
    # same 0..1 rank space the serving layer feeds (realistic-range normalised at inference)
    Xr = np.column_stack([rank01(X[:, i]) for i in range(X.shape[1])])
    print(f"rows={len(X):,}")

    clf = IsolationForest(n_estimators=200, contamination=0.03, random_state=42)
    clf.fit(Xr)
    print(f"anomalies flagged on training data: {(clf.predict(Xr) == -1).mean()*100:.1f}%")

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    joblib.dump({"clf": clf, "features": FEATURES, "model": "isoforest-rank-fraud-v2"}, OUT)
    print(f"saved {OUT}")


if __name__ == "__main__":
    main()
