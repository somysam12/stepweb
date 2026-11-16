# Panel Integration Guide — AES-256-GCM + HMAC Encrypted Protocol

How to connect **any** native C++ lib or Java/Kotlin APK to the HB/ArkCheat panel's
two secured endpoints: `/validbool` (login) and `/p/sw.js` (heartbeat).

> **Panel base URL:** `https://onyxbypass.battleground-hub.shop`  
> Change this in one place (`KCConfig.h` or your equivalent constant) — no hash to recompute.

---

## 1. Why AES-GCM / What Makes This Secure

Both endpoints are encrypted **both ways** (request and response):

| Layer | What it blocks |
|---|---|
| AES-256-GCM encrypted request | Server sees only ciphertext — no plain key/device in transit |
| HMAC-SHA256 of request | Fake or replayed requests rejected before decrypt |
| AES-256-GCM encrypted response | A cracker who patches the URL to a fake server **cannot** forge a valid encrypted response without the key → decrypt fails → your code treats it as crash |
| Random nonce per call | Wire bytes differ every call — replay protection built in |
| `q` field only, no readable fields | No `"status":true` string to search/patch in memory |
| Response wiped from memory | Heap/canary dump finds no plain key or token |

---

## 2. Shared Secrets (native side and panel side must match)

These values live in your native header **and** in `finalpanel/app/Config/secrets.php`:

```cpp
// AES-256-GCM key — 32 bytes as 64 hex chars
#define HB_AES_KEY_HEX  "5c3d9f2a8b1e4c6079d2f3a4b5c6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8"

// HMAC-SHA256 key (authenticates the request)
#define HB_HMAC_KEY_STR "X9kL2mQ7vR4nB1eY3pT8wS5uJ6dA0cF"

// XOR masks for expiry / timestamp in the response
#define HB_XKEY1  0xB7E15163u
#define HB_XKEY2  0x9E3779B9u
```

Panel side (`app/Config/secrets.php`):
```php
define('HB_AES_KEY',  '5c3d9f2a8b1e4c6079d2f3a4b5c6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8');
define('HB_HMAC_KEY', 'X9kL2mQ7vR4nB1eY3pT8wS5uJ6dA0cF');
define('HB_XKEY1',    0xB7E15163);
define('HB_XKEY2',    0x9E3779B9);
```

---

## 3. Endpoints

### `/validbool` — Login / Key Verification

**URL:** `https://onyxbypass.battleground-hub.shop/validbool`  
**Method:** POST form-encoded (`application/x-www-form-urlencoded`)

**Request fields:**

| Field | Value |
|---|---|
| `p1` | `base64url( nonce[12] + tag[16] + AES-GCM-ciphertext )` of the JSON payload |
| `p2` | `HMAC_SHA256(p1, HB_HMAC_KEY_STR)` as lowercase hex |

**JSON payload** (encrypted into `p1`):
```json
{
  "k":   "<license_key>",
  "d":   "<device_uuid>",
  "t":   1753000000,
  "pkg": "com.yourapp.package",
  "libs": "",
  "bh":  ""
}
```
- `k` = the user's license key (read from your `.lic` file or UI)  
- `d` = device UUID (any stable per-device string)  
- `t` = `time(0)` — panel rejects if `|now - t| > 120s` (replay protection)  
- `pkg` = APK package name — panel binds it on first verify; different package later = rejected  
- `libs`, `bh` = leave as empty string (reserved)

**Response:** AES-GCM encrypted blob (same format as `p1`, decoded the same way)  
Decrypted JSON:
```json
{ "q": 7, "x": 3453824419, "z": 2891234, "w": 583921, "u": "a1b2c3d4e5", "v": "f6a7b8c9" }
```

| Field | Meaning |
|---|---|
| `q` | **The only field that matters.** 7=valid · 1=maintenance · 2=crash/blocked |
| `x` | `expiry_unix XOR HB_XKEY1` — decode as `x ^ 0xB7E15163` to get real expiry |
| `z` | `now XOR HB_XKEY2` — decode as `z ^ 0x9E3779B9` for server timestamp |
| `w`, `u`, `v` | Random junk — ignore |

---

### `/p/sw.js` — Heartbeat (every 60 seconds)

**URL:** `https://onyxbypass.battleground-hub.shop/p/sw.js`  
**Method:** POST form-encoded — **exactly the same protocol** as `/validbool`

Same `p1`/`p2` format, same JSON payload (`k`, `d`, `t`, `pkg`, `libs`, `bh`).  
Same encrypted response, same `q` field.

The only difference is the URL. Use the **same `KC_SecureBeat()` function** for both.

---

## 4. Native C++ Implementation (Copy-Paste Ready)

Drop this into your project as `KCSecureHB.h`. Requires: OpenSSL (`EVP`, `HMAC`, `RAND`) + libcurl.

```cpp
#pragma once
#include <string>
#include <cstring>
#include <cstdio>
#include <cstdlib>
#include <ctime>
#include <algorithm>
#include <curl/curl.h>
#include <openssl/evp.h>
#include <openssl/hmac.h>
#include <openssl/rand.h>

// ── Change these to match your panel's secrets.php ──────────────────────────
#define HB_AES_KEY_HEX  "5c3d9f2a8b1e4c6079d2f3a4b5c6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8"
#define HB_HMAC_KEY_STR "X9kL2mQ7vR4nB1eY3pT8wS5uJ6dA0cF"
// ────────────────────────────────────────────────────────────────────────────

static void _kc_hex2bin(const char* hex, unsigned char* out, int n) {
    for (int i = 0; i < n; i++) {
        unsigned int b = 0; sscanf(hex + i*2, "%2x", &b); out[i] = (unsigned char)b;
    }
}

static const char* _KC_B64 =
    "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_";

static std::string _kc_b64url_enc(const unsigned char* d, size_t n) {
    std::string o; int val = 0, bits = -6;
    for (size_t i = 0; i < n; i++) {
        val = (val << 8) + d[i]; bits += 8;
        while (bits >= 0) { o += _KC_B64[(val >> bits) & 0x3F]; bits -= 8; }
    }
    if (bits > -6) o += _KC_B64[((val << 8) >> (bits + 8)) & 0x3F];
    return o;
}

static std::string _kc_b64url_dec(const std::string& in) {
    int T[256]; memset(T, -1, sizeof(T));
    for (int i = 0; i < 64; i++) T[(unsigned char)_KC_B64[i]] = i;
    std::string o; int val = 0, bits = -8;
    for (unsigned char c : in) {
        if (T[c] == -1) break;
        val = (val << 6) + T[c]; bits += 6;
        if (bits >= 0) { o += char((val >> bits) & 0xFF); bits -= 8; }
    }
    return o;
}

// Encrypt plain → base64url( nonce[12] + tag[16] + ciphertext )
static std::string _kc_aes_enc(const std::string& plain) {
    unsigned char key[32]; _kc_hex2bin(HB_AES_KEY_HEX, key, 32);
    unsigned char nonce[12]; RAND_bytes(nonce, 12);
    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new(); if (!ctx) return "";
    EVP_EncryptInit_ex(ctx, EVP_aes_256_gcm(), NULL, NULL, NULL);
    EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, 12, NULL);
    EVP_EncryptInit_ex(ctx, NULL, NULL, key, nonce);
    std::string ct; ct.resize(plain.size() + 16);
    int len = 0, ctlen = 0;
    EVP_EncryptUpdate(ctx, (unsigned char*)&ct[0], &len,
                      (const unsigned char*)plain.data(), (int)plain.size());
    ctlen = len;
    EVP_EncryptFinal_ex(ctx, (unsigned char*)&ct[0] + len, &len); ctlen += len;
    unsigned char tag[16];
    EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_GET_TAG, 16, tag);
    EVP_CIPHER_CTX_free(ctx);
    std::string raw;
    raw.append((char*)nonce, 12);
    raw.append((char*)tag, 16);
    raw.append(ct.data(), ctlen);
    return _kc_b64url_enc((const unsigned char*)raw.data(), raw.size());
}

// Decrypt base64url( nonce[12] + tag[16] + ciphertext ) → plain
static bool _kc_aes_dec(const std::string& b64, std::string& out) {
    std::string raw = _kc_b64url_dec(b64);
    if (raw.size() < 29) return false;
    unsigned char key[32]; _kc_hex2bin(HB_AES_KEY_HEX, key, 32);
    const unsigned char* nonce = (const unsigned char*)raw.data();
    const unsigned char* tag   = (const unsigned char*)raw.data() + 12;
    const unsigned char* ct    = (const unsigned char*)raw.data() + 28;
    int ctlen = (int)raw.size() - 28;
    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new(); if (!ctx) return false;
    EVP_DecryptInit_ex(ctx, EVP_aes_256_gcm(), NULL, NULL, NULL);
    EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, 12, NULL);
    EVP_DecryptInit_ex(ctx, NULL, NULL, key, nonce);
    out.resize(ctlen); int len = 0, outl = 0;
    EVP_DecryptUpdate(ctx, (unsigned char*)&out[0], &len, ct, ctlen); outl = len;
    EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_TAG, 16, (void*)tag);
    int ok = EVP_DecryptFinal_ex(ctx, (unsigned char*)&out[0] + len, &len); outl += len;
    EVP_CIPHER_CTX_free(ctx);
    if (ok <= 0) return false;
    out.resize(outl);
    return true;
}

// HMAC-SHA256(data, HMAC_KEY) → lowercase hex
static std::string _kc_hmac_hex(const std::string& data) {
    unsigned char mac[32]; unsigned int l = 0;
    HMAC(EVP_sha256(), HB_HMAC_KEY_STR, (int)strlen(HB_HMAC_KEY_STR),
         (const unsigned char*)data.data(), data.size(), mac, &l);
    static const char* h = "0123456789abcdef";
    std::string o;
    for (unsigned i = 0; i < l; i++) { o += h[mac[i] >> 4]; o += h[mac[i] & 0xF]; }
    return o;
}

static size_t _kc_curl_wr(char* p, size_t s, size_t n, void* u) {
    ((std::string*)u)->append(p, s * n); return s * n;
}

// One encrypted call to hbUrl (works for /validbool AND /p/sw.js).
// Returns: 7=valid · 1=maintenance(soft) · 2=blocked/expired/crash · -1=network/decrypt fail
static int KC_SecureBeat(const std::string& hbUrl, const std::string& key,
                         const std::string& dev, const std::string& pkg) {
    time_t t = time(0);
    char js[768];
    snprintf(js, sizeof(js),
        "{\"k\":\"%s\",\"d\":\"%s\",\"t\":%ld,\"pkg\":\"%s\",\"libs\":\"\",\"bh\":\"\"}",
        key.c_str(), dev.c_str(), (long)t, pkg.c_str());

    std::string p1 = _kc_aes_enc(js);
    if (p1.empty()) return -1;
    std::string p2 = _kc_hmac_hex(p1);
    std::string post = "p1=" + p1 + "&p2=" + p2;

    std::string resp;
    CURL* c = curl_easy_init(); if (!c) return -1;
    curl_easy_setopt(c, CURLOPT_URL,           hbUrl.c_str());
    curl_easy_setopt(c, CURLOPT_POSTFIELDS,    post.c_str());
    curl_easy_setopt(c, CURLOPT_WRITEFUNCTION, _kc_curl_wr);
    curl_easy_setopt(c, CURLOPT_WRITEDATA,     &resp);
    curl_easy_setopt(c, CURLOPT_SSL_VERIFYPEER, 0L);
    curl_easy_setopt(c, CURLOPT_SSL_VERIFYHOST, 0L);
    curl_easy_setopt(c, CURLOPT_TIMEOUT,        12L);
    curl_easy_setopt(c, CURLOPT_FOLLOWLOCATION, 1L);
    CURLcode rc = curl_easy_perform(c);
    curl_easy_cleanup(c);
    if (rc != CURLE_OK || resp.empty()) return -1;

    int q = -1;
    std::string plain;
    if (_kc_aes_dec(resp, plain)) {
        size_t pos = plain.find("\"q\":");
        if (pos != std::string::npos) q = atoi(plain.c_str() + pos + 4);
    }
    // wipe response from memory (canary/dump safe)
    std::fill(resp.begin(),  resp.end(),  '\0');
    std::fill(plain.begin(), plain.end(), '\0');
    return q;
}
```

### Linking (Android.mk)
```makefile
LOCAL_LDLIBS    := -llog -lz
LOCAL_STATIC_LIBRARIES := openssl curl
# or whatever your existing link flags are — just make sure OpenSSL and curl are linked
```

---

## 5. How to Use KC_SecureBeat in Your Code

### Login (called once at startup)

```cpp
#include "KCSecureHB.h"

// globals set on successful login, used by heartbeat
std::string g_HBKey, g_HBSerial, g_HBPkg;
bool bValid = false;

void Login(const char* userKey) {
    std::string key(userKey);
    std::string dev  = GetDeviceUUID();    // any stable per-device string
    std::string pkg  = GetPackageName();   // e.g. "com.krafton.pubgmobile"
    std::string url  = "https://onyxbypass.battleground-hub.shop/validbool";

    int q = KC_SecureBeat(url, key, dev, pkg);

    if (q == 7) {
        bValid     = true;           // server says valid
        g_HBKey    = key;            // save for heartbeat
        g_HBSerial = dev;
        g_HBPkg    = pkg;
        // show menu / activate features
    } else if (q == 1) {
        // maintenance — show "Panel is under maintenance" and exit
    } else {
        // q==2 or q==-1 → invalid key / blocked / fake server
        // raise(SIGKILL) or show error
    }
}
```

### Heartbeat Thread (every 60 seconds after login)

```cpp
static int _hb_fails = 0;

void* HeartbeatThread(void*) {
    std::string hbUrl = "https://onyxbypass.battleground-hub.shop/p/sw.js";

    while (true) {
        sleep(60);

        if (g_HBKey.empty()) continue;  // not logged in yet

        int q = KC_SecureBeat(hbUrl, g_HBKey, g_HBSerial, g_HBPkg);

        if (q == 7) {
            _hb_fails = 0;   // key still valid
        } else if (q == 1) {
            _hb_fails = 0;   // maintenance — keep running (soft)
        } else if (q == 2) {
            raise(SIGKILL);  // blocked / expired / package mismatch → hard crash
        } else {
            // q==-1: network fail OR fake server (decrypt failed)
            if (++_hb_fails >= 2) raise(SIGKILL);  // 2 consecutive fails → crash
        }
    }
    return nullptr;
}
```

Start the thread after your lib loads:
```cpp
pthread_t hbt;
pthread_create(&hbt, nullptr, HeartbeatThread, nullptr);
pthread_detach(hbt);
```

---

## 6. q Value Reference

| `q` value | Meaning | What to do |
|---|---|---|
| `7` | Key valid, not expired, device OK, package OK | Proceed / keep running |
| `1` | Panel maintenance mode | Soft — keep app alive, show maintenance message if needed |
| `2` | Key invalid / blocked / expired / device limit / package mismatch | Hard crash (`raise(SIGKILL)`) |
| `-1` | AES decrypt failed (network error or fake server) | Count failures; crash after 2 |

---

## 7. URL Obfuscation (Recommended)

Never store the panel URL as a plain string literal. Use compile-time XOR so
`strings`/`jadx`/`readelf` see only garbage in the binary:

```cpp
// rolling-key XOR — each char XOR'd differently
template<std::size_t N>
struct _KCStr {
    char data[N];
    constexpr _KCStr(const char (&s)[N]) : data{} {
        for (std::size_t i = 0; i < N; i++)
            data[i] = s[i] ^ (char)(0x5A ^ (uint8_t)(i * 0xA3));
    }
    __attribute__((noinline)) std::string str() const {
        std::string r(N-1, '\0');
        for (std::size_t i = 0; i < N-1; i++)
            r[i] = data[i] ^ (char)(0x5A ^ (uint8_t)(i * 0xA3));
        return r;
    }
};
#define OBF_S(s) (_KCStr<sizeof(s)>(s))
#define OBF(s)   (_KCStr<sizeof(s)>(s).str())

// Usage:
static std::string GetLoginURL() {
    static constexpr auto _e = OBF_S("https://onyxbypass.battleground-hub.shop/validbool");
    return _e.str();
}
static std::string GetHBURL() {
    static constexpr auto _e = OBF_S("https://onyxbypass.battleground-hub.shop/p/sw.js");
    return _e.str();
}
```

Then call:
```cpp
int q = KC_SecureBeat(GetLoginURL(), key, dev, pkg);  // login
int q = KC_SecureBeat(GetHBURL(),   key, dev, pkg);  // heartbeat
```

**Changing the URL:** Edit only the string literal above. No hash to recompute, no value to update anywhere else. Rebuild and redeploy.

---

## 8. Java / Kotlin Integration (APK Only, No NDK)

If your project is pure Java/Kotlin (no native lib), use `OkHttp` + `Conscrypt`/`Bouncy Castle` for AES-GCM:

### Gradle dependencies
```groovy
implementation 'com.squareup.okhttp3:okhttp:4.12.0'
implementation 'org.bouncycastle:bcprov-jdk15on:1.70'
```

### AES-256-GCM + HMAC helper
```kotlin
import org.bouncycastle.jce.provider.BouncyCastleProvider
import java.security.Security
import javax.crypto.Cipher
import javax.crypto.Mac
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.SecretKeySpec
import java.util.Base64

object PanelSecurity {
    private val AES_KEY = hexToBytes("5c3d9f2a8b1e4c6079d2f3a4b5c6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8")
    private val HMAC_KEY = "X9kL2mQ7vR4nB1eY3pT8wS5uJ6dA0cF".toByteArray()

    init { Security.addProvider(BouncyCastleProvider()) }

    fun hexToBytes(hex: String): ByteArray =
        ByteArray(hex.length / 2) { hex.substring(it*2, it*2+2).toInt(16).toByte() }

    fun b64urlEnc(data: ByteArray): String =
        Base64.getUrlEncoder().withoutPadding().encodeToString(data)

    fun b64urlDec(s: String): ByteArray =
        Base64.getUrlDecoder().decode(s)

    fun encrypt(plain: String): String {
        val nonce = ByteArray(12).also { java.security.SecureRandom().nextBytes(it) }
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, SecretKeySpec(AES_KEY, "AES"), GCMParameterSpec(128, nonce))
        val ct = cipher.doFinal(plain.toByteArray())
        // ct from BouncyCastle GCM = ciphertext + tag[16]
        val tag = ct.takeLast(16).toByteArray()
        val ctOnly = ct.dropLast(16).toByteArray()
        val raw = nonce + tag + ctOnly
        return b64urlEnc(raw)
    }

    fun decrypt(b64: String): String? = try {
        val raw = b64urlDec(b64)
        if (raw.size < 29) return null
        val nonce = raw.sliceArray(0..11)
        val tag   = raw.sliceArray(12..27)
        val ct    = raw.sliceArray(28 until raw.size)
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, SecretKeySpec(AES_KEY, "AES"), GCMParameterSpec(128, nonce))
        cipher.updateAAD(ByteArray(0))
        // reassemble as ct + tag for BouncyCastle
        String(cipher.doFinal(ct + tag))
    } catch (e: Exception) { null }

    fun hmacHex(data: String): String {
        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(HMAC_KEY, "HmacSHA256"))
        return mac.doFinal(data.toByteArray()).joinToString("") { "%02x".format(it) }
    }
}
```

### OkHttp call
```kotlin
import okhttp3.*
import org.json.JSONObject
import java.io.IOException

fun secureBeat(url: String, key: String, deviceId: String, pkg: String): Int {
    val payload = """{"k":"$key","d":"$deviceId","t":${System.currentTimeMillis()/1000},"pkg":"$pkg","libs":"","bh":""}"""
    val p1 = PanelSecurity.encrypt(payload)
    val p2 = PanelSecurity.hmacHex(p1)

    val client = OkHttpClient()
    val body = FormBody.Builder().add("p1", p1).add("p2", p2).build()
    val req  = Request.Builder().url(url).post(body).build()

    return try {
        val resp = client.newCall(req).execute()
        val respBody = resp.body?.string() ?: return -1
        val plain = PanelSecurity.decrypt(respBody) ?: return -1
        val json  = JSONObject(plain)
        json.getInt("q")    // 7=valid  1=soft  2=crash  -1=fail
    } catch (e: IOException) { -1 }
}

// Login (call on a background thread)
fun login(key: String) {
    val q = secureBeat(
        "https://onyxbypass.battleground-hub.shop/validbool",
        key, getDeviceId(), packageName
    )
    when (q) {
        7    -> { /* valid — activate features */ }
        1    -> { /* maintenance */ }
        else -> { /* block / crash */ android.os.Process.killProcess(android.os.Process.myPid()) }
    }
}

// Heartbeat (call every 60s on a background thread)
var hbFails = 0
fun heartbeat(key: String) {
    val q = secureBeat(
        "https://onyxbypass.battleground-hub.shop/p/sw.js",
        key, getDeviceId(), packageName
    )
    when {
        q == 7  -> hbFails = 0
        q == 1  -> hbFails = 0  // maintenance — keep running
        q == 2  -> android.os.Process.killProcess(android.os.Process.myPid())
        else    -> { if (++hbFails >= 2) android.os.Process.killProcess(android.os.Process.myPid()) }
    }
}
```

---

## 9. Panel-Side: How /validbool Processes the Request

For reference, here is what happens server-side after your `p1`/`p2` arrive:

```
1. Verify HMAC:  hash_hmac('sha256', p1, HB_HMAC_KEY) === p2
                 → if no match: return AES(q=2)   [bad/fake request]

2. Decrypt p1:   hb_aes_decrypt(p1)  → JSON {k, d, t, pkg}

3. Replay check: |time() - t| <= 120 seconds

4. Maintenance:  if getSetting('maintenance_mode') == '1' → AES(q=1)

5. Key lookup:   SELECT * FROM keys_code WHERE user_key=k
                 → not found, inactive, or expired → AES(q=2)

6. Device bind:  if d not in key.devices → add it (up to max_devices)
                 → over limit → AES(q=2)

7. Pkg bind:     first verify → store pkg; later verify → must match
                 → mismatch → AES(q=2)

8. Success:      AES(q=7, x=expiry^XKEY1, z=now^XKEY2, w/u/v=random)
```

The `/p/sw.js` heartbeat does steps 1–8 identically.

---

## 10. Quick Checklist for Adding to a New Source

- [ ] Copy `KCSecureHB.h` into your `jni/` folder
- [ ] Set `HB_AES_KEY_HEX` and `HB_HMAC_KEY_STR` to match `secrets.php`
- [ ] Replace the login endpoint call with `KC_SecureBeat(loginUrl, key, device, pkg)`
- [ ] Set `bValid = (q == 7)` — **never** `bValid = (token == auth)` or any client-side check
- [ ] Start a background thread calling `KC_SecureBeat(hbUrl, ...)` every 60s
- [ ] On `q==2`: `raise(SIGKILL)` — do not just hide the menu
- [ ] On `q==-1` twice in a row: `raise(SIGKILL)` (blocks fake-server URLs)
- [ ] Obfuscate both URLs at compile time (`OBF_S(...)`)
- [ ] Wipe `resp` and `plain` after each call (`std::fill` with `'\0'`)
- [ ] Add OpenSSL + curl to `LOCAL_LDLIBS` in `Android.mk`
