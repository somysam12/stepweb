# Native Lib — Login + Heartbeat + Mod-Kill Integration (DefendImGuiBest)

What was changed in the native source to make login fully secure and connect it to
the ArkCheat/Onyx panel at **https://onyxbypass.battleground-hub.shop**. Only
`main.cpp` compiles (Android.mk `LOCAL_SRC_FILES := main.cpp`); the other main*.cpp
files are backups and were left untouched.

---

## The key path (what the heartbeat watches)

```
KCConfig.h  LIC_FILE_NAME "DefendMod.lic"
   → /sdcard/Android/obb/<pkg>/DefendMod.lic   (your license file)
   → read into keyForLogin[]                    (main.cpp ~1835)
   → Login(keyForLogin)  → POST /validbool      (verified at login)
   → on success: g_HBKey = key, g_HBSerial = device UUID
   → Heartbeat every 60s re-POSTs g_HBKey to /validbool  (forever)
```
Change the `.lic` path only in `KCConfig.h`. The heartbeat always re-verifies whatever
key logged in.

---

## Files changed (7)

### 1. `Helper/KCConfig.h`
```c
#define KC_MY_PANEL_URL "https://onyxbypass.battleground-hub.shop/validbool"
```
Login endpoint. `/validbool` returns `{status:true,data:{token,Enc,EXP,rng}}` — the
exact shape `Login()` already parses.

### 2. `Helper/KCSecure.h`  — URL HMAC lock (anti-repatch)
```c
static constexpr uint64_t _KC_EXPECTED_HMAC = 0x1B044E3C109EC9CEULL;
```
`KC_PANEL_URL()` computes an FNV-HMAC of the URL + bind-key `DEFENDMODBYSHAITAAN`.
If a cracker patches the URL to their own panel, the hash won't match → **hard crash**.
The value above is the HMAC for the new `/validbool` URL (verified: the old URL still
reproduces its old hash `0x739DFFB92B5F75D7`, so the algorithm is correct).

> **If you ever change the URL or bind-key, recompute this** with the FNV algorithm in
> `_kc_url_hmac()` (or the Perl one-liner your dev used) and paste the new value here,
> or the app self-crashes.

### 3. `Helper/KCHardSec.h`  — obfuscated URLs (XOR at compile time)
```c
#define KC_LOGIN_URL_PLAIN "https://onyxbypass.battleground-hub.shop/validbool"
#define KC_HB_URL_PLAIN    "https://onyxbypass.battleground-hub.shop/p/sw.js"   // AES heartbeat
#define KC_GH_URL_PLAIN    "https://onyxbypass.battleground-hub.shop/server/modstatus.php"
#define KC_MOD_VERSION     "1.0"   // bump every mod release
```
All are XOR-encrypted in the binary (zero plaintext for `strings`/jadx).

### 3b. `Helper/KCSecureHB.h` (NEW) — AES-256-GCM + HMAC heartbeat
Implements the encrypted `/p/sw.js` protocol with OpenSSL (already linked):
- Request `p1 = base64url(AES-256-GCM(nonce+tag+ct))` of `{k,d,t,pkg,libs,bh}`, `p2 = HMAC_SHA256(p1)`.
- Response is AES-GCM encrypted `{q,...}` — `q=7` OK, `q=1` soft, `q=2` crash.
- Keys `HB_AES_KEY_HEX` / `HB_HMAC_KEY_STR` **must match** panel `app/Config/secrets.php`.
- **A patched URL / fake server cannot forge a valid AES response → decrypt fails → crash.**
  This is what makes the heartbeat repatch-proof (plain `/validbool` was not).

### 4. `Helper/KCHeartbeat.h`
```c
#define KC_HB_PLAIN "https://onyxbypass.battleground-hub.shop/validbool"
```

### 5. `Helper/Login.h`
- New globals: `g_HBKey`, `g_HBSerial` — set on successful login so the heartbeat can
  re-verify the **real** key.
- **Canary/dump safe:** the plaintext HTTP response (`chunk.memory`) is now
  `memset(0)` + `free()`d before returning — it no longer lingers in heap.

### 6. `jni/main.cpp` — `_KC_HeartbeatThread()`
- Waits for login (needs the real key), then every 60s calls
  `KC_SecureBeat(hbUrl, g_HBKey, g_HBSerial, g_HBPkg)` → the **AES `/p/sw.js`** beat.
- `q==7` OK · `q==1` soft (maintenance, keep running) · `q==2` **crash**
  (blocked/expired/tamper/clone/package-mismatch) · decrypt/network fail → 2 fails → crash.
- Response wiped from memory after each beat.

### 7. `jni/main.cpp` — `GH_FetchStatusThread()` (the kill switch)
- Now hits `modstatus.php?cv=<KC_MOD_VERSION>`.
- Unchanged parsing: `ON` = run, `OFF:<msg>\n<tg link>` = open the link (`am start`),
  show an ALERT dialog, then SIGKILL after 4s. This is the **mod-lib version kill**.

---

## Why login is now "fully secure"

| Layer | Protects against |
|---|---|
| URL HMAC lock (KCSecure) | Repatching the URL to a fake panel → hard crash |
| XOR-obfuscated URLs | `strings` / jadx / memory grep — no plaintext URLs |
| `/validbool` server check | Fake/expired/blocked keys → rejected server-side |
| 60s heartbeat (real key) | Key blocked/expired after login → app dies in ~2 min |
| Response wipe | Canary / heap dump → no login/heartbeat JSON left in memory |
| Anti-Frida / ptrace / MT (KCHardSec) | Debuggers, injectors, memory editors → crash |
| Self ELF integrity (KC_IntegrityCheck) | 1-byte lib tamper → crash (no server hash needed) |
| Mod version kill (modstatus) | Bump version → old mod libs die + open Telegram |

**No lib hash needed** for this build — integrity is self-checked at runtime and mod-kill
is version-only.

---

## Build & deploy

1. Panel: upload **`server/modstatus.php`** (the only new panel file; the rest you already have).
2. Build: `cd app/src/main/jni && ndk-build` (or your usual build) → produces the `.so`.
3. Sign with your keystore → (optional) Virbox protect.
4. Test: put a valid key in the `.lic`, launch → login OK → block the key in the panel →
   app should die within ~2 minutes.
5. Mod kill: panel → Manage Rebranding → **Mod Lib Kill** → bump version / Kill-All +
   set Telegram redirect → old mod libs show the dialog, open Telegram, crash.

> The connection URL is **baked into the lib** (`KCConfig.h`) — you do NOT save it in the
> panel. The panel's Base Domain setting only controls the links shown to you.
