# iSend Libya — SMS API Guide

The complete guide to the iSend Libya REST API. Pair it with the Postman collection in this folder (`iSend.postman_collection.json`) and the environment file (`iSend.postman_environment.json`).

Send SMS to Libyan mobile numbers (Libyana & Almadar), track delivery, manage templates and sender names, and receive real-time delivery reports via webhooks. All requests use HTTPS; all payloads are JSON.

---

## 1. Base URL & authentication

**Base URL:** `https://isend.com.ly`  ·  all endpoints live under `/api/v1`.

Authenticate every request with a **Bearer API key**:

```
Authorization: Bearer prod_xxxxxxxxxxxxxxxx
```

Create keys in the customer portal under **Developer → API Keys**. Keep them secret — a key acts as the user who created it and can spend that account's quota. Rotate a key from the portal if it leaks.

> Surrounding whitespace in the token is tolerated, but avoid pasting a trailing space/newline.

---

## 2. Conventions

| Aspect | Value |
|---|---|
| Transport | HTTPS only (plain HTTP is rejected) |
| Content-Type | `application/json` for request bodies and responses |
| Auth | `Authorization: Bearer <api_key>` |
| Success | Standard HTTP codes — `200 OK`, `201 Created` |
| Errors | `4xx`/`5xx` with `{ "detail": "..." }` |
| Phone format | `2189XXXXXXXX` (country code 218; 91/93 = Almadar, 92/94 = Libyana) |
| Encoding | Auto-detected — GSM7 (160 chars/segment) or UCS2/Unicode (70/segment) |

**Error example** (`403`):
```json
{ "detail": "Sending messages is not allowed", "mode": "template_only", "reasons": ["..."] }
```

---

## 3. Send an SMS

`POST /api/v1/messages`

```json
{
  "to": "218911234567",
  "from": "MYSENDER",
  "body": "Your code is 123456"
}
```

| Field | Required | Notes |
|---|---|---|
| `to` | ✅ | Recipient MSISDN, `2189XXXXXXXX`. |
| `from` | ✅ | An **approved** sender name on your account (≤ 11 chars). |
| `body` | ✅ | 1–1530 chars. Arabic/Unicode supported (billed per segment). |
| `idempotency_key` | — | Prevents duplicate sends; returns the existing message if the key repeats (stored 24h). |

**`201 Created`:**
```json
{
  "id": "0c83c28f-b30c-49bc-90bc-410999619537",
  "company_id": "10a536e0-da6f-42e3-8c4d-e7206bf7f736",
  "user_id": "9c8f1894-9378-4f98-bf56-99df65e20351",
  "to": "218911234567",
  "from": "MYSENDER",
  "body": "Your code is 123456",
  "encoding": "GSM7",
  "segments": 1,
  "character_count": 19,
  "operator": "ALMADAR",
  "status": "SENT",
  "submitted_at": "2026-07-11T10:00:00Z",
  "delivered_at": null,
  "error_code": null,
  "error_message": null
}
```

Save `id` — you use it to check delivery status (§7) and it's the key that DLR webhooks reference.

---

## 4. Bulk send

`POST /api/v1/messages/bulk` — up to **10,000** free-text messages in one request.

```json
{
  "from": "MYSENDER",
  "messages": [
    { "to": "218911234567", "body": "Hello customer 1" },
    { "to": "218922345678", "body": "Hello customer 2", "from": "SHIPMENTS" }
  ],
  "idempotency_key": "batch-2026-07-11-001"
}
```

- `from` is the shared sender; override per message with `messages[].from`.
- `idempotency_key` de-duplicates the whole batch.

**`201`:**
```json
{
  "batch_id": "batch_2026_07_11_12345",
  "total": 2,
  "accepted": 2,
  "rejected": 0,
  "total_segments": 2,
  "results": [
    { "index": 0, "to": "218911234567", "success": true, "message_id": "550e8400-...", "segments": 1 },
    { "index": 1, "to": "218922345678", "success": true, "message_id": "660e8400-...", "segments": 1 }
  ]
}
```

---

## 5. Send from a template

Approved templates let you send pre-vetted content with parameters — required for accounts in template-only mode, and ideal for OTP.

**Single** — `POST /api/v1/messages/send-template`:
```json
{
  "template_id": "otp_basic",
  "to": "218911234567",
  "parameters": { "otp": "123456" },
  "sender_id": "MYSENDER"
}
```
`sender_id` is optional (the first approved sender is used if omitted).

**Bulk** — `POST /api/v1/messages/send-template/bulk` (up to 10,000, each with its own `parameters`):
```json
{
  "template_id": "otp_basic",
  "sender_id": "MYSENDER",
  "recipients": [
    { "to": "218911234567", "parameters": { "otp": "111111" } },
    { "to": "218922345678", "parameters": { "otp": "222222" } }
  ]
}
```

List the templates you can use: `GET /api/v1/sms-templates/available` → `{ "global_templates": [ { "id", "template_key", "body", "parameters", ... } ], ... }`.

---

## 6. Retrieve messages

- **One message:** `GET /api/v1/messages/{id}` → the message object (§3 shape).
- **List:** `GET /api/v1/messages?status_filter=DELIVRD&limit=50` — filter by status, newest first.
- **Stats:** `GET /api/v1/messages/stats` → aggregate counts by status/operator.

---

## 7. Delivery status

Poll `GET /api/v1/messages/{id}` (or subscribe to webhooks, §11). The `status` field:

| `status` | Meaning |
|---|---|
| `PENDING` | Accepted, not yet handed to the operator |
| `SENT` | Handed to the operator, awaiting a delivery receipt |
| `DELIVRD` | Confirmed delivered to the handset |
| `UNDELIV` | Operator could not deliver (off/unreachable) |
| `FAILED` | Failed in the network |
| `REJECTED` | Rejected before sending (e.g. blacklisted) |

---

## 8. Sender names

`GET /api/v1/sender-ids?status=APPROVED` → your sender names:
```json
{ "sender_ids": [ { "sender_id": "MYSENDER", "type": "ALPHANUMERIC", "status": "APPROVED", "purpose": "..." } ] }
```
Only names with `status: APPROVED` can send. Request new names in the portal.

---

## 9. Account quota & balance

- **Quota:** `GET /api/v1/account/quota` → `{ "plan_name", "total", "used", "available", ... }`.
- **Wallet:** `GET /api/v1/wallet/balance` → `{ "balance", "currency": "LYD", "recent_transactions": [...] }`.

---

## 10. Idempotency

Retrying a send after a network timeout can create a duplicate. Pass an `idempotency_key` on `POST /api/v1/messages` and `/messages/bulk`: the platform stores it for 24h and returns the original message (or batch) instead of creating a new one. Use a unique key per logical send (e.g. your order id).

---

## 11. Webhooks

Receive delivery reports and account events at your own HTTPS endpoint.

**Create** — `POST /api/v1/webhooks`:
```json
{
  "url": "https://api.example.com/webhooks/sms",
  "events": ["dlr", "message.sent", "message.delivered", "message.failed"],
  "description": "Production SMS events"
}
```

> The signing **`secret`** is returned **only** in the create response — store it. You need it to verify signatures, and it's never shown again.

**Manage:** `GET /api/v1/webhooks` (list), `PUT /api/v1/webhooks/{id}` (update), `DELETE /api/v1/webhooks/{id}` (remove).

**Event types:** `dlr`, `message.sent`, `message.delivered`, `message.failed`, `quota.low`, `quota.critical`, `quota.exhausted`.

**DLR payload** (sent to your URL):
```json
{
  "event": "dlr",
  "message_id": "0c83c28f-b30c-49bc-90bc-410999619537",
  "to": "218911234567",
  "status": "DELIVRD",
  "delivered_at": "2026-07-11T10:00:05Z"
}
```
Correlate by `message_id` (the `id` from your send response).

**Verify the signature.** Each POST carries `X-Signature` and `X-Timestamp` headers. Compute `HMAC-SHA256(timestamp + "." + raw_body, secret)` and compare (constant-time); reject if the timestamp is older than 5 minutes.

```python
import hmac, hashlib, time
def verify(raw_body: bytes, timestamp: str, signature: str, secret: str) -> bool:
    if abs(time.time() - int(timestamp)) > 300:
        return False
    expected = hmac.new(secret.encode(), (timestamp + ".").encode() + raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature)
```

---

## 12. Quick reference

| Action | Method & path |
|---|---|
| Send SMS | `POST /api/v1/messages` |
| Bulk send | `POST /api/v1/messages/bulk` |
| Send from template | `POST /api/v1/messages/send-template` |
| Bulk template | `POST /api/v1/messages/send-template/bulk` |
| Get message | `GET /api/v1/messages/{id}` |
| List messages | `GET /api/v1/messages?status_filter=&limit=` |
| Message stats | `GET /api/v1/messages/stats` |
| Available templates | `GET /api/v1/sms-templates/available` |
| Sender names | `GET /api/v1/sender-ids?status=APPROVED` |
| Account quota | `GET /api/v1/account/quota` |
| Wallet balance | `GET /api/v1/wallet/balance` |
| Create webhook | `POST /api/v1/webhooks` |
| List webhooks | `GET /api/v1/webhooks` |

Every endpoint authenticates with `Authorization: Bearer <api_key>`.
