#!/usr/bin/env python3
"""
Consolidate all collected data into model-ready CSVs.

Produces (in data/processed/):
  worldbank_long.csv   - tidy: country_code, country, indicator_code, indicator, year, value, unit
  worldbank_wide.csv   - MODEL-READY: one row per (country, year), one column per indicator
                         -> feeds Model 4 (Macroeconomic Risk Model)
  startups_combined.csv - all startup CSVs in data/raw/kaggle/ stacked (union of columns,
                         + __source_file column)  -> feeds Model 1 (Scoring) + Model 5 (Fraud)

Re-run any time you add more CSVs to data/raw/kaggle/ or data/raw/worldbank/.

Run:  py -3.11 scripts/consolidate.py
"""
import csv
import glob
import os
from collections import defaultdict

RAW_WB = "data/raw/worldbank"
RAW_KAGGLE = "data/raw/kaggle"
OUT = "data/processed"


def consolidate_worldbank():
    files = sorted(glob.glob(f"{RAW_WB}/*.csv"))
    if not files:
        print("  worldbank: no files")
        return
    long_rows = []
    # wide[(area,year)][indicator_label] = value ; keep country label + indicator set
    wide = defaultdict(dict)
    country_name = {}
    indicators = set()

    for path in files:
        with open(path, encoding="utf-8-sig") as f:
            for row in csv.DictReader(f):
                area = row.get("REF_AREA", "")
                cname = row.get("REF_AREA_LABEL", "")
                icode = row.get("INDICATOR", "")
                ilabel = row.get("INDICATOR_LABEL", "")
                year = row.get("TIME_PERIOD", "")
                val = row.get("OBS_VALUE", "")
                unit = row.get("UNIT_MEASURE", "")
                if not area or not year or val == "":
                    continue
                long_rows.append([area, cname, icode, ilabel, year, val, unit])
                country_name[area] = cname
                wide[(area, year)][ilabel] = val
                indicators.add(ilabel)

    os.makedirs(OUT, exist_ok=True)
    with open(f"{OUT}/worldbank_long.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["country_code", "country", "indicator_code", "indicator", "year", "value", "unit"])
        w.writerows(long_rows)

    inds = sorted(indicators)
    with open(f"{OUT}/worldbank_wide.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["country_code", "country", "year"] + inds)
        for (area, year) in sorted(wide.keys()):
            w.writerow([area, country_name.get(area, ""), year] + [wide[(area, year)].get(i, "") for i in inds])

    print(f"  worldbank: {len(files)} files -> {len(long_rows):,} long rows; "
          f"wide {len(wide):,} (country,year) rows x {len(inds)} indicators")
    print(f"    indicators: {inds}")


def consolidate_startups():
    files = sorted(glob.glob(f"{RAW_KAGGLE}/*.csv"))
    if not files:
        print("  startups: no files in data/raw/kaggle/ yet (drop your Kaggle CSVs there)")
        return
    # union of all columns across files
    all_cols, parsed = [], []
    for path in files:
        with open(path, encoding="utf-8-sig", newline="") as f:
            rdr = csv.DictReader(f)
            rows = list(rdr)
            parsed.append((os.path.basename(path), rdr.fieldnames or [], rows))
            for c in (rdr.fieldnames or []):
                if c not in all_cols:
                    all_cols.append(c)

    header = ["__source_file"] + all_cols
    total = 0
    with open(f"{OUT}/startups_combined.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=header)
        w.writeheader()
        for fname, cols, rows in parsed:
            for r in rows:
                out = {"__source_file": fname}
                out.update({c: r.get(c, "") for c in all_cols})
                w.writerow(out)
                total += 1
    print(f"  startups: {len(files)} files -> {total:,} rows, {len(all_cols)} union columns")
    print(f"    files: {[p[0] for p in parsed]}")


def main():
    os.makedirs(OUT, exist_ok=True)
    print("Consolidating World Bank indicators...")
    consolidate_worldbank()
    print("Consolidating startup datasets...")
    consolidate_startups()
    print("Done -> data/processed/")


if __name__ == "__main__":
    main()
