# Sobmoei SSO Test Client

เว็บไซต์ PHP แยกสำหรับทดสอบ Authorization Code + PKCE กับ Sobmoei SSO เท่านั้น
ไม่มีการเชื่อมฐานข้อมูลหรือ Admin API โดยตรง

## Public files

- `index.php` ตรวจ session และเริ่ม SSO เมื่อยังไม่ได้เข้าสู่ระบบ
- `callback.php` ตรวจ state, แลก authorization code และเรียก userinfo
- `logout.php` revoke access token และล้าง local session
- `assets/app.css` รูปแบบหน้าจอ

ไฟล์ `bootstrap.php`, `config.example.php` และไดเรกทอรี `lib` ต้องไม่เปิดผ่าน HTTP

## AlmaLinux 9 installation

คัดลอกโฟลเดอร์นี้ไปที่ `/var/www/html/testsso` และสร้างไฟล์ config นอก web root:

```bash
sudo install -d -o root -g apache -m 0750 /etc/sobmoei
sudo install -o root -g apache -m 0640 /var/www/html/testsso/config.example.php /etc/sobmoei/testsso.php
sudo vi /etc/sobmoei/testsso.php
```

ใส่เฉพาะ downstream client ID/secret ที่ Admin สร้างให้ application `testsso`
ห้ามนำ ThaID, Health ID หรือ Provider ID secret มาใส่ในเว็บไซต์ทดสอบ

ตั้ง SELinux context ให้ PHP อ่าน config ได้:

```bash
sudo semanage fcontext -a -t httpd_sys_content_t '/etc/sobmoei(/.*)?'
sudo restorecon -Rv /etc/sobmoei
```

ลงทะเบียน callback ของ application แบบ exact match:

```text
https://sobmoeiservice.moph.go.th/testsso/callback.php
```

ตรวจ syntax และ Apache หลังอัปโหลด:

```bash
sudo -u apache php -l /var/www/html/testsso/index.php
sudo -u apache php -l /var/www/html/testsso/callback.php
sudo -u apache php -l /var/www/html/testsso/logout.php
sudo httpd -t
sudo httpd -S
```

## Security behavior

- บังคับ HTTPS สำหรับ SSO endpoint และ callback
- ใช้ OAuth state, nonce และ PKCE S256
- transaction อายุไม่เกิน 5 นาที
- local session อายุไม่เกิน 30 นาที
- cookie ใช้ Secure, HttpOnly และ SameSite=Lax
- ไม่เก็บ token ใน JavaScript, localStorage หรือ URL
- ไม่แสดง subject, CID, token, secret หรือ raw provider response
- callback error แสดงเฉพาะ correlation ID
- logout รับเฉพาะ POST และตรวจ CSRF token

ระบบ SSO ต้องมี `/authorize`, `/token`, `/userinfo` และ `/revoke`
ก่อนเว็บไซต์ทดสอบจะทำงานแบบ end-to-end ได้
