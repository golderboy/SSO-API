# ติดตั้ง Sobmoei SSO API บน AlmaLinux 9

คู่มือนี้กำหนด path ตัวอย่างเป็น `/var/www/sso-api` และฐานข้อมูล `sso`
ต้องแทนค่าโดเมน, certificate และ credential ให้ตรงกับ server จริง

Apache deployment ของระบบนี้ใช้ path-based reverse proxy `/call/` ภายใน
VirtualHost `443` เดิมของ `sobmoeiservice.moph.go.th` และส่งต่อไปยัง SSO backend
ที่ฟังเฉพาะ `127.0.0.1:8089` จึงไม่เพิ่ม VirtualHost `443` และไม่เปิด backend
ออกสู่เครือข่ายภายนอก

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

`APP_URL`, session path และ callback URI ต้องรวม public prefix `/call` เช่น:

```dotenv
APP_URL=https://sobmoeiservice.moph.go.th/call
SESSION_PATH=/call
THAID_REDIRECT_URI=https://sobmoeiservice.moph.go.th/call/api/callback/thaid
HEALTH_ID_REDIRECT_URI=https://sobmoeiservice.moph.go.th/call/api/callback/moph-id
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

sudo semanage port -l | grep -w 8089
```

อย่าปิด SELinux เพื่อแก้ permission ให้ตรวจ `ausearch`/`sealert`
และปรับ label เฉพาะ path ที่จำเป็น

หากไม่พบ port `8089` ให้เพิ่มด้วย:

```bash
sudo semanage port -a -t http_port_t -p tcp 8089
```

หากมี port อยู่แล้วแต่ชนิดไม่ใช่ `http_port_t` ให้แก้เฉพาะ port นี้ด้วย:

```bash
sudo semanage port -m -t http_port_t -p tcp 8089
```

## 5. เลือก Web server

### Apache

```bash
sudo dnf install -y httpd mod_ssl
sudo apachectl -S > /root/httpd-vhosts-before-sso.txt
sudo httpd -M | grep -E 'headers|proxy|proxy_http|rewrite'
sudo cp -a /etc/httpd/conf.d/ssl.conf /root/ssl.conf.before-sso-call
sudo cp deploy/almalinux9/httpd/sso-api.conf.example /etc/httpd/conf.d/sso-api.conf
sudo cp deploy/almalinux9/httpd/sso-api-proxy.inc.example \
  /etc/httpd/conf.d/sso-api-proxy.inc
sudoedit /etc/httpd/conf.d/ssl.conf
sudo apachectl configtest
sudo systemctl enable --now php-fpm
sudo systemctl reload httpd
```

เพิ่มบรรทัดต่อไปนี้ภายใน `<VirtualHost _default_:443>` ตัวแรกของ `ssl.conf`
ก่อน `</VirtualHost>` เท่านั้น:

```apache
IncludeOptional conf.d/sso-api-proxy.inc
```

ห้ามเพิ่ม VirtualHost `443` ใหม่ และห้ามใส่ include ใน VirtualHost ตัวที่สอง
ไฟล์ include map เฉพาะ `/call/` ไป `http://127.0.0.1:8089/`
เส้นทางอื่นจึงยังใช้ configuration เดิม

หลัง reload ให้เปรียบเทียบ VirtualHost และ listener:

```bash
sudo apachectl -S > /root/httpd-vhosts-after-sso.txt
if sudo test -f /root/httpd-vhosts-before-sso.txt; then
  sudo diff -u /root/httpd-vhosts-before-sso.txt \
    /root/httpd-vhosts-after-sso.txt
else
  echo "No pre-change snapshot; review current apachectl -S output manually."
  sudo cat /root/httpd-vhosts-after-sso.txt
fi
sudo ss -lntp | grep 8089
```

ต้องเห็น `127.0.0.1:8089` ไม่ใช่ `*:8089` และรายการ VirtualHost `443`
ต้องไม่เพิ่มขึ้น ไม่ต้องเปิด port `8089` ใน firewalld หรือ WAF

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
  -H 'Host: sobmoeiservice.moph.go.th' \
  http://127.0.0.1:8089/up
curl --fail --silent --show-error \
  --resolve sobmoeiservice.moph.go.th:443:127.0.0.1 \
  --user-agent 'Sobmoei-SSO-HealthCheck/1.0' \
  https://sobmoeiservice.moph.go.th/call/up
curl --silent --show-error --output /dev/null \
  --write-out '%{http_code}\n' \
  --user-agent 'Sobmoei-SSO-HealthCheck/1.0' \
  -H 'Accept: application/json' \
  https://sobmoeiservice.moph.go.th/call/api/v1/auth/me
```

ผลที่คาดหวังคือ backend `/up` และ public `/call/up` สำเร็จ ส่วน
`/call/api/v1/auth/me` ตอบ `401`
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
