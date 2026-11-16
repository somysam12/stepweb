# Native Lib (C++) — Mod Kill + Login/Unlock Implementation

This is the **native (.so) side**. HTTP on Android native is done with **libcurl**
(bundle it) — if you'd rather do networking in Java and pass the result to native,
that works too (see the note at the end).

---

## 0. What version goes where

| Where | Value | Who sets it |
|---|---|---|
| Inside the mod lib (C++) | `MOD_VERSION = "1.0"` | you, at build time |
| Panel → Mod Lib Kill → "Current Mod Version" | `1.1` | you, in the panel |
| `server/modlib_version.php` response | current panel version + `kill` flag | server (automatic) |

The lib sends **its own** `MOD_VERSION`. The server replies `kill=1` if that version
is **older** than the panel's current mod version (or if "Kill ALL" is ON).

---

## 1. Mod Lib — kill check (C++)

```cpp
// ── mod_kill.cpp ──────────────────────────────────────────────
#include <string>
#include <cstdlib>
#include <cstring>
#include <unistd.h>
#include <curl/curl.h>          // link -lcurl (with SSL)

// The version THIS mod lib was built with. Bump it every release.
static const char*  MOD_VERSION  = "1.0";
// Endpoint from the panel (Mod Lib Kill tab → check URL). XOR-split it in real builds.
static const char*  MOD_URL_BASE = "https://arkcheat.battleground-hub.shop/server/modlib_version.php";
static const uint8_t MOD_XOR_KEY = 0xA5;   // must match server (MOD_XOR_KEY)

// collect HTTP body
static size_t wr_cb(void* p, size_t s, size_t n, void* u) {
    ((std::string*)u)->append((char*)p, s*n); return s*n;
}

// base64 decode
static std::string b64dec(const std::string& in) {
    static const std::string T =
      "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    int val=0, bits=-8; std::string out;
    for (unsigned char c : in) {
        if (c=='=' || T.find(c)==std::string::npos) break;
        val=(val<<6)+(int)T.find(c); bits+=6;
        if (bits>=0){ out+=char((val>>bits)&0xFF); bits-=8; }
    }
    return out;
}

// tiny JSON int / string readers (keys are simple, no nesting)
static int json_int(const std::string& j, const char* key) {
    std::string k = std::string("\"")+key+"\":";
    size_t p = j.find(k); if (p==std::string::npos) return -1;
    p += k.size(); return atoi(j.c_str()+p);
}
static std::string json_str(const std::string& j, const char* key) {
    std::string k = std::string("\"")+key+"\":\"";
    size_t p = j.find(k); if (p==std::string::npos) return "";
    p += k.size(); size_t e = j.find('"', p);
    return (e==std::string::npos) ? "" : j.substr(p, e-p);
}

// version compare: returns <0 if a<b, 0 if equal, >0 if a>b  ("1.0" vs "1.1")
static int ver_cmp(const char* a, const char* b) {
    while (*a || *b) {
        int na=atoi(a), nb=atoi(b);
        if (na!=nb) return na-nb;
        while (*a && *a!='.') a++; if (*a=='.') a++;
        while (*b && *b!='.') b++; if (*b=='.') b++;
    }
    return 0;
}

void mod_kill_die();               // defined in the JNI section below (opens redirect + crashes)

// Call this on mod-lib load, and again on a timer (e.g. every 60s).
bool mod_kill_check() {
    std::string url = std::string(MOD_URL_BASE) + "?cv=" + MOD_VERSION;
    std::string body;
    CURL* c = curl_easy_init();
    if (!c) return false;                          // network fail → don't crash
    curl_easy_setopt(c, CURLOPT_URL, url.c_str());
    curl_easy_setopt(c, CURLOPT_WRITEFUNCTION, wr_cb);
    curl_easy_setopt(c, CURLOPT_WRITEDATA, &body);
    curl_easy_setopt(c, CURLOPT_TIMEOUT, 12L);
    curl_easy_setopt(c, CURLOPT_SSL_VERIFYPEER, 0L);
    CURLcode rc = curl_easy_perform(c);
    curl_easy_cleanup(c);
    if (rc != CURLE_OK || body.empty()) return false;

    // base64 → XOR(0xA5) → JSON
    std::string raw = b64dec(body), plain;
    plain.reserve(raw.size());
    for (char ch : raw) plain += char((unsigned char)ch ^ MOD_XOR_KEY);

    int kill = json_int(plain, "kill");
    if (kill == 1) {
        std::string redirect = json_str(plain, "redirect_url");
        // hand redirect to the JNI helper, then crash
        extern void mod_set_redirect(const std::string&);
        mod_set_redirect(redirect);
        mod_kill_die();                             // opens link (if any) + crashes
        return true;                                // (never reached)
    }
    return false;
}
```

---

## 2. Redirect + crash (JNI helper)

To open a URL you need a `Context`. Cache the `JavaVM` in `JNI_OnLoad` and call a
tiny Java bridge. Then crash.

```cpp
// ── jni_bridge.cpp ────────────────────────────────────────────
#include <jni.h>
#include <string>
#include <cstdlib>

static JavaVM* g_vm = nullptr;
static std::string g_redirect;

extern "C" JNIEXPORT jint JNI_OnLoad(JavaVM* vm, void*) {
    g_vm = vm;
    return JNI_VERSION_1_6;
}
void mod_set_redirect(const std::string& url) { g_redirect = url; }

// Java side must provide:  com.yourpackage.NativeBridge.openUrl(String)
void mod_kill_die() {
    if (!g_redirect.empty() && g_vm) {
        JNIEnv* env = nullptr;
        if (g_vm->AttachCurrentThread(&env, nullptr) == JNI_OK && env) {
            jclass cls = env->FindClass("com/yourpackage/NativeBridge");
            if (cls) {
                jmethodID m = env->GetStaticMethodID(cls, "openUrl", "(Ljava/lang/String;)V");
                if (m) {
                    jstring s = env->NewStringUTF(g_redirect.c_str());
                    env->CallStaticVoidMethod(cls, m, s);
                }
            }
        }
    }
    // permanent crash — no bypass
    _exit(0);          // or: abort();  or:  *(volatile int*)0 = 0;
}
```

```java
// ── NativeBridge.java (in the APK) ────────────────────────────
package com.yourpackage;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;

public class NativeBridge {
    static Context APP;                       // set once from Application/Activity: NativeBridge.APP = getApplicationContext();
    public static void openUrl(String url) {
        try {
            Intent i = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            APP.startActivity(i);
        } catch (Throwable t) { /* ignore, still crashes after */ }
    }
}
```

**Result:** panel me version bump → mod lib ko `kill=1` milta hai → agar telegram link
set hai to wo khulta hai → phir lib crash. Bypass possible nahi (server hamesha kill
bolega us version ke liye).

---

## 3. Login + lib-access system (how the pieces fit)

The **key login** and **lib download** live mostly in Java (`HeartbeatManager`,
`LibUnlockManager` — see `SECURE_LIB_DELIVERY.md`). The native lib's jobs are:

1. **Integrity** (from `3Steps.md` Part 2): lib whitelist via `/proc/self/maps`,
   sha256 of `libshaitaan.so` / `libEliteCore.so` (hashes you paste on the
   **Heartbeat & Security** page), and `nativeBgmiHash()` used by the heartbeat.
2. **Mod kill** (section 1–2 above).
3. Optionally the mod lib can **verify the key itself** before activating, so a
   patched Java login can't turn the mod on. Minimal C++ version:

```cpp
// native key check against /validbool — returns true only if the DB says valid
bool native_key_ok(const char* userKey, const char* serial) {
    std::string url  = "https://arkcheat.battleground-hub.shop/validbool";
    std::string post = std::string("user_key=") + userKey + "&serial=" + serial + "&game=PUBG";
    std::string body;
    CURL* c = curl_easy_init(); if (!c) return false;
    curl_easy_setopt(c, CURLOPT_URL, url.c_str());
    curl_easy_setopt(c, CURLOPT_POSTFIELDS, post.c_str());
    curl_easy_setopt(c, CURLOPT_WRITEFUNCTION, wr_cb);
    curl_easy_setopt(c, CURLOPT_WRITEDATA, &body);
    curl_easy_setopt(c, CURLOPT_TIMEOUT, 12L);
    curl_easy_setopt(c, CURLOPT_SSL_VERIFYPEER, 0L);
    CURLcode rc = curl_easy_perform(c); curl_easy_cleanup(c);
    if (rc != CURLE_OK) return false;
    // {"status":true,...}  →  ok
    return body.find("\"status\":true") != std::string::npos;
}
```

**Full flow the app follows:**
```
1. User enters key → Java posts to /validbool → status:true?  (login)
2. Java calls /secure/unlock (AES) → gets a grant  (only if key valid in DB)
3. Java downloads the real lib from /secure/lib using the grant
4. Java verifies sha256(lib) == the hash from the panel, then System.load()
5. Native runs: integrity checks + nativeBgmiHash + mod_kill_check() on a timer
6. Heartbeat every 60s to /p/sw.js (AES) — block/expire/tamper → crash
```

The mod lib only *activates* after `native_key_ok()` **and** `mod_kill_check()` pass.

---

## 4. Build notes

```
# CMakeLists.txt
target_link_libraries(yourlib curl ssl crypto)
```
- Bump `MOD_VERSION` every mod release, then set the same new version in the panel.
- XOR-split every URL string in production (don't ship plaintext URLs).
- Get each lib's sha256 with `sha256sum lib.so` and paste on the Heartbeat page.
```
