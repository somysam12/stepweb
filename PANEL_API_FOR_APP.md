# Panel API — Full Reference for App Integration (hand this to any agent)

Everything the ArkCheat / Onyx panel exposes for an APK/lib to connect to.
Give this file to any developer/agent and they can wire an app to the panel.

- **Base domain (example):** `https://onyxbypass.battleground-hub.shop`
  (whatever domain the panel is hosted on — the app hardcodes the endpoints below).
- The panel's "Base Domain" setting only changes the links *shown in the panel UI*;
  the app uses hardcoded URLs.

---

## 0. Shared secrets (must match app ↔ panel)

Defined server-side in `app/Config/secrets.php`. Put the **same** values in the app for
the AES endpoints (heartbeat / unlock / lib download).

```
AES_KEY   (hex, 32 bytes / 64 chars) = 5c3d9f2a8b1e4c6079d2f3a4b5c6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8
HMAC_KEY                             = X9kL2mQ7vR4nB1eY3pT8wS5uJ6dA0cF
UNLOCK_SECRET (hex, grant signing)   = e1f2a3b4c5d6079889a0b1c2d3e4f5061728394a5b6c7d8e9f0a1b2c3d4e5f60
XKEY1 = 0xB7E15163      XKEY2 = 0x9E3779B9
XOR key for kill endpoints           = 0xA5
ALLOWED libs = libshaitaan.so, libEliteCore.so, libbgmi.so, libc++_shared.so
```
> Change all of these before production and keep app + panel in sync.

---

## 1. Endpoint map

| Purpose | URL | Method | Encoding |
|---|---|---|---|
| **Key verify (login)** | `/validbool` | POST | plain JSON + MD5 token |
| **Heartbeat (60s)** | `/p/sw.js` | POST | AES-256-GCM + HMAC |
| **Lib unlock (grant)** | `/secure/unlock` | POST | AES-256-GCM + HMAC |
| **Gated lib download** | `/secure/lib` | GET/POST | grant-checked, streams `.so` |
| **Lib kill check** | `/server/lib_version.php?cv=VER` | GET | XOR(0xA5)+base64 JSON |
| **APK kill / update** | `/server/apk_kill.php?cv=VER` | GET | XOR(0xA5)+base64 JSON |
| **Mod-lib kill (plain)** | `/server/modstatus.php?cv=VER` | GET | plain `ON` / `OFF:msg\ntg` |
| **Mod-lib kill (json)** | `/server/modlib_version.php?cv=VER` | GET | XOR(0xA5)+base64 JSON |

Pick the login method the app already understands; a simple app can use just
`/validbool` (login) + one kill endpoint. A hardened app adds `/p/sw.js`,
`/secure/unlock`, `/secure/lib`.

---

## 2. `/validbool` — key verify (login)

**Request (POST, form-encoded):**
```
game=PUBG&user_key=<KEY>&serial=<DEVICE_UUID>
```
**Response — valid:**
```json
{"status":true,"data":{"token":"<md5>","Enc":"<md5>","EXP":"31/12/2099","rng":1699999999}}
```
**Response — invalid:** `{"status":false,"reason":"Key is blocked"}`

- `token = md5("<game>-<key>-<serial>-Vm8Lk7Uj2JmsjCPVPVjrLa7zgfx3uz9E")`
- `rng = server time`; app should check `rng + 30 > now` (replay guard).
- `EXP` may be a string or `{"date":"..."}` — handle both.
- First call **binds the device**; extra devices beyond the key's `max_devices` → `status:false` ("Device limit reached").
- Maintenance mode → `status:false` with the custom maintenance message as `reason`.

**App:** login = read key → POST here → `status:true`? proceed : show `reason`.

---

## 3. `/p/sw.js` — heartbeat (every 60s, AES)

**Request (POST, form-encoded):** `p1=<cipher>&p2=<hmac>`
- `p1 = base64url( AES-256-GCM( nonce[12] + tag[16] + ct ) )` of JSON:
  ```json
  { "k":key, "d":deviceId, "t":unixTime, "pkg":package, "libs":"a.so,b.so", "bh":"<libbgmi sha256>" }
  ```
- `p2 = HMAC_SHA256(p1, HMAC_KEY)` (hex)

**Response:** AES-GCM(base64url) of `{ q, x, z, lh, w, v, u }`
- `q=7` OK · `q=1` soft (maintenance, keep running) · `q=2` **crash** (blocked/expired/tamper/clone)
- `x = expiry ^ 0xB7E15163`, `z = now ^ 0x9E3779B9`, `lh` = expected libbgmi hash, `w/v/u` = junk.

**App:** beat every 60s; on `q==2` or 2 consecutive failures → kill process.
Server checks: key state, replay window (±120s), lib whitelist, libbgmi hash, package binding.

---

## 4. `/secure/unlock` → `/secure/lib` — gated lib download (login-patch proof)

**`/secure/unlock` (POST):** `p1=AES({k,d,n,pkg})&p2=HMAC(p1)` where `n` = random nonce hex.
- Valid key → AES response `{ ok:1, g:<grant>, lib:"libbgmi.so", lh:"<sha256>", ...junk }`
- Invalid → `{ ok:0, ...junk }`  (no grant)
- `grant = HMAC_SHA256(key + "|" + nonce, UNLOCK_SECRET)` — **no date/expiry in the response.**

**`/secure/lib` (GET):** `?k=<key>&n=<nonce>&g=<grant>&f=<libname>`
- Recomputes the grant + re-checks the key in DB → streams the `.so`, else **403**.
- Direct download of `server/libs/*.so` is blocked (403). A leaked link is useless.

**App flow:** login → `/secure/unlock` → get grant → `/secure/lib` with grant → verify
`sha256(file)==lh` → `System.load()`. A patched login never gets a grant.

---

## 5. Kill endpoints

### `/server/lib_version.php?cv=VER` and `/server/apk_kill.php?cv=VER`
XOR(0xA5)+base64 JSON. Decode: base64-decode → XOR each byte with 0xA5 → JSON.
- lib: `{v,kb,kill,redirect_url,ts,ok}` — `kill:1` → crash; open `redirect_url` if set.
- apk: `{kill,popup_msg,redirect_url,force_update,latest_ver,apk_url,ts,ok}`.

### `/server/modstatus.php?cv=VER` — plain text (simplest)
- `ON` → run.
- `OFF:<message>\n<telegram link>` → show dialog, open the link, then crash.
- Driven by panel **Mod Lib Kill** settings (version bump / kill-all + redirect).

### `/server/modlib_version.php?cv=VER` — same as modstatus but XOR+base64 JSON
`{v,kill,redirect_url,ts,ok}`. Use this OR modstatus (pick one for the mod lib).

---

## 6. Panel settings that drive behaviour (owner sets these in the UI)

| Setting / page | Effect |
|---|---|
| Maintenance (Server & Maintenance) | login + heartbeat go soft; custom message shown |
| Key block/expire (All Keys) | key → `status:false` → app crashes via heartbeat |
| One Device Mode / Max Devices Cap (One Device) | key device limits |
| Single-Device Panel Login (One Device) | panel logins locked to 1 device/IP |
| Lib Killer (Manage Rebranding) | `lib_version.php` kill + redirect |
| APK Killer (Manage Rebranding) | `apk_kill.php` kill + message + redirect |
| Mod Lib Kill (Manage Rebranding) | `modstatus.php` / `modlib_version.php` version kill + redirect |
| Lib hashes (Heartbeat & Security) | libbgmi/shaitaan/elite sha256 for heartbeat/unlock |
| Base Domain (Rebranding/Server/Heartbeat) | rebuilds all shown links for a new domain |

---

## 7. Minimum working app integration

```
1. LOGIN     POST /validbool  (key + serial) → status:true?
2. UNLOCK    POST /secure/unlock → grant           (only if key valid in DB)
3. DOWNLOAD  GET  /secure/lib (grant) → the .so     (verify sha256 == lh)
4. HEARTBEAT POST /p/sw.js every 60s → q==2 → crash
5. MOD KILL  GET  /server/modstatus.php?cv=VER → OFF → dialog+telegram+crash
```
A lightweight app can do just steps 1 + 4 (or 1 + 5). All keys are read from the
`keys_code` table via `/validbool` and re-checked by the heartbeat.

---

## Companion docs
- `NATIVE_LOGIN_INTEGRATION.md` — exactly what was wired into the DefendImGui native lib.
- `SECURE_LIB_DELIVERY.md` — Java (HeartbeatManager / LibUnlockManager) + full flow.
- `MOD_LIB_NATIVE.md` — C++ mod-kill + key verify snippets.
