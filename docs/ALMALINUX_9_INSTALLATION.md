# ติดตั้ง Sobmoei SSO API บน AlmaLinux 9

คู่มือนี้กำหนด path ตัวอย่างเป็น `/var/www/sso-api` และฐานข้อมูล `sso`
ต้องแทนค่าโดเมน, certificate และ credential ให้ตรงกับ server จริง

Apache deployment ของระบบนี้ใช้ name-based VirtualHost ร่วมกับเว็บไซต์อื่นบน
HTTPS port `443` โดยแยกด้วย `ServerName` ของ SSO และไม่ประกาศ `Listen 443` ซ้ำ
WAF จึงส่ง traffic ผ่าน port มาตรฐานได้โดยไม่ต้องเปิด port เพิ่ม

## 1. เตรียมระบบ

อัปเดตระบบและตรวจ PHP stream ที่มีอยู่ก่อน:

```bash
sudo dnf upgrade -y
sudo dnf module list php
sudo dnf module install php:8.3/common -y
sudo dnf install -y php-cli php-fpm php-mysqlnd php-opcache php-intl \
  php-mbstring php-xml php-process php-zip php-curl curl unzip git \
  mariadb policycoreutils-python-utils
php -v
php -m
```

Laravel 13 ต้องใช้ PHP 8.3 ขึ้นไป ห้ามติดตั้งด้วย PHP 8.0-8.2
ติดตั้ง Composer 2 จาก
[คู่มือทางการของ Composer](https://getcomposer.org/download/)
และตรวจด้วย `composer --version` ก่อนดำเนินการต่อ

เปิดใช้งานการเทียบเวลา:

```bash
sudo systemctl enable --now chronyd
timedatectl status
```

## 2. ติดตั้ง source code

```bash
sudo mkdir -p /var/www/sso-api
sudo chown "$USER":"$USER" /var/www/sso-api
git clone https://github.com/golderboy/SSO-API.git /var/www/sso-api
cd /var/www/sso-api
composer install --no-dev --classmap-authoritative --no-interaction
cp .env.almalinux.example .env
chmod 600 .env
```

แก้ `.env` อย่างน้อย:

- `APP_URL`
- `DB_PASSWORD`
- ส่วน `THAID`: `THAID_CLIENT_ID`, `THAID_CLIENT_SECRET`, `THAID_REDIRECT_URI`
- ส่วน `MOPH_ID`: `HEALTH_ID_CLIENT_ID`, `HEALTH_ID_CLIENT_SECRET`,
  `HEALTH_ID_REDIRECT_URI`, `PROVIDER_ID_CLIENT_ID` และ `PROVIDER_ID_SECRET_KEY`

เปิด `THAID_ENABLED=true` และ `MOPH_ID_ENABLED=true`
เมื่อใส่ test credential ครบและพร้อมทดสอบ UAT เท่านั้น

`APP_URL` และ callback URI ทุกระบบต้องใช้ HTTPS URL มาตรฐานโดยไม่ระบุ port เช่น:

```dotenv
APP_URL=https://sso.example.go.th
THAID_REDIRECT_URI=https://sso.example.go.th/api/callback/thaid
HEALTH_ID_REDIRECT_URI=https://sso.example.go.th/api/callback/moph-id
```

ตัวอย่าง callback เป็นรูปแบบอธิบายเท่านั้น ต้องใช้ path จริงที่ระบบรองรับ
และลงทะเบียนแบบ exact match กับผู้ให้บริการก่อนเปิดใช้งาน

สร้าง secret ภายใน server ห้ามส่งค่าผ่านแชตหรือ commit:

```bash
php artisan key:generate
openssl rand -base64 48
openssl rand -base64 48
```

นำผลจาก `openssl` คนละค่าใส่ `CID_LOOKUP_KEY` และ `AUDIT_HASH_KEY`
แล้วจำกัดสิทธิ์ไฟล์ `.env` ให้เฉพาะผู้ดูแลและ PHP-FPM ที่จำเป็น

## 3. สร้างฐานข้อมูล

ไฟล์ SQL สำหรับฐานข้อมูลใหม่:

```bash
mariadb -u root -p < database/schema/sso_mariadb.sql
```

สร้าง application account ด้วยรหัสผ่านสุ่มที่ไม่ซ้ำกับระบบอื่น:

```sql
CREATE USER 'sso_app'@'127.0.0.1' IDENTIFIED BY 'CHANGE-TO-A-STRONG-RANDOM-PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON sso.* TO 'sso_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

ใส่รหัสผ่านเดียวกันใน `DB_PASSWORD` แล้วตรวจ:

```bash
php artisan migrate:status
```

SQL มี migration inventory ของ Phase 1 แล้ว จึงไม่ต้องรัน `migrate`
ซ้ำหลัง import ครั้งแรก การ migration รุ่นถัดไปควรรันด้วย deploy credential
แยกจาก runtime account

## 4. Permission และ SELinux

ตัวอย่างด้านล่างสมมติว่า PHP-FPM ใช้ user `apache`
ตรวจค่าจริงใน `/etc/php-fpm.d/www.conf` ก่อน:

```bash
sudo chown -R root:apache /var/www/sso-api
sudo find /var/www/sso-api -type f -exec chmod 640 {} \;
sudo find /var/www/sso-api -type d -exec chmod 750 {} \;
sudo chown -R apache:apache storage bootstrap/cache
sudo chmod -R 770 storage bootstrap/cache

sudo semanage fcontext -a -t httpd_sys_content_t "/var/www/sso-api(/.*)?"
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/sso-api/storage(/.*)?"
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/sso-api/bootstrap/cache(/.*)?"
sudo restorecon -Rv /var/www/sso-api

sudo setsebool -P httpd_can_network_connect on
sudo setsebool -P httpd_can_network_connect_db on
```

อย่าปิด SELinux เพื่อแก้ permission ให้ตรวจ `ausearch`/`sealert`
และปรับ label เฉพาะ path ที่จำเป็น

## 5. เลือก Web server

### Apache

```bash
sudo dnf install -y httpd mod_ssl
sudo apachectl -S > /root/httpd-vhosts-before-sso.txt
sudo grep -RIn "ServerName .*sso.example.go.th" /etc/httpd/conf /etc/httpd/conf.d
sudo cp deploy/almalinux9/httpd/sso-api.conf.example /etc/httpd/conf.d/sso-api.conf
sudoedit /etc/httpd/conf.d/sso-api.conf
sudo apachectl configtest
sudo systemctl enable --now php-fpm
sudo systemctl reload httpd
```

แทน `sso.example.go.th` ด้วยโดเมนจริงก่อนตรวจชื่อซ้ำ หากพบว่า VirtualHost เดิม
ประกาศ `ServerName` เดียวกันบน `*:443` ห้ามเพิ่มอีกตัว ให้หยุดและรวม configuration
เข้ากับ VirtualHost เดิมก่อน

template เพิ่มเฉพาะ `<VirtualHost *:443>` ที่ระบุ `ServerName` เฉพาะของ SSO
ไม่ประกาศ `Listen 443`, wildcard `ServerAlias` หรือแก้ VirtualHost ของเว็บไซต์อื่น
หลัง reload ให้ตรวจ `sudo apachectl -S` และยืนยันว่าแต่ละ hostname บน `*:443`
ชี้ไปยังไฟล์ configuration ที่ถูกต้อง

### Nginx

```bash
sudo dnf install -y nginx
sudo cp deploy/almalinux9/nginx/sso-api.conf.example /etc/nginx/conf.d/sso-api.conf
sudoedit /etc/nginx/conf.d/sso-api.conf
sudo nginx -t
sudo systemctl enable --now php-fpm nginx
```

เลือกใช้เพียงหนึ่งแบบ ตรวจ socket `/run/php-fpm/www.sock`
และสิทธิ์ `listen.acl_users` ใน `/etc/php-fpm.d/www.conf`
ให้ web server ที่เลือกเข้าถึงได้

ใช้ HTTPS service มาตรฐานของ firewall และไม่เปิด custom port:

```bash
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
sudo firewall-cmd --query-service=https
```

## 6. สร้าง Admin และ cache

```bash
cd /var/www/sso-api
php artisan sso:create-admin
php artisan optimize
php artisan sso:check-installation --providers
```

คำสั่งตรวจ installation จะไม่แสดงค่า secret และต้องจบด้วย exit code `0`

## 7. Smoke test

```bash
curl --fail --silent --show-error \
  --user-agent 'Sobmoei-SSO-HealthCheck/1.0' \
  https://YOUR-DOMAIN/up
curl --silent --show-error --output /dev/null \
  --write-out '%{http_code}\n' \
  --user-agent 'Sobmoei-SSO-HealthCheck/1.0' \
  -H 'Accept: application/json' \
  https://YOUR-DOMAIN/api/v1/auth/me
```

ผลที่คาดหวังคือ `/up` สำเร็จ และ `/auth/me` ตอบ `401`
เมื่อยังไม่ได้ส่ง Admin Bearer Token

หลังจากนั้นทดสอบ login, Admin CRUD, ออก application API key และ
`POST /api/v1/access/check` ตาม [API v1](API_V1.md)

## 8. ข้อจำกัดก่อน UAT provider

template รองรับ credential ของ ThaID และสาย Health ID -> Provider ID แล้ว
แต่ยังไม่ควรถือว่า upstream login ใช้งานได้จนกว่าจะ:

1. ใส่ UAT credential ผ่าน `.env` หรือ secret manager
2. ยืนยัน callback URI แบบ exact match กับผู้ให้บริการ
3. ยืนยัน contract ปัจจุบันกับเจ้าของระบบ
4. ทดสอบการจับคู่ Provider ID `hash_cid` และกรณีหลายหน่วยงาน
