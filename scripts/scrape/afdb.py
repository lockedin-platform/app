#!/usr/bin/env python3
"""
African Development Bank — investment/lending activities.

AfDB publishes all of its activities to IATI (org id XM-DAC-46002). The official
projectsportal.afdb.org is JS-heavy / not reachable for bulk export, so we use the
public d-portal.org IATI datastore (no API key) which mirrors the same IATI data.

Output: data/processed/afdb_activities.csv
  aid, title, description, commitment_usd, spend_usd, day_start, day_end, status_code

Run:  py -3.11 scripts/scrape/afdb.py
"""
import csv
import warnings
import requests

warnings.filterwarnings("ignore")
OUT = "data/processed/afdb_activities.csv"
H = {"User-Agent": "Mozilla/5.0"}
REF = "XM-DAC-46002"


def fetch_all():
    rows, offset, page = [], 0, 500
    while True:
        u = (f"https://d-portal.org/q?reporting_ref={REF}"
             f"&limit={page}&offset={offset}&form=json")
        r = requests.get(u, headers=H, timeout=120, verify=False)
        batch = r.json().get("rows", [])
        if not batch:
            break
        rows.extend(batch)
        print(f"  fetched {len(rows)} activities...")
        if len(batch) < page:
            break
        offset += page
    return rows


def main():
    print("AfDB activities via IATI / d-portal")
    rows = fetch_all()
    cols = ["aid", "title", "description", "commitment", "spend",
            "day_start", "day_end", "status_code"]
    with open(OUT, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["aid", "title", "description", "commitment_usd", "spend_usd",
                    "day_start", "day_end", "status_code"])
        for r in rows:
            w.writerow([r.get(c, "") for c in cols])
    print(f"  wrote {OUT} ({len(rows)} rows)")


if __name__ == "__main__":
    main()
