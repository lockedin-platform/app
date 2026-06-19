"""
LockedIn ML service (FastAPI) — now serving TRAINED models.

  Symfony (PHP) = website/API     |     FastAPI (Python) = ML model serving

Endpoints:
  GET  /health  -> status + which models are loaded
  POST /score   -> Model 6 (Deal Outcome): success probability 0-100 + drivers   [logreg, CV AUC 0.80]
  POST /detect  -> Model 3 (Fraud/Quality): anomaly score + is_suspicious          [IsolationForest]

Models are loaded from ml-service/models/*.joblib (trained by scripts/ml/train_*.py).
If a model file is missing the endpoint falls back to a transparent heuristic so the API
never hard-fails.

Run:
    cd ml-service && pip install -r requirements.txt
    uvicorn main:app --host 127.0.0.1 --port 8001 --reload
"""
from __future__ import annotations

import math
import os
from typing import Optional

import joblib
import numpy as np
from fastapi import FastAPI
from pydantic import BaseModel, Field

app = FastAPI(title="LockedIn ML Service", version="1.0.0")

MODELS_DIR = os.path.join(os.path.dirname(__file__), "models")

# --- sector -> canonical bucket -> success prior (mirror of scripts/sector_map.py) ---
SECTOR_MAP = {
    "saas": "TECH", "ai": "TECH", "technology": "TECH", "technologie": "TECH",
    "crypto": "FINTECH", "fintech": "FINTECH", "finance": "FINTECH",
    "health": "HEALTH", "sante": "HEALTH", "santé": "HEALTH",
    "ecommerce": "COMMERCE", "commerce": "COMMERCE",
    "climate": "ENERGY_CLIMATE", "energy": "ENERGY_CLIMATE", "energie": "ENERGY_CLIMATE",
    "agriculture": "AGRI_FOOD", "food & beverage": "AGRI_FOOD", "alimentation": "AGRI_FOOD",
    "education": "EDUCATION", "éducation": "EDUCATION",
}
PRIORS = {"TECH": 0.467, "FINTECH": 0.445, "HEALTH": 0.477, "COMMERCE": 0.446,
          "AGRI_FOOD": 0.436, "ENERGY_CLIMATE": 0.447, "EDUCATION": 0.495, "OTHER": 0.396}
GLOBAL_PRIOR = 0.454


def sector_prior(sector: Optional[str]) -> float:
    if not sector:
        return GLOBAL_PRIOR
    return PRIORS.get(SECTOR_MAP.get(sector.strip().lower(), "OTHER"), GLOBAL_PRIOR)


def _load(name: str):
    path = os.path.join(MODELS_DIR, name)
    try:
        return joblib.load(path)
    except Exception:
        return None


DEAL = _load("deal_outcome.joblib")
FRAUD = _load("fraud_detector.joblib")


class Features(BaseModel):
    sector: Optional[str] = None
    team_size: Optional[int] = Field(default=None, ge=0)
    founder_experience_years: Optional[int] = Field(default=None, ge=0)
    product_traction_users: Optional[int] = Field(default=None, ge=0)
    revenue_million: Optional[float] = Field(default=None, ge=0)
    country: Optional[str] = None  # reserved for the macro-risk join


def _clamp01(v: float) -> float:
    return max(0.0, min(1.0, v))


def to_vector(req: Features) -> np.ndarray:
    """
    Map a founder's REAL values to 0..1 against realistic pre-seed startup ranges, in the same
    feature order as training. The model was trained on per-feature ranks (also 0..1), so it
    applies the learned monotonic "higher -> better" relation to where this startup sits among
    realistic peers — sidestepping the enterprise-scale mismatch in the raw training data.
    """
    return np.array([[
        _clamp01((req.founder_experience_years or 0) / 20.0),                       # 0–20 yrs
        _clamp01(math.log1p(req.team_size or 0) / math.log1p(30)),                  # ~1–30 people
        _clamp01(math.log1p(req.product_traction_users or 0) / math.log1p(50000)),  # ~0–50k users
        _clamp01(math.log1p(req.revenue_million or 0) / math.log1p(5)),             # ~0–5M
        _clamp01((sector_prior(req.sector) - 0.39) / 0.11),                         # prior 0.39–0.50
    ]])


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "service": "lockedin-ml",
            "models": {"deal_outcome": DEAL is not None, "fraud_detector": FRAUD is not None}}


@app.post("/score")
def score(req: Features) -> dict:
    """Model 6 — investment-readiness / deal-outcome probability (0-100)."""
    x = to_vector(req)
    if DEAL is not None:
        prob = float(DEAL["clf"].predict_proba(x)[0][1])
        filled = sum(v is not None for v in req.model_dump().values())
        return {"score": round(prob * 100, 1),
                "confidence": round(min(0.3 + filled * 0.07, 0.85), 2),
                "model": DEAL.get("model", "deal_outcome"),
                "cv_auc": DEAL.get("cv_auc")}
    # fallback heuristic if the model file is missing
    s = 50 + (req.founder_experience_years or 0) * 1.5
    return {"score": round(max(0, min(100, s)), 1), "confidence": 0.2, "model": "stub"}


@app.post("/detect")
def detect(req: Features) -> dict:
    """Model 3 — fraud/quality anomaly check."""
    x = to_vector(req)
    if FRAUD is not None:
        clf = FRAUD["clf"]
        is_anom = int(clf.predict(x)[0]) == -1
        raw = float(clf.decision_function(x)[0])  # <0 = more anomalous
        return {"is_suspicious": is_anom, "anomaly_score": round(-raw, 4),
                "model": FRAUD.get("model", "fraud_detector")}
    return {"is_suspicious": False, "anomaly_score": 0.0, "model": "stub"}
