# การติดตั้งบนเซิร์ฟเวอร์

สำหรับ AlmaLinux 9 ให้ใช้
[คู่มือติดตั้งเฉพาะระบบปฏิบัติการ](ALMALINUX_9_INSTALLATION.md)
และ `.env.almalinux.example`

## Requirements

- Linux หรือ Windows Server ที่รองรับ PHP 8.3
- Apache หรือ Nginx
- Composer 2.8 ขึ้นไป
- MariaDB/MySQL
- HTTPS certificate
- Secret manager หรือระบบส่ง environment variables ที่ได้รับอนุมัติ

Web server document root ต้องชี้ไปที่โฟลเดอร์ `public` เท่านั้น
ห้ามชี้ไปที่ repository root

## ติดตั้ง

```bash
git clone https://github.com/golderboy/SSO-API.git
cd SSO-API
composer install --no-dev --classmap-authoritative
cp .env.example .env
php artisan key:generate
```

กำหนดค่าต่อไปนี้ผ่าน secret manager หรือ `.env` ที่ permission จำกัด:

- `APP_KEY`
- `DB_PASSWORD`
- `CID_LOOKUP_KEY`
- `AUDIT_HASH_KEY`
- provider credential เมื่อเริ่มเฟส integration

จากนั้น:

```bash
php artisan migrate --force
php artisan sso:create-admin
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Production settings

```dotenv
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning
SESSION_ENCRYPT=true
```

ตั้งค่า reverse proxy/web server ให้:

- รับเฉพาะ HTTPS และเปิด HSTS หลังยืนยันว่าโดเมนย่อยทั้งหมดรองรับ HTTPS
- จำกัด request body สำหรับ API ตาม payload ที่ใช้งานจริง
- ไม่บันทึก `Authorization`, `X-API-Key`, CID หรือ request body ลง access log
- ส่ง client IP ผ่าน trusted proxy ที่กำหนดรายการไว้ชัดเจนเท่านั้น

สร้าง `CID_LOOKUP_KEY` และ `AUDIT_HASH_KEY` คนละค่ากัน
แต่ละค่าต้องมี entropy อย่างน้อย 256 บิต และต้องมีแผน backup/rotation
ห้ามเปลี่ยน `CID_LOOKUP_KEY` โดยไม่มี data migration เพราะ lookup เดิมจะใช้ไม่ได้
ห้ามเปลี่ยนหรือทำ `APP_KEY` สูญหาย เพราะข้อมูล CID ที่เข้ารหัสไว้จะถอดรหัสไม่ได้

## File permission

Web server ต้องเขียนได้เฉพาะ:

- `storage`
- `bootstrap/cache`

source code, `.env` และ private key ต้องไม่เปิดให้ดาวน์โหลดผ่าน web server

## Deployment checklist

1. รัน `composer audit --locked`
2. รัน test และ Pint กับ commit เดียวกับที่จะ deploy
3. backup ฐานข้อมูล
4. เปิด maintenance mode หาก migration ไม่ backward compatible
5. รัน migration
6. cache config และ routes
7. ตรวจ `/up`
8. smoke test login, CRUD และ access-check
9. ตรวจ log ว่าไม่มี CID, token หรือ API key ดิบ
10. ปิด maintenance mode

## Rollback

- เก็บ application artifact ของ release ก่อนหน้า
- ทดสอบ migration rollback ใน staging ก่อน production
- ห้าม rollback database โดยอัตโนมัติเมื่อมีข้อมูลใหม่ที่อาจสูญหาย
- หาก key รั่ว ให้ revoke/rotate ก่อน rollback application
