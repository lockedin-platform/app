#!/usr/bin/env python3
"""
Disrupt Africa — African Tech Startups Funding Report scraper.

The reports are FREE PDFs. They are analytical (aggregates by country / sector /
year), 2-column layout. We:
  1. Download each yearly report (idempotent).
  2. Extract clean, column-aware text  -> data/processed/disrupt_africa_<year>.txt
     (this is the corpus for the Phase 3 RAG chatbot + African market context)
  3. Regex out the headline KPIs        -> data/processed/disrupt_africa_kpis.csv
     (total funding, #startups, avg deal size per year — for the risk/context models)

Run:  py -3.11 scripts/scrape/disrupt_africa.py
"""
import os
import re
import csv
import warnings
import requests
import pdfplumber

warnings.filterwarnings("ignore")  # silence InsecureRequestWarning

RAW = "data/raw/disrupt_africa"
OUT = "data/processed"
UA = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}

REPORTS = {
    2024: "https://disruptafrica.com/wp-content/uploads/2025/03/The-African-Tech-Startups-Funding-Report-2024.pdf",
    2023: "https://disruptafrica.com/wp-content/uploads/2024/01/The-African-Tech-Startups-Funding-Report-2023.pdf",
    2022: "https://old.disruptafrica.com/wp-content/uploads/2023/02/The-African-Tech-Startups-Funding-Report-2022.pdf",
}


def download():
    os.makedirs(RAW, exist_ok=True)
    paths = {}
    for year, url in REPORTS.items():
        p = f"{RAW}/DisruptAfrica_Funding_{year}.pdf"
        if not os.path.exists(p) or os.path.getsize(p) < 50_000:
            r = requests.get(url, headers=UA, timeout=120, verify=False)
            open(p, "wb").write(r.content)
            print(f"  downloaded {year}: {len(r.content)//1024} KB")
        else:
            print(f"  cached {year}")
        paths[year] = p
    return paths


def column_text(page):
    """Split a 2-column page at the mid-x and read left column then right column."""
    mid = page.width / 2
    left = page.crop((0, 0, mid, page.height)).extract_text() or ""
    right = page.crop((mid, 0, page.width, page.height)).extract_text() or ""
    return (left + "\n" + right).strip()


def extract_text(path, year):
    os.makedirs(OUT, exist_ok=True)
    chunks = []
    with pdfplumber.open(path) as pdf:
        for pg in pdf.pages:
            txt = column_text(pg)
            if txt:
                chunks.append(txt)
    full = "\n\n".join(chunks)
    # normalise the weird unicode quotes the report uses
    full = full.replace("", "x").replace("’", "'").replace("“", '"').replace("”", '"')
    open(f"{OUT}/disrupt_africa_{year}.txt", "w", encoding="utf-8").write(full)
    return full


def parse_kpis(text, year):
    """Pull headline numbers. Returns dict or {} if nothing matched."""
    flat = re.sub(r"\s+", " ", text)
    out = {"year": year, "source": "Disrupt Africa", "region": "Africa"}

    # require a large comma-formatted amount so we skip "147 startups raised US$1 million or over"
    m = re.search(r"([0-9]{2,4})\s+(?:African tech\s+)?startups raised\s+(?:a combined(?: total of)?\s+)?US\$([0-9]{1,3}(?:,[0-9]{3}){2,})", flat)
    if m:
        out["funded_startups"] = int(m.group(1))
        out["total_funding_usd"] = int(m.group(2).replace(",", ""))
    m = re.search(r"average deal was(?: up [0-9.]+ per cent to)?\s+US\$([0-9,]+)", flat)
    if m:
        out["avg_deal_usd"] = int(m.group(1).replace(",", ""))
    return out if len(out) > 3 else {}


def main():
    print("Disrupt Africa funding reports")
    paths = download()
    kpis = []
    for year, p in sorted(paths.items()):
        txt = extract_text(p, year)
        print(f"  extracted {year}: {len(txt):,} chars -> data/processed/disrupt_africa_{year}.txt")
        k = parse_kpis(txt, year)
        if k:
            kpis.append(k)
            print(f"     KPIs: {k}")
        else:
            print(f"     KPIs: (none auto-detected — check the .txt manually)")

    if kpis:
        cols = ["year", "source", "region", "funded_startups", "total_funding_usd", "avg_deal_usd"]
        with open(f"{OUT}/disrupt_africa_kpis.csv", "w", newline="", encoding="utf-8") as f:
            w = csv.DictWriter(f, fieldnames=cols)
            w.writeheader()
            for row in kpis:
                w.writerow({c: row.get(c, "") for c in cols})
        print(f"  wrote {OUT}/disrupt_africa_kpis.csv ({len(kpis)} rows)")


if __name__ == "__main__":
    main()
