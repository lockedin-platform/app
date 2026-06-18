#!/usr/bin/env python3
"""
Build model-ready startup training tables from the raw Kaggle CSVs.

The raw startup CSVs come in 4 different schemas, so a blind union would be mostly
empty. Instead we:

  1. startups_failures_combined.csv  - the 7 "Startup Failure*" files stacked
                                       (same schema family; all label=fail + failure reasons)
  2. startups_training.csv           - ALL sources harmonised onto a common core schema
                                       with a normalised `label` {success, fail, operating}
                                       and a `source` column  -> unified view + Crunchbase validation

The rich datasets keep their full feature sets in data/raw/kaggle/ — for the first
Scoring model, train primarily on startup_success_dataset.csv (100k rows, clean numerics).

Run:  py -3.11 scripts/build_training_set.py
"""
import csv
import glob
import os
import re

RAW = "data/raw/kaggle"
OUT = "data/processed"

CORE = ["source", "company", "sector", "country_region", "founded_year",
        "funding_total_usd", "funding_rounds", "team_size", "revenue_musd",
        "outcome_raw", "label", "failure_reason"]


def year_of(s):
    m = re.search(r"(19|20)\d{2}", str(s or ""))
    return m.group(0) if m else ""


def num(s):
    s = re.sub(r"[^0-9.\-]", "", str(s or ""))
    return s if s not in ("", "-", ".") else ""


def read(path):
    with open(path, encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f)), f


def label_from(raw, mapping):
    return mapping.get((raw or "").strip().lower(), "")


def combine_failures():
    files = [p for p in glob.glob(f"{RAW}/*.csv")
             if os.path.basename(p).lower().startswith("startup failure")]
    if not files:
        return []
    cols, parsed = [], []
    for p in files:
        with open(p, encoding="utf-8-sig", newline="") as f:
            r = csv.DictReader(f)
            rows = list(r)
            parsed.append((os.path.basename(p), rows))
            for c in (r.fieldnames or []):
                if c not in cols:
                    cols.append(c)
    with open(f"{OUT}/startups_failures_combined.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["__source_file"] + cols)
        w.writeheader()
        for fname, rows in parsed:
            for row in rows:
                out = {"__source_file": fname}
                out.update({c: row.get(c, "") for c in cols})
                w.writerow(out)
    total = sum(len(r) for _, r in parsed)
    print(f"  startups_failures_combined.csv: {len(files)} files -> {total} rows")
    return parsed


def harmonise(failure_parsed):
    rows = []

    # A) startup_success_dataset.csv (100k, numeric features + outcome)
    p = f"{RAW}/startup_success_dataset.csv"
    if os.path.exists(p):
        m = {"failure": "fail", "acquisition": "success", "ipo": "success"}
        for r in read(p)[0]:
            rows.append({"source": "kaggle_success", "company": "",
                         "sector": r.get("sector", ""), "country_region": "",
                         "founded_year": "", "funding_total_usd": "",
                         "funding_rounds": r.get("funding_rounds", ""),
                         "team_size": r.get("team_size", ""),
                         "revenue_musd": r.get("revenue_million", ""),
                         "outcome_raw": r.get("outcome", ""),
                         "label": label_from(r.get("outcome"), m), "failure_reason": ""})

    # B) big_startup_secsees (Crunchbase)
    p = f"{RAW}/big_startup_secsees_dataset.csv"
    if os.path.exists(p):
        m = {"closed": "fail", "acquired": "success", "ipo": "success", "operating": "operating"}
        for r in read(p)[0]:
            ft = num(r.get("funding_total_usd", ""))
            rows.append({"source": "crunchbase", "company": r.get("name", ""),
                         "sector": r.get("category_list", ""),
                         "country_region": r.get("country_code", "") or r.get("region", ""),
                         "founded_year": year_of(r.get("founded_at", "")),
                         "funding_total_usd": ft,
                         "funding_rounds": r.get("funding_rounds", ""),
                         "team_size": "", "revenue_musd": "",
                         "outcome_raw": r.get("status", ""),
                         "label": label_from(r.get("status"), m), "failure_reason": ""})

    # C) startup_data.csv (500)
    p = f"{RAW}/startup_data.csv"
    if os.path.exists(p):
        m = {"acquired": "success", "ipo": "success", "private": "operating"}
        for r in read(p)[0]:
            amt = num(r.get("Funding Amount (M USD)", ""))
            ft = str(int(float(amt) * 1_000_000)) if amt else ""
            rows.append({"source": "kaggle_startupdata", "company": r.get("Startup Name", ""),
                         "sector": r.get("Industry", ""), "country_region": r.get("Region", ""),
                         "founded_year": year_of(r.get("Year Founded", "")),
                         "funding_total_usd": ft,
                         "funding_rounds": r.get("Funding Rounds", ""),
                         "team_size": r.get("Employees", ""),
                         "revenue_musd": r.get("Revenue (M USD)", ""),
                         "outcome_raw": r.get("Exit Status", ""),
                         "label": label_from(r.get("Exit Status"), m), "failure_reason": ""})

    # D) failure family (all fail)
    for fname, frows in failure_parsed:
        for r in frows:
            if not (r.get("Name") or "").strip():
                continue
            rows.append({"source": "kaggle_failures", "company": r.get("Name", ""),
                         "sector": r.get("Sector", ""), "country_region": "",
                         "founded_year": "", "funding_total_usd": "",
                         "funding_rounds": "", "team_size": "", "revenue_musd": "",
                         "outcome_raw": "failed", "label": "fail",
                         "failure_reason": r.get("Why They Failed", "")})

    with open(f"{OUT}/startups_training.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=CORE)
        w.writeheader()
        w.writerows(rows)

    from collections import Counter
    by_src = Counter(r["source"] for r in rows)
    by_lab = Counter(r["label"] or "(blank)" for r in rows)
    print(f"  startups_training.csv: {len(rows):,} rows")
    print(f"    by source: {dict(by_src)}")
    print(f"    by label : {dict(by_lab)}")


def main():
    os.makedirs(OUT, exist_ok=True)
    fp = combine_failures()
    harmonise(fp)
    print("Done -> data/processed/startups_failures_combined.csv, startups_training.csv")


if __name__ == "__main__":
    main()
