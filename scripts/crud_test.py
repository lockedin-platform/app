#!/usr/bin/env python3
"""Deep functional test: create real records via POST and verify they persist + render."""
import re
import time
import requests

BASE = "http://localhost:8000"
EMAIL, PW = "testuser1@test.com", "TestUser1!"


def login(s):
    r = s.get(f"{BASE}/login")
    m = re.search(r'name="_csrf_token"\s+value="([^"]+)"', r.text)
    token = m.group(1) if m else ""
    r = s.post(f"{BASE}/login", data={"_username": EMAIL, "_password": PW, "_csrf_token": token})
    return "/login" not in r.url


def main():
    s = requests.Session()
    assert login(s), "login failed"
    print("login: OK")

    stamp = str(int(time.time()))

    # 1) Create a community post
    content = f"Automated test post {stamp}"
    r = s.post(f"{BASE}/community/posts/new", data={"content": content}, timeout=30)
    posts_html = s.get(f"{BASE}/community/posts", timeout=30).text
    print("post create:", "OK" if content in posts_html else "FAIL (not found after create)",
          f"(http {r.status_code})")

    # 2) Create a group
    gname = f"TestGroup{stamp}"
    r = s.post(f"{BASE}/community/groups/new",
               data={"name": gname, "description": f"desc {stamp}", "is_private": "0"},
               allow_redirects=True, timeout=30)
    group_ok = gname in r.text or gname in s.get(f"{BASE}/community/groups", timeout=30).text
    print("group create:", "OK" if group_ok else "FAIL", f"(http {r.status_code}, final {r.url.split('8000')[-1]})")

    # 3) Create a project
    # discover fields from the new-project form
    form = s.get(f"{BASE}/projets/new", timeout=30).text
    names = re.findall(r'name="([^"]+)"', form)
    print("project form fields:", sorted(set(n for n in names if not n.startswith('_'))))


if __name__ == "__main__":
    main()
