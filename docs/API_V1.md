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

## Administrative authorization

ทุก endpoint ในส่วนนี้ต้องมี Bearer Token ที่มี ability `admin`
และผู้ใช้ต้องเป็น Admin หรือ SuperAdmin ที่ยัง active

| ความสามารถ | Admin | SuperAdmin |
| --- | --- | --- |
| จัดการ personnel | CRUD | อ่าน |
| จัดการ organizations | CRUD | อ่าน |
| จัดการ applications และ API keys | CRUD | อ่าน application |
| จัดการ access grants | CRUD | CRUD |
| อ่าน audit logs | อ่าน | อ่าน |

ระบบมี Admin ได้หนึ่งบัญชี ส่วน SuperAdmin มีได้หลายบัญชี
และไม่มีสิทธิสร้าง แก้ไข หรือลบ configuration ของระบบ

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
  "system_role": "user"
}
```

Response ไม่คืน CID, CID hash, encrypted CID หรือ password

Admin สร้างหรือเลื่อนผู้ใช้เป็น `super_admin` ได้ แต่ไม่สามารถกำหนด
`system_role=admin` ผ่าน CRUD API การสร้าง Admin คนแรกใช้คำสั่ง
`php artisan sso:create-admin` เท่านั้น

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
- `403` ไม่มีสิทธิ์ตาม role matrix
- `404` ไม่พบ resource หรือ resource ไม่อยู่ใน application ที่ระบุ
- `409` ข้อมูลหรือ effective grant ซ้ำ
- `422` validation หรือ business rule ไม่ผ่าน
- `429` เกิน rate limit
