# ข้อกำหนดด้านความปลอดภัย

## OAuth/OIDC controls

- บังคับ `state` ทุก authorization request
- ใช้ PKCE S256 สำหรับ client ที่รองรับ และเป็นข้อบังคับสำหรับ public client
- ใช้ nonce เมื่อมี ID Token
- callback URI ต้อง exact match
- authorization code ใช้ครั้งเดียว อายุสั้น และผูก client, redirect URI และ PKCE
- JWT validation ต้อง allowlist algorithm และตรวจ signature, issuer, audience, expiry, issued-at และ subject
- รองรับ key rotation โดย cache key ตาม TTL และ refresh เมื่อพบ key ID ใหม่อย่างมีขอบเขต

## Application security

- cookie ใช้ Secure, HttpOnly และ SameSite ที่สอดคล้องกับ redirect flow
- regenerate session หลัง login
- CSRF protection สำหรับ admin และ state-changing endpoint
- rate limit ตาม IP, client และ transaction
- generic error สำหรับผู้ใช้ พร้อม correlation ID
- admin plane ใช้ MFA และ least privilege

## Data protection

- data minimization เป็นค่าเริ่มต้น
- CID lookup ใช้ keyed HMAC และแยก key จากฐานข้อมูล
- raw CID เก็บได้เมื่อมีเหตุผลและการอนุมัติเท่านั้น พร้อม encryption at rest
- ไม่บันทึก token, authorization code, client secret, private key หรือ raw profile ใน log
- downstream claim ส่งตาม allowlist ต่อ application

## Operational security

- secret มาจาก secret manager หรือ runtime mount
- TLS verification เปิดตลอด และกำหนด trusted proxy อย่างชัดเจน
- production key rotation และ revoke runbook
- backup ต้องเข้ารหัสและทดสอบ restore
- dependency และ container image ต้องสแกนก่อน release
- audit event ต้องส่งออกไปยัง storage ที่แก้ไขย้อนหลังได้ยาก

## Threat cases ที่ต้องทดสอบ

- login CSRF และ mix-up attack
- open redirect
- code interception และ replay
- nonce/state mismatch
- algorithm confusion และ unsigned token
- issuer/audience mismatch
- SSRF ผ่าน provider configuration
- privilege escalation ผ่าน organization/role
- session fixation และ stolen session
- secret/token leakage ใน log และ error
- provider/policy database outage
