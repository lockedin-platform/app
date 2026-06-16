# LockedIn ML Service (FastAPI)

The Python half of the stack (Strategy Report): **Symfony = website**, **FastAPI = ML serving**.
Separate process; Symfony talks to it over HTTP.

## Run
```bash
cd ml-service
python -m venv .venv
.venv\Scripts\activate            # Windows  (source .venv/bin/activate on Linux/Mac)
pip install -r requirements.txt
uvicorn main:app --host 127.0.0.1 --port 8001 --reload
```

## Endpoints
- `GET /health` → `{"status":"ok","model_loaded":false}`
- `POST /score` → investment-readiness score 0–100 + confidence + drivers.
  Body = the funding-independent feature set (sector, team_size,
  founder_experience_years, product_traction_users, market_size_billion,
  revenue_million, burn_rate_million, country).

## Status
**Skeleton only — no model trained yet (by decision).** `/score` returns a transparent
heuristic with `"model":"stub"` so Symfony can integrate now. When ready:
1. Train XGBoost on `ml_data.scoring_training_features` (Colab/Kaggle, free GPU).
2. Save the model (`joblib`), load it in `main.py`, replace the stub body with
   `predict_proba` → 0–100. The API contract stays the same.

## How Symfony calls it (later)
In `ProjetScoringService`, instead of the hardcoded formula, POST the project's
features to `http://127.0.0.1:8001/score` and store the returned score/drivers.
Keep the current rule-based scorer as a fallback when the service is down.
