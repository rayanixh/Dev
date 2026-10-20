# What the Toffee APK shows — plain-language findings

Target: `toffee_atv_apk.apk` (your file, 13.7 MB)
Every fact below came from reading the file. Nothing was downloaded, installed,
or executed. Commands used: the REA CLI (`rea inspect-artifact`,
`rea inspect-analysis-view`) plus Python in a local virtual environment.

**File integrity:** SHA-256 `425695386aa6b6f55681419ba586be4c45cda29ee1af3b9d0aa47d3dfea40823`
matches the digest inside REA evidence `ev_9a280317…aa4d`. So the analysis below
describes exactly this file, not a lookalike.

---

## 1. Identity

| Item | Value |
| --- | --- |
| Package (Android's unique app ID) | `com.banglalink.toffeetv` |
| App name | Toffee |
| Version | 4.4.3 (build number 187) |
| Minimum Android | 7.0 (SDK 25) |
| Build target | Android 14 (SDK 34) |
| Signing certificate | self-signed, "Nexdecade Technology Pvt. Ltd.", Dhaka |
| Certificate valid | 2019-11-05 → 2044-10-29 |

Self-signed is normal for Android apps — every app is signed with its own
certificate. It is not a security problem by itself.

**Who actually built it:** the launcher screen is
`com.mobiotics.vlive.android.ui.main.MainActivity`, and 4,411 classes sit under
`com.mobiotics.vlive`. This is a **white-label product from Mobiotics** that
Banglalink brands as Toffee. Almost no code is in `com.banglalink.*`.

That matters for you: the *patterns* in this app are the patterns of a commercial
OTT platform, and they are reusable. The *content* is not yours.

---

## 2. Manifest — what the app asks Android for

**19 permissions.** The interesting ones:

| Permission | Meaning |
| --- | --- |
| `INTERNET`, `ACCESS_NETWORK_STATE`, `ACCESS_WIFI_STATE` | network access |
| `RECORD_AUDIO` | microphone — likely voice search |
| `POST_NOTIFICATIONS` | show notifications |
| `FOREGROUND_SERVICE`, `WAKE_LOCK` | keep playing in the background |
| `RECEIVE_BOOT_COMPLETED` | start itself after a reboot |
| `READ_EXTERNAL_STORAGE`, `WRITE_EXTERNAL_STORAGE` | **downloads** (offline mode) |
| `com.android.vending.BILLING` | in-app purchases |
| `com.google.android.c2dm.permission.RECEIVE` | push notifications (Firebase) |
| `AD_ID`, `ACCESS_ADSERVICES_*` | advertising identifiers |

No `CAMERA`, no `READ_CONTACTS`, no `ACCESS_FINE_LOCATION`. That's a
comparatively restrained list.

**Exported components** (entry points other apps can reach — the usual place
security bugs live):

- **Activities:** `MainActivity` (the launcher), plus Facebook and Firebase auth screens
- **Services:** ExoPlayer scheduler, AndroidX WorkManager, Google Sign-In revocation
- **Receivers:** Amazon IAP, CleverTap push, Firebase messaging, profile installer

**Deep links:** `https://cct.com.banglalink.toffeetv`, `fbconnect`, `recaptcha`,
`genericidp`, `firebase.auth`

**Two flags worth knowing:**

- `allowBackup="false"` — good; app data can't be pulled via Android backup
- `usesCleartextTraffic="true"` — **HTTP is allowed**, see section 5
- `debuggable` is not set — good; it's a release build

---

## 3. Assets — the most useful folder in the whole APK

`assets/mock/` contains **11 JSON files** that are sample API responses the
developers used while building. This is a written-down description of the
backend contract:

```
availability.json      44 sample "availability" records
config.json            40 KB — the entire app configuration
content_success.json   54 KB — a sample catalogue response
plan.json              subscription plans
subscription.json      user subscriptions
profile_list.json      multi-profile support
purchase_list.json     purchase history
```

Plus `assets/image_interstitial.html` (an HTML ad overlay) and
`assets/org/threeten/bp/TZDB.dat` (timezone database).

### The config file is the architecture

`config.json` has 13 top-level keys. The important ones:

| Key | What it holds |
| --- | --- |
| `screens` | **6 screens, each a list of sections** ← the UI architecture |
| `menu` | navigation entries, each pointing at a screen |
| `featureEnabled` | **16 on/off switches** |
| `providerDetails` | branding + endpoints: base URL, DRM licence URL, theme |
| `planPrice`, `promotions`, `paymentMethods` | commerce surface |
| `apiUtilities` | country list, category list, language list |

### The feature switches

```json
"ageRange": true,        "chromeCastEnabled": false,
"contentShareEnabled": true,  "gLoginEnabled": true,
"hasCouponEnabled": false,    "hasMultiProfiles": false,
"hasMyBills": false,          "hasMyDevices": false,
"hasMyPlans": false,          "hasMyPurchases": false,
"hasWalkthrough": true,       "inappRating": true,
"isLoginFirst": true,         "kidsMode": false,
"offlineMode": true,          "pinBasedLogin": false
```

The app ships with every feature present but switchable. Turning off
`offlineMode` in the config hides downloads **without shipping a new APK**.

### How the home screen is built

The `HOME` screen is 14 *sections*. Each section declares what data it needs and
how to draw it:

```json
{
  "sectionType": "FEATURED",
  "listType":    "CAROUSAL",
  "displayType": "LANDSCAPE",
  "endpoint":    "/subscriber/v1/content/featured",
  "isRequiredLogin": false,
  "parameters": { "genre": "", "language": "en", "objecttype": "CONTENT" }
}
```

So the phone contains almost no layout logic for the home page. It receives a
list of sections and renders a carousel, a horizontal scroll row, or a grid
depending on `listType`. **This is why OTT apps can change their homepage
without an app-store update.**

The six screens and their section counts: `HOME` 14, `LIVE` 8, `TVSHOW` 1,
`MOVIES` 2, `MUSIC` 4, `WATCHLIST` 3.

### The 8 API endpoints these sections call

```
/subscriber/v1/content/featured      ← big banner carousel
/subscriber/v1/content              ← general lists
/subscriber/v1/content/popular      ← "Popular"
/subscriber/v1/content/recommended  ← "Recommended for you"
/subscriber/v1/content/recent       ← "Recently added"
/subscriber/v1/content/highrated    ← "Top rated"
```

(Some entries lack the leading `/` — that's a real inconsistency in their config,
not a typo I introduced.)

Two more endpoint paths exist in the DEX code: `/subscriber/v1/epg` (electronic
programme guide → live TV schedule) and `/subscriber/v1/metadata/trailer`.

### ⚠️ Read this before copying any values

These mocks are **template fixtures**, not Toffee's live data:

- `providerId` is **`"noorplay"`**, `website` is `noorplay.com` — a different
  operator's branding
- Plan names read `"tetetst"`; currency is `INR`; countries are `IN`, `BO`
- Sample subscriber ID is `mHH0oMeRkbvU`
- Dates are from 2020–2021

**Trust the shape. Never copy the values.** This is the single most common
beginner mistake: screenshotting a mockup and hardcoding it.

---

## 4. Code, strings and resources

- **19,023 classes** across two DEX files. No native `.so` libraries at all.
- The binary is heavily **obfuscated**: resource files are renamed to things like
  `res/8G.xml`, `res/36.pem`. Class and method names survive, but layout files
  are unreadable by name.
- Third-party surface, by class count: Google/AndroidX (4,848), Mobiotics
  (4,411), Google Ads (1,209), ExoPlayer player (170), Firebase (156), Amazon
  IAP (91), CleverTap, Mixpanel.
- Player: **ExoPlayer2** with a DRM layer (`exoplayer2/drm`) — Widevine-class
  playback.
- Payload strings of interest: `/system/xbin/su` and `/system/app/Superuser.apk`
  — classic **root-detection** probes. Observed string only; I did not trace
  what happens when one is found.
- 112 URL strings, 54 distinct hosts. The app's own backends are
  `content-prod.services.toffeelive.com` and
  `entitlement-prod.services.toffeelive.com`, both under
  `/toffee/BD/DK/android-tv/`. Everything else is ads/analytics/docs.

---

## 5. Security observations (facts, with confidence stated)

**a) Cleartext HTTP is permitted globally — confirmed.**
I found and decoded `res/8G.xml`, the file the manifest points at:

```xml
<network-security-config>
  <base-config cleartextTrafficPermitted="true">
    <trust-anchors>
      <certificates src="system"/>
      <certificates src="@7F130004"/>   <!-- raw/gts_root_r1 -->
    </trust-anchors>
  </base-config>
</network-security-config>
```

No domain list, no exclusions — **unencrypted HTTP is allowed to any host**.
That is a real weakening: on hostile Wi-Fi, traffic to any `http://` endpoint can
be read and modified.

**b) The bundled certificate is not sinister.** I resolved `@7F130004` to
`raw/gts_root_r1` — that is **Google Trust Services Root R1**, a normal public
root CA. So this is a compatibility workaround for older Android devices, *not*
a secret interception certificate. This is the kind of claim worth checking
before alarming anyone.

**c) The app checks for root.** Strings `/system/xbin/su` and
`/system/app/Superuser.apk` are the standard probes. Inference, not proof: I did
not trace the code that uses them.

**d) No native libraries.** Nothing compiled in C/C++. Therefore Ghidra/Hopper
would have nothing to analyse even if installed — a genuine non-finding.

---

## 6. What needs JADX (and what does not)

JADX is the tool that turns `classes.dex` back into readable Java. It is
**blocked** in this sandbox: the JAR only exists on GitHub's release file server,
which this environment cannot reach. I did not retry the download.

**Everything above was obtained without JADX**, because none of it requires
reading Java code. What still needs it:

| Question | Needs JADX? |
| --- | --- |
| Package, version, signing, permissions, components | ❌ no |
| Feature flags, screens, sections, endpoints, mock JSON | ❌ no |
| Which resource file backs a given attribute | ❌ no |
| Class names, package layout, SDK mix, URL strings | ❌ no |
| **The exact code inside a method** (e.g. what happens when root is found) | ✅ yes |
| **How a token is built or a request is signed** | ✅ yes (or packet capture) |
| **Where a specific value comes from at runtime** | ✅ yes (or packet capture) |

There is a second route to runtime questions that needs no decompiler at all:
capture the app's real network traffic (a HAR or SAZ file) and inspect it with
`rea inspect-web-network-capture`. That answers "what does it actually send"
far more directly than reading Java.

---

## 7. Turning this into PHP features

You are allowed to reuse **architecture ideas**, and you should write your own
implementation. Concretely:

**1. Server-driven homepage** (the big idea here)

```sql
CREATE TABLE screens (
  id           VARCHAR(32) PRIMARY KEY,     -- HOME, LIVE, ...
  sort_order   INT NOT NULL
);
CREATE TABLE sections (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  screen_id    VARCHAR(32) NOT NULL,
  section_type VARCHAR(32) NOT NULL,        -- FEATURED, CONTENT, CONTENTGROUP
  list_type    VARCHAR(16) NOT NULL,        -- CAROUSAL, HORZSCROLL, GRID
  display_type VARCHAR(16) NOT NULL,        -- LANDSCAPE, PORTRAIT, SQUARE, MIXED
  endpoint     VARCHAR(128) NOT NULL,
  parameters   JSON NULL,
  sort_order   INT NOT NULL,
  is_enabled   TINYINT(1) DEFAULT 1,
  FOREIGN KEY (screen_id) REFERENCES screens(id)
);
```

`index.php` then fetches enabled sections for the screen, loops them, and
renders one partial per `list_type`. Adding a row changes the homepage — no
deploy.

**2. Feature flags**

```sql
CREATE TABLE feature_flags (name VARCHAR(48) PRIMARY KEY, enabled TINYINT(1));
```
Load once per request into `$config['features']`, then `if ($features['kidsMode'])`.

**3. Subscription shape worth copying** (from `subscription.json`):
`subscriber_id`, `plan_id`, `status` (`ACTIVE`/`ONTRIAL`/…), `plan_start_date`,
`next_billing`, `renew_type` (`MANUAL`/auto), `coupon_id`, `availability_id`.
Copy the *fields and status names*; choose your own values.

**4. Endpoints to build:** `/api/config`, `/api/plans`, `/api/subscription`,
`/api/content/{featured,popular,recommended,recent,highrated}` — matching the
split you see here. One endpoint per row of content, plus one config endpoint
that describes the whole UI.

**5. Do NOT copy:** the `baseURL`, `drmLicenseUrl`, provider IDs, plan IDs,
countries, or any sample values. They belong to other companies and are stale.

---

## 8. Repeating this yourself

```bash
# 1. inventory with REA (no engine needed)
rea inspect-artifact /path/to/app.apk --json > evidence.json

# 2. full recon in plain text
python3 -m venv /tmp/rev && /tmp/rev/bin/pip install androguard
/tmp/rev/bin/python scripts/apk-recon.py /path/to/app.apk

# 3. read the mock API contract
unzip -o /path/to/app.apk 'assets/*' -d /tmp/app-assets
```
