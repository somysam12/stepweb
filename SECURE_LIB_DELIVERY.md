# Secure Lib Delivery + Login‑Patch Protection — App Implementation Guide

This document explains the **server side that is already built into the panel** and
exactly **what to implement in the APK (Java) and native lib (C++)** so that:

1. The native lib **cannot be downloaded** with just a URL. A leaked link is useless.
2. If someone **patches your login screen** to fake a "login success", the lib is
   **still never delivered** — because the lib is unlocked by a **separate request**
   that only succeeds when the key is genuinely valid **in our database**.
3. All traffic is **AES‑256‑GCM encrypted + HMAC authenticated**. Captured requests /
   responses are useless and cannot be replayed to a different device/key.
4. The unlock response contains **no date / expiry / timestamp** — it carries a random,
   keyed **grant token** instead (as requested).

> Order to build: **WEBSITE (done)** → **NATIVE LIB (C++)** → **APK (Java)**.

---

## 0. Shared secrets (MUST be identical everywhere)

Defined once on the server in `app/Config/secrets.php`. Put the **same** values in the
APK and the native lib.

```
AES_KEY  (hex, 32 bytes / 64 chars) = 5c3d9f2a8b1e4c6079d2f3a4b5c6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8
HMAC_KEY                            = X9kL2mQ7vR4nB1eY3pT8wS5uJ6dA0cF
UNLOCK_SECRET (hex, grant signing)  = e1f2a3b4c5d6079889a0b1c2d3e4f5061728394a5b6c7d8e9f0a1b2c3d4e5f60
XKEY1 = 0xB7E15163      XKEY2 = 0x9E3779B9
ALLOWED LIBS = libshaitaan.so, libEliteCore.so, libbgmi.so, libc++_shared.so
```

> ⚠️ The AES key in the old `3Steps.md` was **63 hex chars (invalid)** — `hex2bin`
> failed and encryption silently broke. Use the **64‑char** key above everywhere.
> Change all four values before you ship to production and keep them in sync.

---

## 1. Server endpoints (already live)

| Purpose | URL | Method | Encryption |
|---|---|---|---|
| Key verify (login) | `/validbool` | POST | plain JSON + MD5 token (matches existing APK) |
| Heartbeat (60 s) | `/p/sw.js` | POST | AES‑256‑GCM + HMAC |
| **Lib unlock (grant)** | `/secure/unlock` | POST | AES‑256‑GCM + HMAC |
| **Gated lib download** | `/secure/lib` | GET/POST | grant‑checked, streams `.so` |
| APK kill / update check | `/server/apk_kill.php?cv=VER` | GET | XOR(0xA5)+base64 |
| Lib kill check | `/server/lib_version.php?cv=VER` | GET | XOR(0xA5)+base64 |

Direct download of `server/libs/*.so` is **blocked** by `.htaccess`. `version.txt` stays public.

---

## 2. The unlock → download flow (the important part)

```
┌─────────┐   1. login (key)          ┌──────────────┐
│  APK    │ ────────────────────────► │  /validbool  │  key valid? → token
└─────────┘                           └──────────────┘
     │
     │ 2. build nonce (random, NOT a timestamp)
     │    p1 = AES( {k:key, d:deviceId, n:nonce, pkg:pkg} )
     │    p2 = HMAC(p1)
     ▼
┌─────────┐   POST p1,p2              ┌──────────────┐
│  APK    │ ────────────────────────►│ /secure/unlock│  ← verifies key IN DB
└─────────┘                           └──────────────┘     (patched login can't help)
     ▲   response (AES):  {ok:1, g:grant, lib:name, lh:hash}     grant = HMAC(key|nonce, UNLOCK_SECRET)
     │   (NO date / expiry / time — just the grant token)
     │ 3. download lib with the grant
     ▼
┌─────────┐  GET /secure/lib?k=&n=&g=&f=lib   ┌──────────────┐
│  APK    │ ────────────────────────────────►│  /secure/lib  │ recomputes grant,
└─────────┘   ◄─── streams libbgmi.so ────────└──────────────┘ re‑checks key in DB
     │ 4. sha256(downloaded lib) == lh ?  → load it : reject
     ▼ 5. start heartbeat every 60 s (/p/sw.js)
```

**Why a patched login can't get the lib:** step 2/3 both re‑query `keys_code` for the
key. If login was faked (key not in DB, blocked, or expired) the unlock returns `{ok:0}`
with **no grant**, and `/secure/lib` returns **403**. There is nothing to bypass on the
client — the gate is the server's own database.

**Why capture/replay fails:** requests are AES‑GCM (authenticated) + HMAC. Without the
keys an attacker can neither read nor forge them. The `grant` is a keyed hash of
`key|nonce` — unguessable without `UNLOCK_SECRET`, and only usable while the key stays
valid in the DB.

---

## 3. APK (Java) — `LibUnlockManager.java`

Reuse the AES/HMAC helpers from `HeartbeatManager` (same keys). Call this **after** a
successful `/validbool` login, on a background thread.

```java
package com.yourpackage;

import android.content.Context;
import android.util.Base64;
import org.json.JSONObject;
import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.security.SecureRandom;
import javax.crypto.*;
import javax.crypto.spec.*;

public class LibUnlockManager {
    private static final String AES_KEY  = "5c3d9f2a8b1e4c6079d2f3a4b5c6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8";
    private static final String HMAC_KEY = "X9kL2mQ7vR4nB1eY3pT8wS5uJ6dA0cF";

    // Split-XOR these in main.cpp like the heartbeat URL (don't hardcode plain).
    private static String unlockUrl() { return "https://arkcheat.battleground-hub.shop/secure/unlock"; }
    private static String libUrl()    { return "https://arkcheat.battleground-hub.shop/secure/lib"; }

    /** Returns the loaded lib file on success, or null → you must exit the app. */
    public static File unlockAndDownload(Context ctx, String key, String deviceId) {
        try {
            // 1. random nonce (NOT a timestamp)
            byte[] nb = new byte[16]; new SecureRandom().nextBytes(nb);
            String nonce = toHex(nb);

            JSONObject in = new JSONObject();
            in.put("k", key);
            in.put("d", deviceId);
            in.put("n", nonce);
            in.put("pkg", ctx.getPackageName());

            String p1 = aesEncrypt(in.toString());
            String p2 = hmac(p1);

            // 2. POST /secure/unlock
            String resp = postForm(unlockUrl(), "p1="+enc(p1)+"&p2="+enc(p2));
            String plain = aesDecrypt(resp);
            if (plain == null) return null;
            JSONObject r = new JSONObject(plain);
            if (r.optInt("ok",0) != 1) return null;      // key invalid → no lib
            String grant   = r.optString("g","");
            String libName = r.optString("lib","");
            String libHash = r.optString("lh","");        // expected sha256 of lib
            if (grant.isEmpty() || libName.isEmpty()) return null;

            // 3. download from /secure/lib
            String dl = libUrl()+"?k="+enc(key)+"&n="+enc(nonce)+"&g="+enc(grant)+"&f="+enc(libName);
            File out = new File(ctx.getFilesDir(), libName);
            if (!httpDownload(dl, out)) return null;

            // 4. verify hash before trusting the file
            if (!libHash.isEmpty() && !sha256(out).equalsIgnoreCase(libHash)) {
                out.delete(); return null;
            }
            return out;   // System.load(out.getAbsolutePath()) if you load at runtime
        } catch (Throwable t) { return null; }
    }

    // ---- helpers ----
    private static String postForm(String url, String body) throws Exception {
        HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
        c.setRequestMethod("POST"); c.setDoOutput(true);
        c.setConnectTimeout(12000); c.setReadTimeout(12000);
        c.setRequestProperty("Content-Type","application/x-www-form-urlencoded");
        try (OutputStream os=c.getOutputStream()){ os.write(body.getBytes(StandardCharsets.UTF_8)); }
        if (c.getResponseCode()!=200){ c.disconnect(); return ""; }
        Scanner sc=new Scanner(c.getInputStream(),"UTF-8"); StringBuilder sb=new StringBuilder();
        while(sc.hasNextLine()) sb.append(sc.nextLine()); c.disconnect();
        return sb.toString().trim();
    }
    private static boolean httpDownload(String url, File out) {
        try {
            HttpURLConnection c=(HttpURLConnection)new URL(url).openConnection();
            c.setConnectTimeout(15000); c.setReadTimeout(30000);
            if (c.getResponseCode()!=200){ c.disconnect(); return false; }
            try (InputStream is=c.getInputStream(); FileOutputStream fo=new FileOutputStream(out)){
                byte[] buf=new byte[8192]; int n; while((n=is.read(buf))>0) fo.write(buf,0,n);
            }
            c.disconnect(); return out.length()>0;
        } catch(Exception e){ return false; }
    }
    private static String enc(String s) throws Exception { return URLEncoder.encode(s,"UTF-8"); }

    private static String aesEncrypt(String p) throws Exception {
        byte[] key=hex(AES_KEY), nonce=new byte[12]; new SecureRandom().nextBytes(nonce);
        Cipher c=Cipher.getInstance("AES/GCM/NoPadding");
        c.init(Cipher.ENCRYPT_MODE,new SecretKeySpec(key,"AES"),new GCMParameterSpec(128,nonce));
        byte[] ct=c.doFinal(p.getBytes(StandardCharsets.UTF_8));
        byte[] raw=new byte[12+ct.length];
        System.arraycopy(nonce,0,raw,0,12); System.arraycopy(ct,0,raw,12,ct.length);
        return Base64.encodeToString(raw,Base64.URL_SAFE|Base64.NO_WRAP|Base64.NO_PADDING);
    }
    private static String aesDecrypt(String b) {
        try {
            String p=b; int m=p.length()%4; if(m==2)p+="=="; else if(m==3)p+="=";
            byte[] raw=Base64.decode(p,Base64.URL_SAFE|Base64.NO_WRAP);
            if(raw.length<29) return null;
            byte[] key=hex(AES_KEY),nonce=new byte[12],tag=new byte[16],ct=new byte[raw.length-28];
            System.arraycopy(raw,0,nonce,0,12); System.arraycopy(raw,12,tag,0,16); System.arraycopy(raw,28,ct,0,ct.length);
            byte[] ctTag=new byte[ct.length+16];
            System.arraycopy(ct,0,ctTag,0,ct.length); System.arraycopy(tag,0,ctTag,ct.length,16);
            Cipher c=Cipher.getInstance("AES/GCM/NoPadding");
            c.init(Cipher.DECRYPT_MODE,new SecretKeySpec(key,"AES"),new GCMParameterSpec(128,nonce));
            return new String(c.doFinal(ctTag),StandardCharsets.UTF_8);
        } catch(Exception e){ return null; }
    }
    private static String hmac(String d) throws Exception {
        Mac m=Mac.getInstance("HmacSHA256");
        m.init(new SecretKeySpec(HMAC_KEY.getBytes(StandardCharsets.UTF_8),"HmacSHA256"));
        return toHex(m.doFinal(d.getBytes(StandardCharsets.UTF_8)));
    }
    private static String sha256(File f) throws Exception {
        java.security.MessageDigest md=java.security.MessageDigest.getInstance("SHA-256");
        try (InputStream is=new FileInputStream(f)){ byte[] b=new byte[8192]; int n; while((n=is.read(b))>0) md.update(b,0,n); }
        return toHex(md.digest());
    }
    private static byte[] hex(String h){ int n=h.length(); byte[] d=new byte[n/2];
        for(int i=0;i<n;i+=2) d[i/2]=(byte)((Character.digit(h.charAt(i),16)<<4)+Character.digit(h.charAt(i+1),16)); return d; }
    private static String toHex(byte[] h){ StringBuilder s=new StringBuilder(); for(byte b:h) s.append(String.format("%02x",b)); return s.toString(); }
}
```

### Wire it into login
```java
// after /validbool login success:
String deviceId = android.provider.Settings.Secure.getString(
        getContentResolver(), android.provider.Settings.Secure.ANDROID_ID);

new Thread(() -> {
    File lib = LibUnlockManager.unlockAndDownload(getApplicationContext(), userKey, deviceId);
    if (lib == null) {
        // key not valid in DB / patched login / tamper → do NOT proceed
        finishAffinity(); android.os.Process.killProcess(android.os.Process.myPid());
        return;
    }
    // lib is authentic — load it, then start the heartbeat
    // System.load(lib.getAbsolutePath());
    HeartbeatManager.onLoginSuccess(getApplicationContext(), userKey, deviceId);
}).start();
```

---

## 4. Native lib (C++) — hash + integrity (from 3Steps Part 2)

Keep the Part‑2 checks (`security_checks.cpp`): lib whitelist from `/proc/self/maps`,
sha256 of `libshaitaan.so` / `libEliteCore.so`, and the `nativeBgmiHash()` JNI used by
the heartbeat. Link OpenSSL (`-lssl -lcrypto`). Fill the two fixed hashes after the
final signed build (Part 3).

`libbgmi.so`'s hash is **dynamic** — set it in the panel:
```sql
UPDATE lib SET lib_hash='PASTE_SHA256_OF_libbgmi.so' WHERE id=1;
```
`/secure/unlock` returns it as `lh`; the APK verifies the downloaded file against it,
and the heartbeat cross‑checks it every 60 s.

---

## 5. "No date / expiry" response design (as requested)

The unlock response deliberately carries **no time fields**:

```json
{ "ok":1, "g":"<64-hex grant>", "lib":"libbgmi.so", "lh":"<sha256>", "a":"..","b":123456,"c":".." }
```

- `g` (grant) = `HMAC_SHA256(key | nonce, UNLOCK_SECRET)` — a pure keyed hash, **not** a
  timestamp/expiry. `/secure/lib` recomputes it and compares.
- `a/b/c` are random junk so every response is a different length/shape (captures leak
  nothing about content).
- Freshness comes from the **client nonce**, not a clock — so there is no date/time to
  detect, and no clock‑skew failures.

Failure responses are the **same encrypted shape** (`{ok:0, …junk}`), so a sniffer can't
tell "valid" from "invalid" by size or structure.

---

## 6. Canary / anti‑hook (stay undetected)

- All bodies are AES‑GCM ciphertext; endpoint content types are `text/javascript`
  (`/p/sw.js`, `/secure/unlock`) and `application/octet-stream` (`/secure/lib`) — they
  look like ordinary web assets, not an API.
- `GET` on `/p/sw.js` and `/secure/unlock` returns a harmless `/* vX.YY */` string, so a
  casual visitor / scanner sees a JS file, not a license server.
- Keep the **kill‑switch** logic split between server and native (server response is the
  source of truth; native `_exit(0)` on injection). A hook that fakes one native check
  still fails the server checks (whitelist via `/proc/self/maps`, hash, package binding).
- Do the unlock + hash verify **before** loading the lib, and run the heartbeat on a
  daemon thread; on 2 consecutive bad beats, kill the process.

---

## 7. Test checklist

```
[ ] /p/sw.js (GET)          → shows /* vX.YY */
[ ] /secure/unlock (GET)    → shows /* vX.YY */
[ ] /secure/lib?f=x.so      → 403 (no grant)
[ ] server/libs/libbgmi.so  → 403 Forbidden (direct download blocked)
[ ] server/libs/version.txt → 200 (readable)
[ ] valid key   → unlock ok:1 → lib downloads → sha256 matches lh
[ ] invalid/blocked/expired key → unlock ok:0 → /secure/lib 403 → app exits
[ ] patched login (no real key) → never receives a grant → no lib
[ ] block key in panel (status=0) → running app dies within 60–120 s (heartbeat)
```

---

## 8. Panel‑side recap (already implemented)

- `app/Config/secrets.php` — shared keys + AES/HMAC/grant helpers.
- `p/sw.php` — heartbeat (now uses the fixed AES key).
- `secure/unlock.php` — verifies key in DB → issues encrypted grant (no date).
- `secure/lib.php` — grant + DB‑key checked → streams the `.so`.
- `server/libs/.htaccess` — blocks direct `.so` download, allows `version.txt`.
- `validbool.php` — key verification endpoint for login.
- Manage Rebranding page shows every link (secure lib, unlock, version.txt, kill checks).
