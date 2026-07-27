# API v1

Base path: `/api/v1`

ทุก request และ response ใช้ JSON ยกเว้น health endpoint `/up`

## Admin authentication

### Login

`POST /auth/login`

```json
{
  "email": "admin@example.test",
  "password": "strong-password",
  "device_name": "admin-workstation"
}
```

ระบบคืน Bearer Token เพียงใน response นี้ และกำหนด `Cache-Control: no-store`

### Current administrator

`GET /auth/me`

Header:

```text
Authorization: Bearer ADMIN_TOKEN
```

### Logout

`POST /auth/logout`

ระบบ revoke token ที่ใช้เรียก request ปัจจุบัน

## Admin CRUD

ทุก endpoint ในส่วนนี้ต้องมี Bearer Token ที่มี ability `admin`
และผู้ใช้ต้องเป็น super administrator ที่ยัง active

| Resource | Endpoints |
| --- | --- |
| Personnel | `/admin/users` |
| Organizations | `/admin/organizations` |
| Applications | `/admin/applications` |
| Access grants | `/admin/access-grants` |
| Audit logs | `/admin/audit-logs` |

CRUD resource ใช้ UUID ใน URL ไม่ใช้เลขลำดับฐานข้อมูล

### Create personnel

`POST /admin/users`

```json
{
  "name": "ชื่อบุคลากร",
  "email": "personnel@example.test",
  "cid": "เลขประจำตัวประชาชนสำหรับส่งผ่าน TLS",
  "is_active": true,
  "is_super_admin": false
}
```

Response ไม่คืน CID, CID hash, encrypted CID หรือ password

### Create organization

`POST /admin/organizations`

```json
{
  "hcode": "12345",
  "name_th": "หน่วยงานทดสอบ",
  "name_en": "Test Organization",
  "is_active": true
}
```

### Create application

`POST /admin/applications`

```json
{
  "name": "Personnel Portal",
  "slug": "personnel-portal",
  "base_url": "https://service.example.test",
  "require_organization_match": true,
  "is_active": true
}
```

### Create access grant

`POST /admin/access-grants`

```json
{
  "user_id": "USER_UUID",
  "application_id": "APPLICATION_UUID",
  "organization_id": "ORGANIZATION_UUID",
  "role": "staff",
  "permissions": [
    "site.login",
    "records.read"
  ],
  "valid_from": null,
  "valid_until": null
}
```

เมื่อ revoke grant ระบบเก็บ record ไว้สำหรับ audit และตั้ง `revoked_at`
แทนการลบประวัติออกจากฐานข้อมูล

## Application API key

### Create or rotate

`POST /admin/applications/{application}/api-keys`

```json
{
  "name": "production-server",
  "key": "OPTIONAL_KEY_WITH_AT_LEAST_32_CHARACTERS",
  "expires_at": null,
  "revoke_existing": true
}
```

หากไม่ส่ง `key` ระบบจะสร้างค่าแบบ cryptographically random ให้
plain text key แสดงครั้งเดียวและไม่สามารถดึงย้อนหลังได้

### Revoke

`DELETE /admin/applications/{application}/api-keys/{apiKey}`

ระบบตรวจว่า key เป็นของ application ใน URL เพื่อป้องกัน BOLA

## Check access

`POST /access/check`

Header:

```text
X-API-Key: APPLICATION_API_KEY
```

Body:

```json
{
  "cid": "เลขประจำตัวประชาชนสำหรับส่งผ่าน TLS",
  "organization_hcode": "12345"
}
```

Allowed response:

```json
{
  "data": {
    "allowed": true,
    "subject_id": "USER_UUID",
    "application_id": "APPLICATION_UUID",
    "organization": {
      "id": "ORGANIZATION_UUID",
      "hcode": "12345"
    },
    "role": "staff",
    "permissions": [
      "site.login"
    ]
  }
}
```

Denied response ใช้เหตุผลกลาง `not_authorized`
เพื่อไม่เปิดเผยว่าพบ CID, organization หรือ grant ใดในฐานข้อมูล

## Status codes

- `200` สำเร็จ
- `201` สร้างข้อมูลสำเร็จ
- `204` revoke/delete สำเร็จ
- `401` credential หรือ API key ไม่ถูกต้อง
- `403` ไม่มีสิทธิ์ Admin
- `404` ไม่พบ resource หรือ resource ไม่อยู่ใน application ที่ระบุ
- `409` ข้อมูลหรือ effective grant ซ้ำ
- `422` validation หรือ business rule ไม่ผ่าน
- `429` เกิน rate limit
