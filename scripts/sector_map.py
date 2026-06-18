#!/usr/bin/env python3
"""
Sector taxonomy — the single source of truth that maps BOTH the Kaggle training
sectors AND the LockedIn platform form sectors into one shared set of buckets.

Why: the Kaggle success dataset has 7 tech-centric sectors (Crypto, SaaS, AI...);
the platform form has 15 real-economy sectors (Agriculture, Tourism, Crafts...).
Only "Health" overlapped, so the raw `sector` feature didn't generalise. Mapping
both sides to canonical buckets fixes Model 1's vocabulary mismatch.

Honest note: several real-economy sectors map to OTHER because the Kaggle data has
NO examples of them. That's correct — it makes the gap explicit (the model treats
them as a generic bucket) instead of pretending it learned something it didn't.
Those buckets fill in once we retrain on our own platform submissions.
"""

CANONICAL = ["TECH", "FINTECH", "HEALTH", "COMMERCE", "AGRI_FOOD",
             "ENERGY_CLIMATE", "EDUCATION", "OTHER"]

# lower-cased raw sector -> canonical bucket
SECTOR_TO_CANONICAL = {
    # --- Kaggle training sectors ---
    "saas": "TECH", "ai": "TECH", "crypto": "FINTECH", "fintech": "FINTECH",
    "health": "HEALTH", "ecommerce": "COMMERCE", "climate": "ENERGY_CLIMATE",
    # --- LockedIn platform form sectors (English) ---
    "technology": "TECH", "finance": "FINTECH", "education": "EDUCATION",
    "commerce": "COMMERCE", "agriculture": "AGRI_FOOD", "food & beverage": "AGRI_FOOD",
    "energy": "ENERGY_CLIMATE",
    "tourism": "OTHER", "real estate": "OTHER", "transport": "OTHER",
    "fashion & textile": "OTHER", "industry": "OTHER", "services": "OTHER", "crafts": "OTHER",
    # --- platform form sectors (French labels in ProjetController::SECTEURS) ---
    "technologie": "TECH", "sante": "HEALTH", "santé": "HEALTH", "education ": "EDUCATION",
    "éducation": "EDUCATION", "tourisme": "OTHER", "immobilier": "OTHER",
    "energie": "ENERGY_CLIMATE", "énergie": "ENERGY_CLIMATE", "alimentation": "AGRI_FOOD",
    "mode & textile": "OTHER", "industrie": "OTHER", "artisanat": "OTHER",
}


def canonicalize(sector: str | None) -> str:
    if not sector:
        return "OTHER"
    return SECTOR_TO_CANONICAL.get(sector.strip().lower(), "OTHER")


# Keyword buckets for FREE-TEXT categories (e.g. Crunchbase "Agriculture|Biotech").
# Order matters: first match wins.
FREETEXT_KEYWORDS = [
    ("AGRI_FOOD", ["agricultur", "farm", "agtech", "food", "beverage"]),
    ("EDUCATION", ["education", "edtech", "e-learning", "school", "training", "teach"]),
    ("FINTECH", ["fintech", "finance", "banking", "payment", "insurance", "crypto", "lending"]),
    ("HEALTH", ["health", "medical", "biotech", "pharma", "wellness"]),
    ("ENERGY_CLIMATE", ["energy", "clean tech", "cleantech", "solar", "climate", "renewable"]),
    ("COMMERCE", ["commerce", "retail", "marketplace", "shopping", "ecommerce"]),
    ("TECH", ["software", "saas", "artificial intelligence", "analytics", "cloud",
              "developer", "mobile", "web", "data"]),
    ("OTHER", ["travel", "tourism", "real estate", "property", "transport", "logistic",
               "fashion", "apparel", "textile", "craft", "manufactur", "construction"]),
]


def bucket_freetext(text: str | None) -> str:
    if not text:
        return "OTHER"
    s = text.lower()
    for bucket, kws in FREETEXT_KEYWORDS:
        if any(w in s for w in kws):
            return bucket
    return "OTHER"


if __name__ == "__main__":
    # quick self-check
    for s in ["SaaS", "Agriculture", "Tourism", "Crafts", "Fintech", "Health", "UnknownX"]:
        print(f"  {s:<14} -> {canonicalize(s)}")
