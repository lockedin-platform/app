#!/usr/bin/env python3
"""Quick targeted page checker: login as given role, GET each path, print status + error."""
import re
import sys
import requests

BASE = "http://localhost:8000"
ACCOUNTS = {
    "admin": ("testadmin@test.com", "AdminPass1!"),
    "user1": ("testuser1@test.com", "TestUser1!"),
    "user2": ("testuser2@test.com", "TestUser2!"),
}


def login(s, email, pw):
    r = s.get(f"{BASE}/login")
    m = re.search(r'name="_csrf_token"\s+value="([^"]+)"', r.text)
    token = m.group(1) if m else ""
    r = s.post(f"{BASE}/login", data={"_username": email, "_password": pw, "_csrf_token": token})
    return "/login" not in r.url


def err(html):
    m = re.search(r'exception-message[^>]*>\s*<h1[^>]*>(.*?)</h1>', html, re.S)
    if m:
        return re.sub(r'<[^>]+>', '', m.group(1)).strip()[:400]
    m = re.search(r'<title>(.*?)</title>', html, re.S)
    return m.group(1).strip()[:200] if m else ""


role = sys.argv[1] if len(sys.argv) > 1 else "admin"
paths = sys.argv[2:] or ["/community/groups"]
email, pw = ACCOUNTS[role]
s = requests.Session()
print("login:", "OK" if login(s, email, pw) else "FAILED")
for p in paths:
    r = s.get(f"{BASE}{p}", allow_redirects=False, timeout=20)
    line = f"{p} -> {r.status_code}"
    if r.status_code >= 500:
        line += " :: " + err(r.text)
    elif r.status_code in (301, 302):
        line += " -> " + r.headers.get("Location", "")
    print(line)
