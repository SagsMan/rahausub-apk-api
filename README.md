# Rahausub APK API

> REST API for the **Rahausub** Android/iOS app  
> Base URL: `https://api.rahausub.com.ng/api.php`  
> Payment Provider: **PaymentPoint** (Palmpay + Opay virtual accounts)  
> Push Notifications: **Firebase Cloud Messaging (FCM v1)**  
> Webhook: `https://api.rahausub.com.ng/webhook.php`

---

## Architecture

Single-file PHP router (`api.php`) — no framework, no Composer. Each feature is a `case` block in one `switch` statement.

```
GET/POST https://api.rahausub.com.ng/api.php?action=ACTION_NAME
```

### Authentication — Token Formats (all accepted)

| Format | Example |
|--------|---------|
| Bearer header | `Authorization: Bearer TOKEN` ✅ recommended |
| Custom header | `X-API-Token: TOKEN` |
| Query param | `?token=TOKEN` |
| JSON body | `{"token": "TOKEN"}` |

---

## Files

| File | Description |
|------|-------------|
| `api.php` | **Main REST router** — all actions |
| `conn.php` | DB connection (mysqli) |
| `fcm_helper.php` | Firebase JWT + HTTP v1 push sender |
| `saveDeviceToken.php` | Register/update FCM device token |
| `broadcastNotification.php` | Admin: push to all users |
| `sendPushToUser.php` | Admin: push to specific user by email |
| `webhook.php` | **PaymentPoint webhook** — auto-credits wallet + FCM push on payment |
| `generateBankAccount.php` | Legacy standalone account creator |
| `setup_tables.sql` | One-time DB migration |
| `login.php` | Legacy standalone login |
| `register.php` | Legacy standalone register |
| `*.php` | Other legacy standalone endpoints |

> **New development should use `api.php` exclusively.**

---

## Performance

All endpoints are optimised for **< 0.5 second** response times:

- 7 database indexes added (`token`, `email`, `wallet.user_id`, `device_tokens.email`, etc.)
- Auth + wallet balance fetched in a **single JOIN query** (was 2 separate queries)
- Use `action=init` on app startup — returns everything in one call (~390ms)

---

## Database Setup (one-time)

Run `setup_tables.sql` on `eduowrav_rahausub`:

| Table | Purpose |
|-------|---------|
| `device_tokens` | FCM push tokens per device |
| `notifications_tbl` | In-app notifications |
| `admin_notifications_tbl` | Admin notification management |
| `admin_notif_delivery_tbl` | Per-user delivery tracking |
| `admin_notif_api_settings` | Email/SMS channel credentials |
| `referal_tbl` | Referral relationships |
| `referal_earn_transaction_tbl` | Referral earnings |

Also adds to `users_tbl`: `nin`, `finger`, `referal_token`, `token`, `date_join`

---

## API Reference

### Authentication Flow

```
1. POST ?action=register   →  create account
2. POST ?action=login      →  get token  ← save this
3. All other endpoints     →  Authorization: Bearer TOKEN
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
    "time": "2026-06-13 08:00:00"
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
  "data": { "message": "Registration successful. Please submit your BVN/NIN via the KYC section to activate your virtual account." }
}
```
> `pin` defaults to `0000` if not provided.

---

### `login` — Login
```
POST /api.php?action=login
Content-Type: application/json
```
**Request body:**
```json
{ "email": "user@example.com", "password": "Secret123" }
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
    "admin_role": "1,2,3",
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
> **Save `token`** — required for all authenticated requests.

---

### `init` ⭐ — App Startup (use this instead of profile on open)
```
GET /api.php?action=init
Authorization: Bearer TOKEN
```
Returns **everything the home screen needs in one call** (~390ms):
```json
{
  "status": "success",
  "data": {
    "id": 42,
    "email": "user@example.com",
    "sname": "Mahmud",
    "oname": "Muhammad",
    "phone": "08160327173",
    "state": "Lagos",
    "admin_role": "1,2,3",
    "super_admin": 1,
    "wallet_balance": 90.00,
    "has_account": true,
    "acc_no": "6683940358",
    "bank_name": "Palmpay",
    "acc_name": "Rahausub-Mah(Paymentpoint)",
    "accounts": [
      { "provider": "PaymentPoint", "bank_name": "Palmpay", "account_number": "6683940358", "account_name": "Rahausub-Mah(Paymentpoint)" },
      { "provider": "PaymentPoint", "bank_name": "Opay",    "account_number": "9876543210", "account_name": "Rahausub-Muh(Paymentpoint)" }
    ],
    "unread_count": 3,
    "referral_code": "3a5a612c02b88c84dfad5d52847767ca",
    "referral_link": "https://rahausub.com.ng/easyfinder/dashboard/register?join_with_referal=3a5a612c02b88c84dfad5d52847767ca",
    "bvn": "****12345",
    "has_bvn": true,
    "has_nin": false,
    "kyc_complete": true,
    "finger": true,
    "haspin": true
  }
}
```
> **Use `init` on app open/resume.** It replaces separate calls to `profile`, `wallet`, and `get_unread_count`.

---

### `profile` — Get User Profile
```
GET /api.php?action=profile
Authorization: Bearer TOKEN
```
Same response as `init`. Both actions are identical — `init` is the recommended alias.

---

### `verify_token` — Validate Stored Token
```
GET /api.php?action=verify_token
Authorization: Bearer TOKEN
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
    "acc_name": "Usman Musa"
  }
}
```

---

### `dashboard_stats` — Dashboard Summary
```
GET /api.php?action=dashboard_stats
Authorization: Bearer TOKEN
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
GET /api.php?action=wallet
Authorization: Bearer TOKEN
```
```json
{ "status": "success", "data": { "balance": 5000.00, "email": "user@example.com" } }
```

---

### `wallet_history` — Wallet Transaction History
```
GET /api.php?action=wallet_history
Authorization: Bearer TOKEN
```
```json
{ "status": "success", "data": { "transactions": [ { ...wallet record... } ] } }
```

---

### `transactions` — Service Transaction History
```
GET /api.php?action=transactions
Authorization: Bearer TOKEN
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
        "date": "2026-06-13 08:00:00",
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
POST /api.php?action=submit_kyc
Authorization: Bearer TOKEN
Content-Type: application/json
```
**Request body:**
```json
{ "bvn": "12345678901", "nin": "98765432101" }
```
> At least one of `bvn` or `nin` required. Both exactly 11 digits.

**Response (account generated immediately):**
```json
{
  "status": "success",
  "data": {
    "message": "KYC submitted successfully",
    "account_ready": true,
    "acc_no": "8123456789",
    "bank_name": "Palmpay",
    "acc_name": "Usman Musa",
    "accounts": [ ... ]
  }
}
```
**Response (still processing):**
```json
{
  "status": "success",
  "data": {
    "message": "KYC submitted successfully",
    "account_ready": false,
    "setup_message": "Generating your virtual account, please check back shortly."
  }
}
```
> If `account_ready = false`, poll `get_kyc_status` every 30 seconds.

---

### `get_kyc_status` — Check KYC & Account Status
```
GET /api.php?action=get_kyc_status
Authorization: Bearer TOKEN
```
**Response:**
```json
{
  "status": "success",
  "data": {
    "kyc_submitted": true,
    "kyc_complete": true,
    "has_bvn": true,
    "has_nin": false,
    "has_account": true,
    "acc_no": "8123456789",
    "bank_name": "Palmpay",
    "acc_name": "Usman Musa",
    "accounts": [ ... ]
  }
}
```

---

### `generate_account` — Generate Virtual Account (after KYC)
```
POST /api.php?action=generate_account
Authorization: Bearer TOKEN
```
```json
{ "status": "success", "data": { "message": "Account generated", "acc_no": "8123456789", "bank_name": "Palmpay", "acc_name": "..." } }
```

---

## Notifications

### `notifications` — Get User Notifications
```
GET /api.php?action=notifications
Authorization: Bearer TOKEN
```
**Query params (optional):**
- `?page=1` — page number (default 1)
- `?limit=20` — per page (default 20)

**Response:**
```json
{
  "status": "success",
  "data": {
    "notifications": [
      {
        "id": 5,
        "title": "Wallet Credited ✅",
        "message": "₦5,000 has been credited to your wallet by John Doe.",
        "type": "success",
        "is_read": false,
        "created_at": "2026-06-13 09:00:00"
      }
    ],
    "unread_count": 3
  }
}
```

---

### `get_unread_count` — Unread Notification Badge
```
GET /api.php?action=get_unread_count
Authorization: Bearer TOKEN
```
```json
{ "status": "success", "data": { "unread_count": 3 } }
```
> Also returned in `init` as `unread_count` — no separate call needed on startup.

---

### `mark_notification_read` — Mark One Read
```
POST /api.php?action=mark_notification_read
Authorization: Bearer TOKEN
Content-Type: application/json
```
```json
{ "notification_id": 5 }
```
**Response:**
```json
{ "status": "success", "data": { "message": "Notification marked as read" } }
```

---

### `mark_all_notifications_read` — Mark All Read
```
POST /api.php?action=mark_all_notifications_read
Authorization: Bearer TOKEN
```
```json
{ "status": "success", "data": { "message": "All notifications marked as read" } }
```

---

## Referral

### `referral` / `get_referral_stats` — Referral Stats
```
GET /api.php?action=referral
Authorization: Bearer TOKEN
```
**Response:**
```json
{
  "status": "success",
  "data": {
    "referral_code": "3a5a612c02b88c84dfad5d52847767ca",
    "referral_link": "https://rahausub.com.ng/easyfinder/dashboard/register?join_with_referal=3a5a612c02b88c84dfad5d52847767ca",
    "total_referred": 5,
    "total_earnings": 750.00,
    "referred_users": [
      { "sname": "Ali", "oname": "Musa", "email": "ali@example.com", "date_join": "2026-05-01" }
    ],
    "share_message": "Join Rahausub and earn on every data, airtime purchase! Use my referral code: 3a5a612c02b88c84..."
  }
}
```

---

## FCM Push Notifications

### `saveDeviceToken.php` — Register Device Token
```
POST /saveDeviceToken.php
Content-Type: application/json
```
```json
{ "email": "user@example.com", "token": "USER_LOGIN_TOKEN", "fcm_token": "FCM_DEVICE_TOKEN", "platform": "android" }
```
> Call this after every login and whenever the FCM token refreshes.

---

### `sendPushToUser.php` — Push to Specific User (admin)
```
POST /sendPushToUser.php
Content-Type: application/json
```
```json
{ "admin_key": "RahSubAdmin2026!", "email": "user@example.com", "title": "Hello", "message": "Your wallet has been credited." }
```

---

### `broadcastNotification.php` — Push to All Users (admin)
```
POST /broadcastNotification.php
Content-Type: application/json
```
```json
{ "admin_key": "RahSubAdmin2026!", "title": "Maintenance", "message": "System will be down at midnight." }
```

---

## PaymentPoint Webhook

### `webhook.php` — Auto Credit on Payment

**Set this URL in PaymentPoint dashboard:**
```
https://api.rahausub.com.ng/webhook.php
```

When a payment hits a user's virtual account, the webhook automatically:
1. Verifies HMAC-SHA512 signature
2. Finds user by account number
3. Credits wallet balance
4. Creates in-app notification ("Wallet Credited ✅")
5. Sends FCM push to all user's devices

**Webhook payload (sent by PaymentPoint):**
```json
{
  "event": "payment.success",
  "accountNumber": "8123456789",
  "amount": 5000,
  "reference": "PP_ref_abc123",
  "senderName": "John Doe"
}
```

**Response:**
```json
{
  "status": "ok",
  "message": "Payment processed",
  "email": "user@example.com",
  "amount": 5000,
  "new_balance": 5090,
  "fcm_sent": 1
}
```

---

## Services

### `buy_airtime` — Buy Airtime
```
POST /api.php?action=buy_airtime
Authorization: Bearer TOKEN
Content-Type: application/json
```
```json
{ "network": "MTN", "phone": "08012345678", "amount": 500 }
```

---

### `buy_data` — Buy Data
```
POST /api.php?action=buy_data
Authorization: Bearer TOKEN
Content-Type: application/json
```
```json
{ "network": "MTN", "phone": "08012345678", "plan_id": 5, "amount": 300 }
```

---

### `data_plans` — Get Data Plans
```
GET /api.php?action=data_plans&network=MTN
```
```json
{ "status": "success", "data": { "plans": [ { "id": 5, "name": "1GB", "amount": 300, "validity": "30 days" } ] } }
```

---

### `buy_tv` — Cable TV Subscription
```
POST /api.php?action=buy_tv
Authorization: Bearer TOKEN
Content-Type: application/json
```
```json
{ "provider": "DSTV", "smart_card": "1234567890", "plan_id": 3, "amount": 8000 }
```

---

### `buy_electricity` — Electricity / DISCO
```
POST /api.php?action=buy_electricity
Authorization: Bearer TOKEN
Content-Type: application/json
```
```json
{ "disco": "EKEDC", "meter_no": "12345678901", "meter_type": "prepaid", "amount": 2000, "phone": "08012345678" }
```

---

## Security / Account

### `set_pin` — Set Transaction PIN
```
POST /api.php?action=set_pin
Authorization: Bearer TOKEN
Content-Type: application/json
```
```json
{ "pin": "1234" }
```

---

### `change_pin` — Change Transaction PIN
```
POST /api.php?action=change_pin
Authorization: Bearer TOKEN
Content-Type: application/json
```
```json
{ "old_pin": "1234", "new_pin": "5678" }
```

---

### `change_password` — Change Password
```
POST /api.php?action=change_password
Authorization: Bearer TOKEN
Content-Type: application/json
```
```json
{ "old_password": "OldPass123", "new_password": "NewPass456" }
```

---

### `check_fingerprint` — Check Fingerprint Status
```
GET /api.php?action=check_fingerprint&email=user@example.com
```
```json
{ "status": "success", "data": { "finger": true, "email": "user@example.com" } }
```

---

### `toggle_fingerprint` — Enable/Disable Fingerprint
```
POST /api.php?action=toggle_fingerprint
Authorization: Bearer TOKEN
```
```json
{ "status": "success", "data": { "finger": true, "message": "Fingerprint login enabled" } }
```

---

## Error Responses

All errors follow the same shape:
```json
{ "status": "error", "message": "Human-readable error description" }
```

| HTTP Code | Meaning |
|-----------|---------|
| 400 | Bad request / missing fields |
| 401 | Missing or invalid token |
| 404 | Resource not found |
| 500 | Server error (check logs) |

---

## Changelog

| Date | Change |
|------|--------|
| Jun 2026 | Add `Authorization: Bearer TOKEN` support — fixes React Native 401 |
| Jun 2026 | Add `init` endpoint — one call returns full startup data in ~390ms |
| Jun 2026 | Add 7 DB indexes — eliminates full table scans on every auth request |
| Jun 2026 | Add `webhook.php` — auto-credits wallet + FCM push on PaymentPoint payment |
| Jun 2026 | Fix `verify_token` — removed invalid `wallet_balance` column reference (was causing 500 on all auth'd endpoints) |
| Jun 2026 | Replace Monnify with PaymentPoint throughout `api.php` |
| Jun 2026 | Add KYC (BVN + NIN), referral system, FCM push, admin notification management |

---

## Data Bundle Types (plan_types)

### `data_types` — Get Data Bundle Types for a Network ⭐ NEW
```
GET /api.php?action=data_types&serviceID=mtn-data
```
No auth required.

**Response:**
```json
{
  "status": "success",
  "data": {
    "types": [
      { "id": 1,  "name": "MTN SME",               "code": "mtnsme"     },
      { "id": 2,  "name": "MTN Corporate Gifting",  "code": "mtncg"      },
      { "id": 7,  "name": "MTN Awoof",              "code": "mtnawoof"   },
      { "id": 9,  "name": "DATA SHARE",             "code": "mtnshare"   },
      { "id": 10, "name": "DATA COUPONS",           "code": "mtncoupons" },
      { "id": 11, "name": "MTN SME 2",              "code": "mtnsme2"    },
      { "id": 12, "name": "MTN SMS",                "code": "mtn-sms"    }
    ]
  }
}
```
> Use `id` from this response as `plan_id` when calling `other_data_plans`.

---

### `other_data_plans` — Get Plans for a Data Type ⭐ NEW
```
GET /api.php?action=other_data_plans&plan_id=1
```
No auth required. `plan_id` = `id` from `data_types` response.

**Response:**
```json
{
  "status": "success",
  "data": {
    "plans": [
      {
        "id": 149,
        "plan_id": "7",
        "api_id": 4,
        "name": "MTN SME 1GB (7 Days)",
        "validity": "7 Days",
        "amount": 430
      }
    ]
  }
}
```
> Use `id` (not `plan_id`) when calling `buy_other_data`.

---

### `buy_other_data` — Purchase Data Bundle (non-VTpass) ⭐ NEW
```
POST /api.php?action=buy_other_data
Authorization: Bearer TOKEN
Content-Type: application/json
```
**Request body:**
```json
{
  "number":  "08012345678",
  "plan_id": 149,
  "pin":     "1234"
}
```
> Set `pin` to `"fingerprint"` for biometric auth.

**Response:**
```json
{
  "status": "success",
  "data": {
    "success":  true,
    "message":  "Data purchase successful",
    "balance":  4570.00,
    "api_response": { ... }
  }
}
```

---

## Typical Data Bundle Flow

```
1. GET  ?action=data_types&serviceID=mtn-data      → pick a type (get id)
2. GET  ?action=other_data_plans&plan_id={id}      → pick a plan (get plan.id)
3. POST ?action=buy_other_data                     → purchase
   body: { number, plan_id, pin }
```

Also works with legacy standalone endpoints:
- `POST /getDataTypes.php`  — same data, requires token in body
- `POST /getOtherData.php`  — requires token + plan_id in body
- `POST /buyOtherData.php`  — requires token + number + plan_id + pin
