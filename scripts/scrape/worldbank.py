#!/usr/bin/env python3
"""
World Bank macro indicators — UNIFIED auto-pull (all 9, from the free API).

Replaces the manual CSV downloads: pulls every indicator Model 4 uses straight from
the World Bank API and writes them in the SDMX column subset scripts/consolidate.py reads.
World Bank macro data updates ~once a year, so run this on a yearly (or quarterly) schedule —
no per-request API calls, no manual downloads.

Yearly refresh (one go):
    py -3.11 scripts/scrape/worldbank.py
    py -3.11 scripts/consolidate.py
    DATABASE_URL_ML="postgresql://..." py -3.11 scripts/load_to_db.py

Schedule it:
  - Linux/server cron (1st of January, 03:00):
      0 3 1 1 * cd /app && py -3.11 scripts/scrape/worldbank.py && py -3.11 scripts/consolidate.py && DATABASE_URL_ML=... py -3.11 scripts/load_to_db.py
  - Windows Task Scheduler: schedule the same three commands yearly.
"""
import csv
import os
import warnings
import requests

warnings.filterwarnings("ignore")
OUT = "data/raw/worldbank"
H = {"User-Agent": "Mozilla/5.0"}
COLS = ["REF_AREA", "REF_AREA_LABEL", "INDICATOR", "INDICATOR_LABEL",
        "TIME_PERIOD", "OBS_VALUE", "UNIT_MEASURE"]

# indicator code -> canonical label (MUST match WB_WIDE_RENAME keys in load_to_db.py)
INDICATORS = {
    "NY.GDP.MKTP.KD.ZG": "GDP growth (annual %)",
    "NY.GDP.PCAP.CD": "GDP per capita (current US$)",
    "FP.CPI.TOTL.ZG": "Inflation, consumer prices (annual %)",
    "SL.UEM.TOTL.NE.ZS": "Unemployment, total (% of total labor force) (national estimate)",
    "SE.SEC.ENRR": "School enrollment, secondary (% gross)",
    "IQ.CPA.BREG.XQ": "CPIA business regulatory environment rating (1=low to 6=high)",
    "PA.NUS.FCRF": "Official exchange rate (LCU per US$, period average)",
    "GOV_WGI_PV.EST": "Political Stability and Absence of Violence/Terrorism: Estimate",
    "BX.KLT.DINV.WD.GD.ZS": "Foreign direct investment, net inflows (% of GDP)",
}


def _get_json(url, tries=5):
    last = None
    for i in range(tries):
        try:
            return requests.get(url, headers=H, timeout=45, verify=False).json()
        except Exception as e:  # noqa: BLE001 (network or JSONDecodeError — both transient)
            last = e
            print(f"     retry {i + 1}/{tries} ({type(e).__name__})")
    raise last


def fetch(code, label):
    url = (f"https://api.worldbank.org/v2/country/all/indicator/{code}"
           f"?format=json&per_page=25000&date=1990:2024")
    js = _get_json(url)
    rows = []
    if not isinstance(js, list) or len(js) < 2 or js[1] is None:
        return rows
    for d in js[1]:
        if d.get("value") is None:
            continue
        rows.append([d.get("countryiso3code", ""), (d.get("country") or {}).get("value", ""),
                     code, label, d.get("date", ""), d.get("value", ""), ""])
    return rows


def main():
    os.makedirs(OUT, exist_ok=True)
    total = 0
    for code, label in INDICATORS.items():
        rows = fetch(code, label)
        fn = f"{OUT}/WB_API_{code.replace('.', '_')}.csv"
        with open(fn, "w", newline="", encoding="utf-8") as f:
            w = csv.writer(f)
            w.writerow(COLS)
            w.writerows(rows)
        total += len(rows)
        print(f"  {code:<22} {len(rows):>6,} obs -> {os.path.basename(fn)}")
    print(f"  TOTAL {total:,} observations across {len(INDICATORS)} indicators")
    print("  next: py -3.11 scripts/consolidate.py  &&  load_to_db.py")


if __name__ == "__main__":
    main()
