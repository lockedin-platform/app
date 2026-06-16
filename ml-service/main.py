"""
LockedIn ML service (FastAPI).

This is the Python side of the stack from the Strategy Report:
  Symfony (PHP) = website/API     |     FastAPI (Python) = ML model serving

It runs as a SEPARATE process next to Symfony. Symfony calls it over HTTP
(e.g. POST http://127.0.0.1:8001/score) to get model predictions.

STATUS: skeleton only — NO model is trained yet (by decision). /score returns a
transparent, rule-based PLACEHOLDER with `"model": "stub"` so the Symfony side can
integrate against a stable contract today; we swap the stub for the real XGBoost
model (trained on ml_data.scoring_training_features) without changing the API.

Run:
    cd ml-service
    python -m venv .venv && .venv\\Scripts\\activate     (Windows)
    pip install -r requirements.txt
    uvicorn main:app --host 127.0.0.1 --port 8001 --reload
"""
from __future__ import annotations

from typing import Optional
from fastapi import FastAPI
from pydantic import BaseModel, Field

app = FastAPI(title="LockedIn ML Service", version="0.1.0")

# The exact feature set the model is/will be trained on
# (data/processed/scoring_training_features.csv). Keep this in sync with the
# platform submission form so training features == inference features.
class ScoreRequest(BaseModel):
    sector: Optional[str] = None
    team_size: Optional[int] = Field(default=None, ge=0)
    founder_experience_years: Optional[int] = Field(default=None, ge=0)
    product_traction_users: Optional[int] = Field(default=None, ge=0)
    market_size_billion: Optional[float] = Field(default=None, ge=0)
    revenue_million: Optional[float] = Field(default=None, ge=0)
    burn_rate_million: Optional[float] = Field(default=None, ge=0)
    country: Optional[str] = None  # used by the future macro-risk join (Model 4)


class ScoreResponse(BaseModel):
    score: float                       # 0..100 investment-readiness score
    confidence: float                  # 0..1 — low until a real model + data exist
    drivers: dict                      # which inputs pushed the score up/down
    model: str                         # "stub" now, "xgboost-v1" later


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "service": "lockedin-ml", "model_loaded": False}


@app.post("/score", response_model=ScoreResponse)
def score(req: ScoreRequest) -> ScoreResponse:
    """
    PLACEHOLDER scoring. Transparent, monotonic heuristic on the funding-independent
    features so the endpoint is usable end-to-end before the model is trained.
    Replace the body with: load XGBoost -> predict_proba -> map to 0..100.
    """
    s = 50.0
    drivers: dict = {}

    if req.founder_experience_years is not None:
        bump = min(req.founder_experience_years, 12) * 1.5
        s += bump; drivers["founder_experience_years"] = round(bump, 1)
    if req.team_size is not None:
        bump = min(req.team_size, 10) * 1.2
        s += bump; drivers["team_size"] = round(bump, 1)
    if req.product_traction_users is not None:
        bump = min(req.product_traction_users / 1000.0, 15)
        s += bump; drivers["product_traction_users"] = round(bump, 1)
    if req.market_size_billion is not None:
        bump = min(req.market_size_billion, 10)
        s += bump; drivers["market_size_billion"] = round(bump, 1)
    if req.revenue_million is not None and req.burn_rate_million:
        ratio = req.revenue_million / req.burn_rate_million
        bump = max(min((ratio - 1) * 5, 10), -10)
        s += bump; drivers["revenue_vs_burn"] = round(bump, 1)

    score_val = max(0.0, min(100.0, s))
    # confidence stays deliberately low: this is a heuristic, not a trained model
    filled = sum(v is not None for v in req.model_dump().values())
    confidence = round(min(0.15 + filled * 0.05, 0.5), 2)
    return ScoreResponse(score=round(score_val, 1), confidence=confidence,
                         drivers=drivers, model="stub")
