# Rahausub APK API

> REST API for the **Rahausub** Android/iOS app  
> Base URL: `https://api.rahausub.com.ng/api.php`  
> Payment Provider: **PaymentPoint** (Palmpay + Opay virtual accounts)  
> Push Notifications: **Firebase Cloud Messaging (FCM v1)**

---

## Architecture

Single-file PHP router (`api.php`) — no framework, no Composer. Each feature is a `case` block in one `switch` statement. All endpoints follow the same request/response pattern:

```
GET/POST https://api.rahausub.com.ng/api.php?action=ACTION_NAME
```

Auth is via a plain hex token sent in:
- Query string: `?token=TOKEN`
- HTTP header: `X-API-Token: TOKEN`
- JSON body: `{ "token": "TOKEN" }`

---

## Files

| File | Description |
|------|-------------|
| `api.php` | **Main REST router** — all 25+ actions |
| `conn.php` | DB connection (mysqli) |
| `generateBankAccount.php` | PaymentPoint virtual account creator |
| `getAccountDetails.php` | Standalone account fetch + create |
| `fcm_helper.php` | Firebase JWT + HTTP v1 push sender |
| `saveDeviceToken.php` | Register/update FCM device token |
| `broadcastNotification.php` | Admin: push to all users |
| `sendPushToUser.php` | Admin: push to specific user by email |
| `webhook.php` | PaymentPoint webhook — credits wallet on payment |
| `setup_tables.sql` | One-time DB migration (7 new tables + column additions) |
| `login.php` | Legacy standalone login endpoint |
| `register.php` | Legacy standalone register endpoint |
| `buyAirtime.php` | Legacy standalone airtime endpoint |
| `buyData.php` | Legacy standalone data endpoint |
| `*.php` | Other legacy standalone endpoints |

> **New development should use `api.php` exclusively.** Legacy files exist for backward compatibility.

---

## Database Setup (one-time)

Run `setup_tables.sql` on the `eduowrav_rahausub` database. This creates:

| Table | Purpose |
|-------|---------|
| `device_tokens` | FCM push tokens per device |
| `notifications_tbl` | In-app notifications |
| `admin_notifications_tbl` | Full admin notification management |
| `admin_notif_delivery_tbl` | Per-user delivery tracking |
| `admin_notif_api_settings` | Email/SMS channel credentials |
| `referal_tbl` | Referral relationships |
| `referal_earn_transaction_tbl` | Referral earnings |

Also adds columns to `users_tbl`: `nin`, `finger`, `referal_token`, `token`, `date_join`

---

## API Reference

### Authentication Flow

```
1. POST ?action=register   →  create account
2. POST ?action=login      →  get token
3. All other endpoints     →  ?token=TOKEN
```

---

### `health` — Health Check
```
GET /api.php?action=health
```
**Response:**
```json
{
  "status": "success",
  "data": {
    "message": "Rahausub API is running",
    "version": "1.0",
    "provider": "PaymentPoint",
    "time": "2026-06-12 08:00:00"
  }
}
```

---

### `register` — Create Account
```
POST /api.php?action=register
Content-Type: application/json
```
**Request body:**
```json
{
  "email": "user@example.com",
  "password": "Secret123",
  "sname": "Usman",
  "oname": "Musa",
  "phone": "08012345678",
  "pin": "1234",
  "state": "Kano",
  "referal": "OPTIONAL_REFERRAL_CODE"
}
```
**Response:**
```json
{
  "status": "success",
  "data": {
    "message": "Registration successful. Please submit your BVN/NIN via the KYC section to activate your virtual account."
  }
}
```
> `pin` defaults to `0000` if not provided. `referal` is the referral code of the person who referred this user.

---

### `login` — Login
```
POST /api.php?action=login
Content-Type: application/json
```
**Request body:**
```json
{
  "email": "user@example.com",
  "password": "Secret123"
}
```
**Response:**
```json
{
  "status": "success",
  "data": {
    "token": "a3f1c9d2e...",
    "id": 42,
    "email": "user@example.com",
    "sname": "Usman",
    "oname": "Musa",
    "phone": "08012345678",
    "admin_role": 0,
    "wallet_balance": 5000.00,
    "haspin": true,
    "finger": false,
    "has_account": true,
    "acc_no": "8123456789",
    "bank_name": "Palmpay",
    "acc_name": "Usman Musa"
  }
}
```
> **Save `token`** — it is required for all authenticated requests.

---

### `verify_token` — Validate Token (on app start/resume)
```
GET /api.php?action=verify_token&token=TOKEN
```
**Response:**
```json
{
  "status": "success",
  "data": {
    "valid": true,
    "user_id": 42,
    "email": "user@example.com",
    "name": "Usman Musa",
    "phone": "08012345678",
    "haspin": true,
    "finger": false,
    "wallet_balance": 5000.00,
    "has_account": true,
    "acc_no": "8123456789",
    "bank_name": "Palmpay",
    "acc_name": "Usman Musa",
    "accounts": [
      { "provider": "PaymentPoint", "bank_name": "Palmpay", "account_number": "8123456789", "account_name": "Usman Musa" },
      { "provider": "PaymentPoint", "bank_name": "Opay",    "account_number": "9876543210", "account_name": "Usman Musa" }
    ]
  }
}
```
**Error (invalid/expired token):**
```json
{ "status": "error", "message": "Invalid or expired token" }
```

---

### `profile` — Get User Profile
```
GET /api.php?action=profile&token=TOKEN
```
**Response:**
```json
{
  "status": "success",
  "data": {
    "id": 42,
    "email": "user@example.com",
    "sname": "Usman",
    "oname": "Musa",
    "phone": "08012345678",
    "state": "Kano",
    "admin_role": 0,
    "super_admin": 0,
    "referral_code": "md5hash...",
    "referral_link": "https://rahausub.com.ng/easyfinder/dashboard/register?join_with_referal=md5hash",
    "wallet_balance": 5000.00,
    "has_account": true,
    "acc_no": "8123456789",
    "bank_name": "Palmpay",
    "acc_name": "Usman Musa",
    "accounts": [ ... ],
    "bvn": "****12345",
    "has_bvn": true,
    "has_nin": false,
    "kyc_complete": true
  }
}
```

---

### `dashboard_stats` — Dashboard Data
```
GET /api.php?action=dashboard_stats&token=TOKEN
```
**Response:**
```json
{
  "status": "success",
  "data": {
    "wallet_balance": 5000.00,
    "total_transactions": 24,
    "success_transactions": 20,
    "failed_transactions": 4,
    "notifications_count": 8,
    "referral_count": 3,
    "has_account": true,
    "acc_no": "8123456789",
    "bank_name": "Palmpay",
    "acc_name": "Usman Musa",
    "accounts": [ ... ]
  }
}
```

---

### `wallet` — Get Balance
```
GET /api.php?action=wallet&token=TOKEN
```
**Response:**
```json
{ "status": "success", "data": { "balance": 5000.00, "email": "user@example.com" } }
```

---

### `wallet_history` — Wallet Transaction History
```
GET /api.php?action=wallet_history&token=TOKEN
```
**Response:**
```json
{ "status": "success", "data": { "transactions": [ { ...wallet record... } ] } }
```

---

### `transactions` — Service Transaction History
```
GET /api.php?action=transactions&token=TOKEN
```
**Response:**
```json
{
  "status": "success",
  "data": {
    "transactions": [
      {
        "id": 101,
        "title": "MTN 1GB Data",
        "phone": "08012345678",
        "date": "2026-06-12 08:00:00",
        "subtitle": "Successful",
        "amount": "300",
        "status": 1,
        "negative": true,
        "request_id": "DATA_abc123"
      }
    ]
  }
}
```

---

## KYC — BVN / NIN

### `submit_kyc` — Submit BVN and/or NIN
```
POST /api.php?action=submit_kyc&token=TOKEN
Content-Type: application/json
```
**Request body:**
```json
{
  "bvn": "12345678901",
  "nin": "98765432101"
}
```
> At least one of `bvn` or `nin` is required. Both must be exactly 11 digits.

**Response (account generated immediately):**
```json
{
  "status": "success",
  "data": {
    "message": "KYC submitted successfully",
    "account_ready": true,
    "account_generated": true,
    "acc_no": "8123456789",
    "bank_name": "Palmpay",
    "acc_name": "Usman Musa",
    "account_number": "8123456789",
    "account_name": "Usman Musa",
    "accounts": [
      { "provider": "PaymentPoint", "bank_name": "Palmpay", "account_number": "8123456789", "account_name": "Usman Musa" },
      { "provider": "PaymentPoint", "bank_name": "Opay",    "account_number": "9876543210", "account_name": "Usman Musa" }
    ]
  }
}
```
**Response (account still processing):**
```json
{
  "status": "success",
  "data": {
    "message": "KYC submitted successfully",
    "account_ready": false,
    "setup_message": "Generating your virtual account, please check back shortly.",
    "account_error": "Connection error: ..."
  }
}
```
> If `account_ready = false`, poll `get_kyc_status` every 30 seconds.

---

### `get_kyc_status` — Check KYC & Account Status
```
GET /api.php?action=get_kyc_status&token=TOKEN
```
**Response:**
```json
{
  "status": "success",
  "data": {
    "kyc_complete": true,
    "has_bvn": true,
    "has_nin": false,
    "has_account": true,
    "needs_bvn": false,
    "account_ready": true,
    "account_number": "8123456789",
    "bank_name": "Palmpay",
    "account_name": "Usman Musa",
    "acc_no": "8123456789",
    "acc_name": "Usman Musa",
    "accounts": [ ... ],
    "setup_message": ""
  }
}
```

---

### `funding_accounts` — Get Virtual Accounts for Wallet Funding
```
GET /api.php?action=funding_accounts&token=TOKEN
```
**Response:**
```json
{
  "status": "success",
  "data": {
    "has_accounts": true,
    "has_account": true,
    "accounts": [
      { "provider": "PaymentPoint", "bank_name": "Palmpay", "account_number": "8123456789", "account_name": "Usman Musa" },
      { "provider": "PaymentPoint", "bank_name": "Opay",    "account_number": "9876543210", "account_name": "Usman Musa" }
    ],
    "acc_no": "8123456789",
    "bank_name": "Palmpay",
    "acc_name": "Usman Musa",
    "provider": "PaymentPoint",
    "needs_bvn": false,
    "setup_message": ""
  }
}
```

---

### `generate_account` — Manually Trigger Account Generation
```
POST /api.php?action=generate_account&token=TOKEN
```
> Also accepts `generate_monnify` for backward compatibility.  
> Requires BVN/NIN to be already saved via `submit_kyc`.

**Response:**
```json
{
  "status": "success",
  "data": {
    "message": "Virtual account generated successfully",
    "accounts": [ ... ],
    "acc_no": "8123456789",
    "bank_name": "Palmpay",
    "acc_name": "Usman Musa"
  }
}
```

---

### `verify_account` — Check If Account Exists
```
GET /api.php?action=verify_account&token=TOKEN
```
> Also accepts `verify_monnify` for backward compatibility.

**Response:**
```json
{
  "status": "success",
  "data": {
    "has_account": true,
    "accounts": [ ... ],
    "acc_no": "8123456789",
    "bank_name": "Palmpay",
    "acc_name": "Usman Musa"
  }
}
```

---

## Airtime & Data

### `buy_airtime` — Purchase Airtime
```
POST /api.php?action=buy_airtime&token=TOKEN
Content-Type: application/json
```
**Request body:**
```json
{
  "amount": 200,
  "number": "08012345678",
  "network": "mtn",
  "pin": "1234"
}
```
> `network` values: `mtn` | `airtel` | `glo` | `9mobile` | `etisalat`  
> Use `"pin": "fingerprint"` for biometric auth.

**Response:**
```json
{
  "status": "success",
  "data": {
    "success": true,
    "message": "Airtime purchased successfully",
    "balance": 4800.00
  }
}
```
**Failure (insufficient balance):**
```json
{ "status": "error", "message": "Insufficient balance" }
```
**Failure (transaction failed, auto-refunded):**
```json
{
  "status": "success",
  "data": {
    "success": false,
    "message": "Transaction failed, wallet refunded",
    "balance": 5000.00
  }
}
```

---

### `buy_data` — Purchase Data
```
POST /api.php?action=buy_data&token=TOKEN
Content-Type: application/json
```
**Request body:**
```json
{
  "amount": 300,
  "number": "08012345678",
  "serviceID": "mtn-data",
  "variation": "mtn-1gb-300",
  "pin": "1234"
}
```
**Response:** Same structure as `buy_airtime`.

---

### `data_plans` — Get Available Data Plans
```
GET /api.php?action=data_plans&serviceID=mtn-data
```
> No auth required. Common `serviceID` values: `mtn-data`, `airtel-data`, `glo-data`, `etisalat-data`

**Response:**
```json
{
  "status": "success",
  "data": {
    "plans": [
      { "plan_id": "mtn-1gb-300", "name": "MTN 1.0GB + 1.0GB Night", "amount": "300" },
      { "plan_id": "mtn-2gb-500", "name": "MTN 2.0GB",               "amount": "500" }
    ]
  }
}
```

---

## Notifications

### `notifications` / `get_notifications` — Get All Notifications
```
GET /api.php?action=notifications&token=TOKEN
```
**Response:**
```json
{
  "status": "success",
  "data": {
    "notifications": [
      {
        "id": 5,
        "title": "New Data Deal!",
        "message": "MTN 1GB for just ₦200",
        "type": "success",
        "target": "all",
        "created_at": "2026-06-12 08:00:00",
        "is_read": false,
        "read": false
      }
    ],
    "unread_count": 3
  }
}
```
> `type` values: `info` | `success` | `warning` | `danger`

---

### `get_unread_count` — Badge Count
```
GET /api.php?action=get_unread_count&token=TOKEN
```
**Response:**
```json
{ "status": "success", "data": { "unread_count": 3 } }
```

---

### `mark_notification_read` — Mark One as Read
```
POST /api.php?action=mark_notification_read&token=TOKEN
Content-Type: application/json
```
**Request body:**
```json
{ "notification_id": 5 }
```
**Response:**
```json
{ "status": "success", "data": { "message": "Marked as read" } }
```

---

### `mark_all_notifications_read` — Mark All as Read
```
POST /api.php?action=mark_all_notifications_read&token=TOKEN
```
**Response:**
```json
{ "status": "success", "data": { "message": "All notifications marked as read" } }
```

---

## Referral System

### `referral` / `get_referral_stats` — Get Referral Info
```
GET /api.php?action=referral&token=TOKEN
```
**Response:**
```json
{
  "status": "success",
  "data": {
    "referral_code": "md5hash...",
    "referral_link": "https://rahausub.com.ng/easyfinder/dashboard/register?join_with_referal=md5hash",
    "total_referred": 5,
    "total_earnings": 500.00,
    "referred_users": [
      { "sname": "Aisha", "oname": "Bello", "email": "aisha@...", "date_join": "2026-05-01 ..." }
    ],
    "share_message": "Join Rahausub and earn on every data, airtime purchase! Use my referral code: md5hash..."
  }
}
```

---

## Security / PIN / Fingerprint

### `check_fingerprint` — Check If User Has Fingerprint Enabled
```
GET /api.php?action=check_fingerprint&email=user@example.com
```
> No auth required. Used before showing fingerprint login button.

**Response:**
```json
{ "status": "success", "data": { "finger": true, "email": "user@example.com" } }
```

---

### `toggle_fingerprint` — Enable/Disable Fingerprint
```
POST /api.php?action=toggle_fingerprint&token=TOKEN
```
**Response:**
```json
{ "status": "success", "data": { "finger": true, "message": "Fingerprint enabled" } }
```

---

### `set_pin` — Set Transaction PIN
```
POST /api.php?action=set_pin&token=TOKEN
Content-Type: application/json
```
**Request body:** `{ "pin": "1234" }` (4–6 digits)

**Response:** `{ "status": "success", "data": { "message": "PIN set successfully" } }`

---

### `change_pin` — Change PIN
```
POST /api.php?action=change_pin&token=TOKEN
Content-Type: application/json
```
**Request body:** `{ "old_pin": "1234", "new_pin": "5678" }`

---

### `change_password` — Change Password
```
POST /api.php?action=change_password&token=TOKEN
Content-Type: application/json
```
**Request body:** `{ "old_password": "OldPass", "new_password": "NewPass" }`

---

## FCM Push Notifications (Standalone Endpoints)

### `saveDeviceToken.php` — Register FCM Token
```
POST https://api.rahausub.com.ng/saveDeviceToken.php
Content-Type: application/json
```
**Request body:**
```json
{
  "token": "USER_AUTH_TOKEN",
  "fcm_token": "FIREBASE_DEVICE_TOKEN",
  "platform": "android"
}
```
> Call this after login and whenever FCM token refreshes (onTokenRefresh).  
> `platform` values: `android` | `ios`

**Response:**
```json
{ "success": true, "message": "Token saved" }
```

---

### `broadcastNotification.php` — Push to All Users (Admin Only)
```
POST https://api.rahausub.com.ng/broadcastNotification.php
Content-Type: application/json
```
**Request body:**
```json
{
  "admin_key": "RahSubAdmin2026!",
  "title": "🔥 Flash Sale!",
  "body": "MTN 1GB for ₦200 today only",
  "platform": "all",
  "data": { "screen": "DataPlans" }
}
```
> `platform` values: `all` | `android` | `ios`

**Response:**
```json
{ "success": true, "sent": 142, "failed": 3 }
```

---

### `sendPushToUser.php` — Push to Specific User (Admin Only)
```
POST https://api.rahausub.com.ng/sendPushToUser.php
Content-Type: application/json
```
**Request body:**
```json
{
  "admin_key": "RahSubAdmin2026!",
  "email": "user@example.com",
  "title": "Transaction Complete",
  "body": "Your wallet has been credited ₦5,000",
  "data": { "screen": "Wallet" }
}
```

---

### `webhook.php` — PaymentPoint Webhook (Server-to-Server)
> Called by PaymentPoint when a user funds their virtual account.  
> Verifies HMAC signature, credits `wallet_tbl` automatically.

---

## Error Responses

All errors follow this format:
```json
{ "status": "error", "message": "Descriptive error message" }
```

| HTTP Code | Meaning |
|-----------|---------|
| `400` | Bad request / validation error |
| `401` | Unauthorized — invalid or missing token |
| `404` | Unknown action |
| `405` | Wrong HTTP method |
| `409` | Conflict (e.g. BVN already used) |
| `422` | Unprocessable (e.g. PaymentPoint failed) |
| `503` | Database unavailable |

---

## Expo / React Native Integration

```javascript
const API_BASE = 'https://api.rahausub.com.ng/api.php';

async function apiCall(action, params = {}, token = null) {
  const url = token ? `${API_BASE}?action=${action}&token=${token}` : `${API_BASE}?action=${action}`;
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(params),
  });
  return res.json();
}

// On app start — verify token
const status = await apiCall('verify_token', {}, savedToken);
if (!status.data?.valid) {
  // Token expired → show login screen
}

// After login — register FCM token
import * as Notifications from 'expo-notifications';
const expoPushToken = (await Notifications.getExpoPushTokenAsync()).data;
await fetch('https://api.rahausub.com.ng/saveDeviceToken.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ token: userAuthToken, fcm_token: expoPushToken, platform: 'android' }),
});

// KYC flow
const kyc = await apiCall('get_kyc_status', {}, token);
if (kyc.data.needs_bvn) {
  // Show BVN input screen
  const result = await apiCall('submit_kyc', { bvn: '12345678901' }, token);
  if (result.data.account_ready) {
    // Show virtual account numbers
  } else {
    // Poll get_kyc_status every 30 seconds
  }
}
```

---

## Deployment

| Item | Value |
|------|-------|
| Server | premium102.web-hosting.com (cPanel) |
| API path | `/home/eduowrav/api.rahausub.com.ng/` |
| Database | `eduowrav_rahausub` |
| Firebase SA JSON | `/home/eduowrav/firebase_service_account.json` |
| Firebase Project | `vtu-apps-5c6af` |
| Payment provider | PaymentPoint (`api.paymentpoint.co`) |
| Virtual account banks | Palmpay (20946) + Opay (20897) |

