#!/usr/bin/env python3
"""Smoke test: log in with each account and GET every parameter-less page, recording HTTP status + error snippets."""
import re
import sys
import json
import requests

BASE = "http://localhost:8000"

ACCOUNTS = {
    "admin": ("testadmin@test.com", "AdminPass1!"),
    "user1": ("testuser1@test.com", "TestUser1!"),
    "user2": ("testuser2@test.com", "TestUser2!"),
}

# Pages to test (parameter-less GET). Skip destructive/logout/export/oauth ones.
SKIP = {
    "/logout", "/connect/google", "/connect/google/callback",
    "/admin/preview/exit",  # only valid mid-preview
    # AI / external-API pages (excluded per request; single-threaded server + slow API):
    "/projets/chatbot", "/mentorat/chatbot",
    "/investissement/advanced/economic-data",
    "/investissement/advanced/economic-dashboard",
    "/investissement/advanced/currency-convert",
    "/investissement/advanced/matching/results",
}

PAGES = [
    "/", "/admin", "/admin/apprentissage/badges", "/admin/apprentissage/badges/new",
    "/admin/apprentissage/cours", "/admin/apprentissage/cours/new", "/admin/apprentissage/progressions",
    "/admin/community", "/admin/community/events", "/admin/community/groups", "/admin/community/posts",
    "/admin/investissement", "/admin/investissement/ajax/offers", "/admin/investissement/ajax/opportunities",
    "/admin/investissement/create", "/admin/investissement/offers", "/admin/mentorat", "/admin/mentorat/sessions",
    "/admin/preview/enter", "/admin/projets", "/admin/team-matcher", "/admin/users",
    "/admin/users/broadcast", "/admin/users/export-csv", "/admin/users/new", "/admin/users/stats",
    "/api/notifications", "/apprentissage/badges", "/apprentissage/cours", "/apprentissage/progression",
    "/apprentissage/progression/export-pdf", "/community/events", "/community/events/new",
    "/community/groups", "/community/groups/new", "/community/posts", "/debug-me", "/face-login",
    "/forgot-password", "/investissement/advanced/currency-convert", "/investissement/advanced/economic-dashboard",
    "/investissement/advanced/economic-data", "/investissement/advanced/matching",
    "/investissement/advanced/matching/results", "/investissement/create-opportunity",
    "/investissement/entrepreneur/inbox", "/investissement/my-applications", "/investissement/my-contracts",
    "/investissement/my-offers", "/investissement/my-offers/ajax", "/investissement/my-postings",
    "/investissement/opportunities", "/investissement/opportunities/ajax", "/investissement/portfolio",
    "/investissement/postings/create", "/mentorat/availability", "/mentorat/calendar", "/mentorat/chatbot",
    "/mentorat/mentors", "/mentorat/my-calendar", "/mentorat/requests", "/mentorat/sessions",
    "/mentorat/sessions/export/excel", "/mentorat/sessions/export/pdf", "/mentorat/sessions/new",
    "/profile", "/profile/edit", "/profile/login-history", "/profile/notifications",
    "/projets", "/projets/chatbot", "/projets/dashboard", "/projets/export/csv", "/projets/new",
    "/team-matcher", "/verify-email",
]


def login(session, email, password):
    r = session.get(f"{BASE}/login")
    m = re.search(r'name="_csrf_token"\s+value="([^"]+)"', r.text)
    token = m.group(1) if m else ""
    r = session.post(f"{BASE}/login", data={
        "_username": email, "_password": password, "_csrf_token": token,
    }, allow_redirects=True)
    # logged in if we are NOT back on /login
    return "/login" not in r.url


def extract_error(html):
    # Symfony exception page title
    m = re.search(r'<title>(.*?)</title>', html, re.S)
    title = m.group(1).strip()[:200] if m else ""
    # exception message block
    m2 = re.search(r'exception-message[^>]*>\s*<h1[^>]*>(.*?)</h1>', html, re.S)
    msg = re.sub(r'<[^>]+>', '', m2.group(1)).strip()[:300] if m2 else ""
    return msg or title


def main():
    results = {}
    for role, (email, pw) in ACCOUNTS.items():
        s = requests.Session()
        ok = login(s, email, pw)
        results[role] = {"_login": "OK" if ok else "FAILED"}
        if not ok:
            continue
        for page in PAGES:
            if page in SKIP:
                continue
            try:
                r = s.get(f"{BASE}{page}", allow_redirects=False, timeout=15)
                code = r.status_code
                entry = str(code)
                if code >= 500:
                    entry += " :: " + extract_error(r.text)
                elif code in (301, 302):
                    entry += " -> " + r.headers.get("Location", "")
                results[role][page] = entry
            except Exception as e:
                results[role][page] = f"EXC :: {type(e).__name__}"
            print(f"[{role}] {page} -> {results[role][page]}", flush=True)
    print(json.dumps(results, indent=2))

    # Summary of 500s
    print("\n=== 5xx / EXCEPTIONS ===")
    seen = set()
    for role, pages in results.items():
        for page, status in pages.items():
            if page.startswith("_"):
                continue
            if status.startswith("5") or status.startswith("EXC"):
                key = (page, status)
                if key not in seen:
                    seen.add(key)
                    print(f"[{role}] {page} -> {status}")
    if not seen:
        print("None \U0001F389")


if __name__ == "__main__":
    main()
