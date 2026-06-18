#!/usr/bin/env python3
"""
MAGNiTT — MENA venture funding (aggregate KPIs).

Reality check: MAGNiTT's row-level deal database is PAYWALLED, and their free
report PDFs are image-only (no extractable text, would need OCR). The genuinely
free MENA data is the published HEADLINE figures from their annual reports + press
coverage. Those are captured here with their sources.

The raw FY2023 report PDF is kept at data/raw/magnitt/ for manual/OCR use later.

Output: data/processed/magnitt_mena_kpis.csv

Run:  py -3.11 scripts/scrape/magnitt.py
"""
import csv

OUT = "data/processed/magnitt_mena_kpis.csv"

# Published MENA full-year figures. amount_usd in absolute USD.
# 'approx' flag = derived from a reported YoY % change rather than a stated absolute.
ROWS = [
    # year, total_funding_usd, num_deals, approx, source_url
    (2021, 2_600_000_000, 590, False, "https://magnitt.com/research/state-of-startup-funding-2022-50796"),
    (2022, 3_153_000_000, 627, False, "https://magnitt.com/research/2022-mena-venture-investment-report-50849"),
    (2023, 2_428_000_000, 414, True,  "https://magnitt.com/research/2023-mena-venture-investment-summary-50906"),  # -23% funding, -34% deals vs 2022
    (2024, 1_900_000_000, "", False,  "https://magnitt.com/research/2024-MENA-Venture-Investment-Premium-Report-50966"),
]


def main():
    with open(OUT, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["year", "source", "region", "total_funding_usd",
                    "num_deals", "is_approx", "source_url"])
        for year, amt, deals, approx, url in ROWS:
            w.writerow([year, "MAGNiTT", "MENA", amt, deals, int(approx), url])
    print(f"  wrote {OUT} ({len(ROWS)} rows)")
    print("  note: 2023 values derived from reported YoY % (is_approx=1); "
          "raw FY2023 report PDF kept in data/raw/magnitt/ for OCR if needed.")


if __name__ == "__main__":
    main()
