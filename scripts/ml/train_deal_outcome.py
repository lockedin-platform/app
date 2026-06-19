#!/usr/bin/env python3
"""
Model 6 — Deal Outcome Prediction. Trained, and SCALE-ROBUST.

Problem: the Kaggle training data is enterprise-scale (median team 151, revenue 621k "M").
A model trained on raw/log values saturates to 0 on real pre-seed inputs (team 4, revenue 0.2M).

Fix: train on each feature's PERCENTILE RANK (uniform 0..1) so the model only learns the
monotonic "higher rank -> more likely success" relationship. At inference the FastAPI service
maps a founder's REAL values to 0..1 against realistic startup ranges (see ml-service/main.py),
so different real startups land at different points and the model discriminates them.

Run:  py -3.11 scripts/ml/train_deal_outcome.py
"""
import csv
import os
import numpy as np
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import cross_val_score
import joblib

FEATURES = ["founder_experience_years", "log_team_size", "log_traction",
            "log_revenue", "sector_success_prior"]
SRC = "data/processed/model1_training.csv"
OUT = "ml-service/models/deal_outcome.joblib"


def rank01(col: np.ndarray) -> np.ndarray:
    order = col.argsort()
    ranks = np.empty_like(order, dtype=float)
    ranks[order] = np.arange(len(col))
    return ranks / max(len(col) - 1, 1)


def main():
    if not os.path.exists(SRC):
        raise SystemExit(f"missing {SRC}")
    rows = [r for r in csv.DictReader(open(SRC, encoding="utf-8"))]
    X, y = [], []
    for r in rows:
        try:
            X.append([float(r[f]) for f in FEATURES]); y.append(int(r["label"]))
        except (ValueError, KeyError):
            continue
    X, y = np.array(X), np.array(y)
    Xr = np.column_stack([rank01(X[:, i]) for i in range(X.shape[1])])  # per-feature 0..1 rank
    print(f"rows={len(y):,}  success_rate={y.mean():.3f}")

    clf = LogisticRegression(max_iter=1000)
    auc = cross_val_score(clf, Xr, y, cv=5, scoring="roc_auc")
    print(f"5-fold CV AUC (rank features) = {auc.mean():.3f} +/- {auc.std():.3f}")

    clf.fit(Xr, y)
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    joblib.dump({"clf": clf, "features": FEATURES, "cv_auc": round(float(auc.mean()), 3),
                 "model": "logreg-rank-deal-outcome-v2",
                 "note": "features are 0..1 (rank in training / realistic-range at inference)"}, OUT)
    print(f"saved {OUT}  weights={dict(zip(FEATURES, clf.coef_[0].round(2)))}")


if __name__ == "__main__":
    main()
