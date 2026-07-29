# Provider ID UAT deployment and verification

เอกสารนี้ใช้กับ AlmaLinux 9 และ public SSO prefix `/call` เท่านั้น

## สิ่งที่ระบบรองรับ

- Health ID Authorization Code callback:
  `/sso/callback/provider-id`
- แลก Health ID code ด้วย form-urlencoded ตาม `API_PROVIDERID.pdf`
- ใช้ Health ID access token เป็น opaque credential สำหรับแลก Provider ID token
  โดยไม่เชื่อถือหรืออ่าน claim ภายใน
- ตรวจ Provider ID access token ด้วย RSA public key และ `RS256`
- เรียก Provider ID profile ด้วย Bearer token พร้อม `client-id` และ
  `secret-key`
- บังคับให้ `account_id` จาก Health ID, Provider ID token response และ
  Provider ID profile ตรงกัน
- จับคู่ `hash_cid` กับผู้ใช้เดิมเท่านั้น ไม่สร้างผู้ใช้อัตโนมัติ
- อนุญาตเฉพาะ access grant ที่ organization `hcode` ตรงกับ Provider ID
  profile
- ตรวจ state, browser session, transaction expiry และ callback replay
  แบบ fail-closed

## 1. ติดตั้งโค้ด

บน AlmaLinux 9:

```bash
cd /var/www/sso-api
git pull --ff-only origin main
composer install --no-dev --classmap-authoritative --no-interaction
composer check-platform-reqs --no-dev
sudo -u apache php artisan optimize:clear
```

ห้ามใช้ `apachectl` บนระบบนี้ การตรวจ Apache ต้องใช้:

```bash
sudo httpd -t
sudo httpd -S
```

## 2. ตั้งค่า Provider UAT

กำหนดค่าจริงใน `/var/www/sso-api/.env` โดยห้าม commit หรือแสดง secret:

```dotenv
APP_URL=https://sobmoeiservice.moph.go.th/call

MOPH_ID_ENABLED=false
MOPH_ID_CLOCK_SKEW_SECONDS=60
HEALTH_ID_CLIENT_ID=
HEALTH_ID_CLIENT_SECRET=
HEALTH_ID_REDIRECT_URI=https://sobmoeiservice.moph.go.th/call/sso/callback/provider-id
HEALTH_ID_BASE_URL=https://uat-moph.id.th
HEALTH_ID_AUTHORIZATION_PATH=/oauth/redirect
HEALTH_ID_TOKEN_PATH=/api/v1/token

PROVIDER_ID_CLIENT_ID=
PROVIDER_ID_SECRET_KEY=
PROVIDER_ID_BASE_URL=https://uat-provider.id.th
PROVIDER_ID_TOKEN_PATH=/api/v1/services/token
PROVIDER_ID_PROFILE_PATH=/api/v1/services/profile
PROVIDER_ID_PUBLIC_KEY_PATH=/api/v1/services/public-key
PROVIDER_ID_PUBLIC_KEY_CACHE_SECONDS=300
```

ลงทะเบียน Redirect URI ที่ Health ID UAT แบบ exact match:

```text
https://sobmoeiservice.moph.go.th/call/sso/callback/provider-id
```

หลังใส่ credential แล้วให้ตรวจโดยยังคง `MOPH_ID_ENABLED=false`:

```bash
cd /var/www/sso-api
sudo -u apache php artisan optimize:clear
sudo -u apache php artisan sso:check-installation --providers
sudo -u apache php artisan route:list --path=provider-id
```

ผล `route:list` ต้องพบ:

```text
GET|HEAD  sso/callback/provider-id  sso.callback.provider-id
```

เมื่อ installation check ผ่านแล้วจึงเปลี่ยน:

```dotenv
MOPH_ID_ENABLED=true
```

และโหลด configuration ใหม่:

```bash
cd /var/www/sso-api
sudo -u apache php artisan optimize:clear
sudo -u apache php artisan optimize
```

## 3. ติดตั้ง testsso และออก OAuth client

ตรวจว่าไฟล์ทดสอบอยู่ครบ:

```bash
sudo -u apache php -l /var/www/html/testsso/index.php
sudo -u apache php -l /var/www/html/testsso/callback.php
sudo -u apache php -l /var/www/html/testsso/logout.php
```

จากนั้นใช้สคริปต์ setup ซึ่งเรียก Admin API, สร้าง application `testsso`,
ออก confidential OAuth client และเขียน
`/etc/sobmoei/testsso.php` ด้วย permission `root:apache 0640`:

```bash
cd /var/www/sso-api
sudo bash scripts/prepare-almalinux-runtime.sh
sudo -u apache php artisan optimize:clear
sudo bash scripts/prepare-almalinux-passport-keys.sh
sudo bash scripts/setup-testsso-client.sh
```

สคริปต์จะถาม Admin email/password แบบ interactive และไม่แสดง client secret
ออกหน้าจอหรือบันทึกลง shell history

ถ้า application และ OAuth client เคยถูกสร้างแล้ว แต่ไม่มี client secret เดิม
ให้หมุน secret อย่างชัดเจน:

```bash
cd /var/www/sso-api
sudo bash scripts/prepare-almalinux-runtime.sh
sudo -u apache php artisan optimize:clear
sudo bash scripts/prepare-almalinux-passport-keys.sh
sudo bash scripts/setup-testsso-client.sh --rotate-existing
```

คำสั่งนี้ทำให้ testsso client secret เดิมใช้ไม่ได้ทันที หาก callback หรือ
provider policy เดิมไม่ตรง สคริปต์จะสร้าง testsso OAuth client ใหม่
โดยอัตโนมัติ การเปลี่ยนแปลงจำกัดเฉพาะ application `testsso`
และไม่กระทบ OAuth client ของเว็บไซต์อื่น

## 4. ตรวจจาก Server

การเปิด callback โดยไม่มี browser transaction, `code` และ `state`
ต้องถูกปฏิเสธ แต่ต้องไม่เป็น `404`:

```bash
curl -A 'Sobmoei-SSO-HealthCheck/1.0' \
  -H 'Host: sobmoeiservice.moph.go.th' \
  -sS -o /dev/null \
  -w 'Backend callback: %{http_code}\n' \
  'http://127.0.0.1:8089/sso/callback/provider-id'
```

ตรวจ public proxy:

```bash
curl --resolve sobmoeiservice.moph.go.th:443:127.0.0.1 \
  -A 'Sobmoei-SSO-HealthCheck/1.0' \
  -sS -o /dev/null \
  -w 'Public callback: %{http_code}, TLS=%{ssl_verify_result}\n' \
  'https://sobmoeiservice.moph.go.th/call/sso/callback/provider-id'
```

สถานะที่ยอมรับสำหรับ callback เปล่าคือ `400` หรือ `403` ตาม transaction
state แต่ห้ามเป็น `404`, `500` หรือ redirect ไป upstream provider

## 5. ตรวจจาก Windows PowerShell

ใช้ `curl.exe` เพื่อไม่ชน alias ของ Windows PowerShell:

```powershell
curl.exe --user-agent "Sobmoei-SSO-HealthCheck/1.0" --silent --show-error --output NUL --write-out "testsso: HTTP %{http_code}`n" "https://sobmoeiservice.moph.go.th/testsso/index.php"
```

เมื่อ `/etc/sobmoei/testsso.php` พร้อม ผลต้องเป็น `HTTP 200` และหน้าเว็บต้องมี
ปุ่ม “เข้าสู่ระบบด้วย Sobmoei SSO”

จากนั้นเปิดใน browser:

```text
https://sobmoeiservice.moph.go.th/testsso/index.php
```

กด Login แล้วเลือก ThaID หรือ Provider ID ที่หน้า Sobmoei SSO
ห้ามทดสอบด้วยการเปิด upstream callback โดยตรง เพราะ callback ต้องผูกกับ
transaction และ browser session ที่สร้างจาก `/authorize`

## 6. ข้อมูลที่ห้ามส่งหรือบันทึก

- Admin password และ Admin bearer token
- Health ID/Provider ID client secret
- Authorization code และ access token
- CID, `hash_cid` และ raw provider profile
- OAuth client secret ของ testsso

Audit log และ error page ต้องแสดงเฉพาะ reason กลางกับ correlation ID
เท่านั้น
