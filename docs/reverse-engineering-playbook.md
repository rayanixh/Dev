# Reverse engineering an app with me — a working playbook

This is how to get useful answers out of me on an app, using `toffee_atv_apk.apk`
as the running example. It is a method, not a magic prompt: what you bring
(target, question, authorization) and what I bring (evidence gathering) both
matter.

> Scope note: reverse engineering is legitimate for interoperability, learning,
> security review, and analyzing software you are authorized to inspect. Do not
> use these techniques to bypass paid entitlements, DRM, or someone else's
> accounts.

---

## 1. What reconnaissance already established

Run once, offline, no JADX required:

```bash
python3 -m venv /tmp/rev && /tmp/rev/bin/pip install androguard
/tmp/rev/bin/python scripts/apk-recon.py toffee_atv_apk.apk
```

Findings from your file — this is the level of fact I can produce today:

| Fact | Value |
| --- | --- |
| Package | `com.banglalink.toffeetv` (Toffee, Android TV build) |
| Version | 4.4.3, versionCode 187, minSdk 25, targetSdk 34 |
| Signer | self-signed, `Nexdecade Technology Pvt. Ltd.`, Dhaka, valid to 2044 |
| Launcher | `com.mobiotics.vlive.android.ui.main.MainActivity` |
| Platform | **Mobiotics VLive** white-label OTT stack (`com.mobiotics.vlive`, 4,411 classes) |
| Code size | 19,023 classes across `classes.dex` + `classes2.dex`; no native `.so` libraries |
| Player | ExoPlayer2 (`com.mobiotics.player.exo`, 117 classes) + `exoplayer2/drm` |
| Monetization | IMA/AdMob, `com.amazon.device.iap` (TV in-app purchases), Play Billing |
| Analytics | CleverTap + Firebase + Mixpanel baked into the DEX |
| Backend | `content-prod.services.toffeelive.com/toffee/BD/DK/android-tv/`, `entitlement-prod.services.toffeelive.com/...` |
| Config CDN | `d1v8zxa9gk5f.cloudfront.net/COMMONFILES/country-phone-code.json` |
| Deep links | `https://cct.com.banglalink.toffeetv`, `fbconnect`, `recaptcha`, `genericidp` |
| Red flag | `android:usesCleartextTraffic="true"` despite a network security config |

Two structural conclusions worth internalizing:

1. **The app is white-label.** The distinctive behavior is not in
   `com.banglalink.*` — that namespace barely appears. It is in
   `com.mobiotics.vlive.*`. So "how does Toffee do X" is usually answered by
   reading Mobiotics' platform code, and the same answer applies to every other
   operator running VLive.
2. **The interesting strings are endpoints and SDK boundaries,** not class
   names. `content-prod` vs `entitlement-prod` already tells you the app
   separates catalog delivery from rights checks — that split is where
   subscription logic lives.

---

## 2. The loop we run together

Every investigation is the same five steps. Skipping step 1 is what produces
confident nonsense.

```
① scope → ② inventory → ③ hypothesis → ④ targeted evidence → ⑤ verified answer
```

**① Scope.** Pick one question with a testable answer. "How does the app work"
is not answerable; "how does the app decide I can play a locked title" is.

**② Inventory.** Establish what exists before reading code: components,
endpoints, SDKs, class layout. This is where the recon above fits, and where
`rea inspect-artifact` gives a hash-anchored manifest.

**③ Hypothesis.** I state a falsifiable claim with the evidence it would take to
confirm it. Example: *"Playback authorization is server-side; the app sends a
token to `entitlement-prod` and receives a signed manifest URL. To confirm, look
for a request builder whose response feeds the player's media URL."*

**④ Targeted evidence.** Read only what the hypothesis needs. One class, one
method, one string table — not a whole-DEX dump.

**⑤ Verified answer.** Observations (quoted, with file/method), inferences
(labelled as such), and open unknowns. If I can't close the question, I say so
and name what would close it.

---

## 3. Prompts that work

The pattern is **target + question + evidence bar + stopping condition**.

### Good

> "In `toffee_atv_apk.apk`, find how the app composes the playback request. I
> want the request builder, the host it targets, and which fields are
> per-device. Cite class and method names. Stop when you have the builder; don't
> trace the whole network stack."

> "The manifest sets `usesCleartextTraffic=true` but also ships a network
> security config. Determine whether any real traffic can still go cleartext, and
> list the domains involved. If the config is compiled into resources and you
> can't read it, say that instead of guessing."

> "Compare this 4.4.3 APK with an older build: what changed in the endpoint list
> and the ExoPlayer/DRM layer? Use only the two files' strings and class
> inventories."

> "Map the subscription surface: which classes touch `entitlement-prod`, which
> touch Play Billing, and where do the two meet? Give me the call path, not the
> whole class list."

### Weak

> "Reverse engineer this app." — no target state, so I'll produce an inventory
> you already have.
> "Decompile everything and explain." — unbounded; burns context for facts you
> won't use.
> "Is this app secure?" — unfalsifiable; ask about a specific property instead.

A useful habit: ask for the **stopping condition**. RE has no natural end;
investigations end when a stated question is answered.

---

## 4. How to read what I give you

I keep three categories separate on purpose:

- **Observation** — read directly from the artifact. Reproducible. Example:
  `usesCleartextTraffic="true"` at manifest offset X.
- **Inference** — my reasoning on top. Example: "cleartext is probably allowed
  for legacy media CDNs." Could be wrong; it is testable.
- **Unknown** — what the evidence can't settle. Example: "the network security
  config is a resource reference (`@7F180006`); without a resource decoder I
  cannot read the domain allowlist."

When I return tool output, it carries an **evidence ID** (`ev_…`) and a
**coverage** block stating what was retained and what was omitted. Treat a
truncated result as incomplete, not as a negative finding: "no results in page 1"
is not "no results".

Anything I claim about *runtime* behavior — which endpoint is called, what the
server returns — cannot come from static analysis. It needs a device or a
capture, and I will say so.

---

## 5. Escalation ladder

Cheapest evidence first. Each rung buys something the previous one can't.

| Rung | Tool | Answers | Cost |
| --- | --- | --- | --- |
| 1 | `scripts/apk-recon.py` | identity, components, endpoints, SDK map | seconds, offline |
| 2 | `rea inspect-artifact` | hash-anchored inventory + next probes | seconds, offline |
| 3 | string/DEX queries (androguard) | which classes reference a host, feature, or SDK | seconds |
| 4 | **JADX** `inspect-android-package` / `inspect-android-method` | actual Java decompilation, method bodies | needs JADX + JDK |
| 5 | **Ghidra/Hopper/IDA** `open_binary` | native code, when `.so` files exist | needs an engine |
| 6 | `adb` (REA device tools) | live device: packages, logs, screenshots, file pulls | needs a device |
| 7 | HAR / mitmproxy / SAZ capture | real request/response traffic | needs device + proxy |

Your APK has **no native libraries**, so rung 5 does not apply to it at all —
that's a real finding, not a limitation. Rung 4 is where the remaining value is,
and it is currently blocked (see below).

Rung 7 is the strongest evidence for "what does this app actually send", and it
needs no decompiler: capture traffic from a device or emulator, save a HAR/SAZ,
and I can read it with `rea inspect-web-network-capture`.

---

## 6. What is blocked, and the one-command fix

Static DEX parsing and string analysis work today. Decompilation does not:

| Need | Blocked by | Unblocks |
| --- | --- | --- |
| `inspect-android-package` (JADX) | JAR only on GitHub's release CDN, which this sandbox can't reach | `bash scripts/setup-rea-toolkit.sh` on a networked machine |
| Native analysis (Ghidra) | same CDN; also irrelevant for this APK (no `.so`) | same script |

Until then, ask me questions answerable from strings, the manifest, resources,
and class/method *signatures*. That surface is larger than it sounds: endpoints,
SDK inventory, exported surface, deep links, and cross-references all live there.

---

## 7. First three investigations to try

1. **Entitlement path.** Where does `entitlement-prod.services.toffeelive.com`
   get called from, and what distinguishes it from `content-prod`? Needs rung 3
   now, rung 4 for the full picture.
2. **Cleartext exposure.** Reconcile `usesCleartextTraffic=true` with the
   network security config. Needs a resource decoder (`apktool`), which is also
   CDN-blocked — so this one waits, and I'll tell you that rather than invent a
   domain list.
3. **Version drift.** Obtain an older Toffee APK and diff endpoint + SDK
   inventories. Fully doable today, and it's how feature-level questions
   ("when did they add X") get answered.

Bring the question, not the whole app. I'll tell you what the evidence supports
and where it runs out.
