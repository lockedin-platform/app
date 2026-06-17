#!/usr/bin/env python3
"""
Retrain trigger — the "wait for usage" mechanism (auto-learn loop).

Each model serves a rule-based v0 until enough REAL platform data has accumulated;
then this job trains the ML version and the service hot-swaps it in. The model
"sleeps until the data wakes it up".

This script READS the event log (public.platform_event) + the live training view,
compares counts to per-model thresholds, and reports which models are ready to (re)train.
It does NOT train yet — training code lands when a model crosses its threshold. Schedule
it (cron / a scheduled agent) to run daily.

Run:  DATABASE_URL_ML="postgresql://..." py -3.11 scripts/ml/retrain_trigger.py
"""
import os
import sys
import json
import psycopg2

# model -> (counting query, threshold, what v0 serves meanwhile)
MODELS = {
    "model1_scoring": {
        "sql": "SELECT count(*) FROM public.platform_event WHERE event_type = 'project_evaluated'",
        "threshold": 200,
        "v0": "100k static Kaggle features (scoring_training_features) - retrain on OUR data at threshold",
    },
    "model2_matching": {
        "sql": """SELECT count(*) FROM public.platform_event
                  WHERE event_type IN ('investor_applied','offer_accepted','offer_rejected','application_rejected')""",
        "threshold": 50,
        "v0": "content-based matching (InvestmentMatchingService: sector/budget/risk/horizon)",
    },
    "model5_fraud": {
        "sql": "SELECT count(*) FROM public.platform_event WHERE event_type = 'project_submitted'",
        "threshold": 200,
        "v0": "rule-based quality checks (SubmissionQualityService)",
    },
    "model4_risk": {
        # deals carry the macro_snapshot; once enough have known outcomes we can train the regression
        "sql": "SELECT count(*) FROM public.platform_event WHERE event_type = 'offer_accepted'",
        "threshold": 30,
        "v0": "rule-based lookup on real World Bank data (MacroRiskService)",
    },
}


def main():
    dsn = os.environ.get("DATABASE_URL_ML")
    if not dsn:
        sys.exit("set DATABASE_URL_ML env var")
    conn = psycopg2.connect(dsn, sslmode="require")
    cur = conn.cursor()

    report = {}
    for name, cfg in MODELS.items():
        cur.execute(cfg["sql"])
        count = cur.fetchone()[0]
        ready = count >= cfg["threshold"]
        report[name] = {
            "events": count,
            "threshold": cfg["threshold"],
            "status": "READY_TO_TRAIN" if ready else "SLEEPING",
            "serving": "(will swap to ML)" if ready else cfg["v0"],
        }

    cur.close()
    conn.close()

    print("=== LockedIn retrain trigger ===")
    for name, r in report.items():
        bar = "READY" if r["status"] == "READY_TO_TRAIN" else "sleeping"
        print(f"  {name:<18} {r['events']:>5}/{r['threshold']:<5} {bar}")
        print(f"      serving now: {r['serving']}")
    print()
    print(json.dumps(report))

    # Exit code 10 if ANY model is ready — a cron job can use this to kick off training.
    if any(r["status"] == "READY_TO_TRAIN" for r in report.values()):
        sys.exit(10)


if __name__ == "__main__":
    main()
