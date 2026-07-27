# Sobmoei SSO API

API สำหรับจัดการบุคลากร แอปพลิเคชัน หน่วยงาน และสิทธิ์เข้าใช้งานเว็บไซต์
พัฒนาด้วย PHP 8.3, Laravel 13 และ Laravel Sanctum

## สถานะ

### Phase 1 พร้อมทดสอบ API และ Admin CRUD

ส่วนเชื่อมต่อ ThaID, Health ID, Provider ID และ downstream OIDC flow
ยังอยู่ในแผนสำหรับเฟสถัดไป

## ความสามารถในเฟสนี้

- Admin login/logout และ Bearer Token ด้วย Laravel Sanctum
- CRUD บุคลากร หน่วยงาน แอปพลิเคชัน และ access grant
- Application API key ที่สร้างโดยระบบหรือกำหนดเองได้
- เก็บ API key เฉพาะ SHA-256 hash และแสดง plain text เพียงครั้งเดียว
- เก็บ CID แบบ encrypted พร้อม keyed HMAC สำหรับ lookup
- ตรวจสิทธิ์จาก CID + application + organization + role + validity period
- UUID สำหรับ public resource identifiers
- Audit log ที่ไม่บันทึก CID, token หรือ API key ดิบ
- Rate limiting, generic authentication errors และ security headers
- Automated tests สำหรับ authentication, CRUD, BOLA, key isolation และ revocation

## API หลัก

- `POST /api/v1/auth/login`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/logout`
- `POST /api/v1/access/check`
- `/api/v1/admin/users`
- `/api/v1/admin/organizations`
- `/api/v1/admin/applications`
- `/api/v1/admin/access-grants`
- `/api/v1/admin/audit-logs`

รายละเอียด request และ response อยู่ใน [API v1](docs/API_V1.md)

## Requirements

- PHP 8.3 ขึ้นไป
- Composer 2.8 ขึ้นไป
- MariaDB/MySQL สำหรับ production
- PHP extensions: ctype, curl, dom, fileinfo, filter, hash, intl, json,
  mbstring, openssl, PDO, pdo_mysql, session, tokenizer, xml, xmlwriter และ zip

## เริ่มใช้งานสำหรับพัฒนา

```bash
composer install
copy .env.example .env
php artisan key:generate
```

กำหนด `CID_LOOKUP_KEY`, `AUDIT_HASH_KEY` และ database credential ใน `.env`
จากนั้นรัน:

```bash
php artisan migrate
php artisan sso:create-admin
php artisan serve
```

ห้าม commit `.env`, client secret, API key, private key, access token หรือ CID จริง

## ทดสอบ

```bash
composer test
php vendor/bin/pint --test
composer audit --locked
```

## เอกสาร

- [API v1](docs/API_V1.md)
- [สถานะการพัฒนา](docs/PHASE_1_IMPLEMENTATION.md)
- [ติดตั้งบนเซิร์ฟเวอร์](docs/SERVER_INSTALLATION.md)
- [สถาปัตยกรรม](docs/SSO_ARCHITECTURE.md)
- [แบบจำลองข้อมูล](docs/SSO_DATA_MODEL.md)
- [Security](docs/SSO_SECURITY.md)
- [Test strategy](docs/SSO_TEST_STRATEGY.md)
- [Decision gates](docs/SSO_DECISIONS.md)

## เอกสารต้นฉบับ

PDF ของผู้ให้บริการยืนยันตัวตนเป็นเอกสารภายในและถูก ignore จาก Git
Repository นี้เป็น public จึงห้ามเพิ่มเอกสารดังกล่าวกลับเข้ามา
