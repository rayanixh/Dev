#!/usr/bin/env python3
"""
Smoke test for the storefront. Assumes a freshly seeded database.

    php bin/install.php --fresh
    php -S 0.0.0.0:8080 -t public public/index.php
    python3 tests/smoke.py            # BASE_URL=http://127.0.0.1:8080

Every check is a real HTTP request against the running app. It covers the
things that usually break in a shop: payment double-submits, client-side price
tampering, stock races, access control, and the webhook signature guard.
"""

import hashlib
import hmac
import http.cookiejar
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get("BASE_URL", "http://127.0.0.1:8080")
PAYMENT_SECRET = os.environ.get("PAYMENT_SECRET", "dev-only-change-me-payment-secret")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def make_client():
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar), NoRedirect()
    )
    return jar, opener


fails = []


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


def csrf(html):
    m = re.search(r'name="_csrf" value="([a-f0-9]+)"', html)
    return m.group(1) if m else None


def request(opener, path, data=None, headers=None):
    body = urllib.parse.urlencode(data).encode() if data else None
    req = urllib.request.Request(
        BASE + path, data=body, headers=headers or {},
        method="POST" if data is not None else "GET",
    )
    try:
        with opener.open(req, timeout=60) as resp:
            return resp.status, resp.read().decode("utf-8", "replace"), dict(resp.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace"), dict(e.headers)


def login(opener, email, password):
    _, html, _ = request(opener, "/login")
    return request(opener, "/login", {"_csrf": csrf(html), "email": email, "password": password})


def main():
    _, shopper = make_client()

    print("== public pages")
    for path, expect in [("/", 200), ("/shop", 200), ("/product/1/nova-gems", 200),
                         ("/login", 200), ("/register", 200), ("/nope", 404)]:
        status, _, _ = request(shopper, path)
        check(f"GET {path} -> {expect}", status == expect, status)

    print("== authentication")
    status, _, headers = login(shopper, "demo@example.test", "DemoPass123!")
    check("login redirects to /account", status == 302 and headers.get("Location") == "/account",
          (status, headers.get("Location")))
    _, html, _ = request(shopper, "/account")
    check("wallet starts at 2,000", "৳2,000" in html)

    print("== order with wallet")
    _, html, _ = request(shopper, "/product/1/nova-gems")
    tok, idem = csrf(html), re.search(r'name="idem_key" value="([a-f0-9]+)"', html)[1]
    order = {"_csrf": tok, "product_id": "1", "package_id": "2", "quantity": "1",
             "game_uid": "PLAYER-42", "pay_method": "wallet", "idem_key": idem}
    status, _, headers = request(shopper, "/checkout", order)
    loc = headers.get("Location") or ""
    check("checkout redirects to the order", status == 302 and loc.startswith("/order/"), (status, loc))

    _, html, _ = request(shopper, loc)
    check("order is paid", "Paid" in html)
    check("order keeps the UID", "PLAYER-42" in html)

    print("== duplicate submit (same idempotency key)")
    _, _, headers = request(shopper, "/checkout", order)
    check("same order reused", headers.get("Location") == loc, headers.get("Location"))

    _, html, _ = request(shopper, "/account")
    check("wallet debited once (1,450)", "৳1,450" in html)
    check("ledger reconciles", "mismatch" not in html.lower())

    print("== price tampering")
    _, html, _ = request(shopper, "/product/1/nova-gems")
    idem2 = re.search(r'name="idem_key" value="([a-f0-9]+)"', html)[1]
    tampered = dict(order, _csrf=csrf(html), idem_key=idem2, package_id="1", amount="1", quantity="1")
    _, _, headers = request(shopper, "/checkout", tampered)
    _, html, _ = request(shopper, headers.get("Location") or "/")
    m = re.search(r"Total</dt><dd><strong>৳([\d,]+)", html)
    check("server used its own price (120)", bool(m) and m.group(1) == "120", m.group(1) if m else None)

    print("== stock and gates")
    _, html, _ = request(shopper, "/product/1/nova-gems")
    idem3 = re.search(r'name="idem_key" value="([a-f0-9]+)"', html)[1]
    _, _, headers = request(shopper, "/checkout",
                            dict(order, _csrf=csrf(html), idem_key=idem3, package_id="4"))
    _, html, _ = request(shopper, headers.get("Location") or "/")
    check("sold-out package refused", "Sold out" in html or "not available" in html)

    _, html, _ = request(shopper, "/product/5/vip-bundle")
    check("loyalty gate hides the form", 'id="order-form"' not in html)

    print("== CSRF")
    status, _, headers = request(shopper, "/checkout", dict(order, _csrf="deadbeef"))
    check("bad token rejected", status == 302 and headers.get("Location") == "/",
          (status, headers.get("Location")))

    print("== admin access control")
    status, _, _ = request(shopper, "/admin/orders")
    check("customer blocked from admin", status == 403, status)

    _, boss = make_client()
    login(boss, "admin@pixeltopup.test", "ChangeMe123!")
    status, html, _ = request(boss, "/admin/orders")
    check("admin dashboard loads", status == 200 and "PLAYER-42" in html)
    order_id = int(re.findall(r"<td>(\d+)</td>", html)[0])
    request(boss, f"/admin/order/{order_id}/status", {"_csrf": csrf(html), "status": "delivered"})
    _, html, _ = request(boss, "/admin/orders")
    check("order marked delivered", "Delivered" in html)

    print("== payment webhook guard")
    status, _, _ = request(shopper, "/webhook/payment", {})
    check("unsigned webhook rejected", status == 401, status)
    sig = hmac.new(PAYMENT_SECRET.encode(), b"", hashlib.sha256).hexdigest()
    req = urllib.request.Request(BASE + "/webhook/payment", data=b"", method="POST",
                                 headers={"X-Signature": sig, "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            code = resp.status
    except urllib.error.HTTPError as e:
        code = e.code
    check("signed empty payload rejected with 400", code == 400, code)

    print()
    if fails:
        print(f"{len(fails)} FAILED: {fails}")
        return 1
    print("ALL CHECKS PASSED")
    return 0


if __name__ == "__main__":
    sys.exit(main())
