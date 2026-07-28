# แบบจำลองข้อมูล

Phase 1 ใช้ internal auto-increment ID สำหรับ foreign key และใช้ `public_id` แบบ UUID
สำหรับ URL/API เพื่อป้องกันการเดาเลขลำดับข้อมูล

## users

- `public_id` UUID unique
- `name`
- `email` nullable unique
- `password` nullable และ hash ด้วย password hasher ของ Laravel
- `cid_hash` keyed HMAC-SHA256 สำหรับ lookup
- `provider_cid_hash` keyed HMAC-SHA256 ของค่า SHA-256(CID) สำหรับจับคู่
  `hash_cid` จาก Provider ID โดยไม่เก็บ unsalted SHA-256 ตรง ๆ
- `cid_encrypted` encrypted ด้วย `APP_KEY`
- `is_active`
- `system_role`: `admin`, `super_admin` หรือ `user`
- `admin_slot`: ค่า `1` เฉพาะ Admin และ `NULL` สำหรับ role อื่น
- `last_login_at`
- timestamps และ soft delete

API Resource ไม่คืน password, CID, CID hash หรือ encrypted CID

`admin_slot` มี unique constraint และทำงานร่วมกับ check constraint เพื่อบังคับ
ให้มี Admin ได้ไม่เกินหนึ่งบัญชี โดย Admin ทำได้ทุกอย่าง ส่วน SuperAdmin
จัดการ access grant และอ่านข้อมูลอ้างอิง/audit เท่านั้น

## organizations

- `public_id` UUID unique
- `hcode` unique
- `name_th`, `name_en`
- `is_active`
- timestamps และ soft delete

## applications

- `public_id` UUID unique
- `name`
- `slug` unique
- `base_url`
- `require_organization_match`
- `is_active`
- timestamps และ soft delete

## application_api_keys

- `public_id` UUID unique
- `application_id`
- `name`
- `key_prefix` สำหรับค้นหา candidate โดยไม่เก็บ key ดิบ
- `key_hash` SHA-256 ของ high-entropy API key
- `last_used_at`
- `expires_at`
- `revoked_at`

Plain text API key แสดงเฉพาะตอนสร้างและไม่สามารถดึงย้อนหลัง

## access_grants

- `public_id` UUID unique
- `user_id`
- `application_id`
- `organization_id` nullable
- `role`
- `permissions` JSON allowlist
- `is_active`
- `valid_from`, `valid_until`
- `revoked_at`
- `created_by`, `revoked_by`
- timestamps

สิทธิ์มีผลเมื่อ user/application/organization active, grant active,
ยังไม่ถูก revoke และอยู่ในช่วงเวลาที่กำหนด

## personal_access_tokens

ตารางของ Laravel Sanctum สำหรับ Admin Bearer Token
กำหนด token expiration ผ่าน `SANCTUM_EXPIRATION_MINUTES`

## sso_subjects

- `user_id` unique และ foreign key ไป `users`
- เป็น subject ภายในสำหรับ downstream OAuth โดยเฉพาะ
- ไม่เก็บ CID ซ้ำและไม่ใช้รับ password

การแยกตารางนี้ทำให้ Admin Sanctum token และ downstream Passport token ใช้
authentication model/guard คนละชุด

## external_identities

- `public_id` UUID unique
- `user_id`
- `provider`: `thaid` หรือ `provider_id`
- `subject_hash`: keyed HMAC ของ provider + verified subject
- `identity_match_hash`: keyed HMAC ที่ต้องตรงกับ hash ของ user
- `linked_at`, `last_authenticated_at`
- timestamps

ไม่เก็บ ThaID `sub`, Provider ID `account_id`, PID หรือ `hash_cid` ตรง ๆ
ระบบสร้าง link ได้เฉพาะเมื่อพบ `users` เดิมที่ active และค่า CID/hash ตรงแน่นอน
ถ้าไม่พบ user จะปฏิเสธโดยไม่สร้างบัญชี และหาก subject เปลี่ยนแต่ CID เดิมถูก link
แล้วจะปฏิเสธเพื่อให้ตรวจสอบ/ยืนยันใหม่

## ตาราง downstream OAuth

- `oauth_clients`: client ID, client secret แบบ hash, exact redirect URI และ grant type
- `oauth_auth_codes`: one-time authorization code อายุ 5 นาที
- `oauth_access_tokens`: downstream access token อายุ 30 นาที
- `oauth_refresh_tokens`: รองรับ engine แต่ client รุ่น pilot จะไม่เปิด
  `refresh_token` grant

ไม่ได้สร้าง `oauth_device_codes` เพราะระบบไม่เปิด Device Code Grant

## authentication_transactions

- `public_id` UUID สำหรับ route โดยไม่เปิดเผยเลขลำดับ
- `application_sso_config_id`
- `browser_session_hash`: keyed HMAC ของค่าสุ่ม 64 ตัวอักษรที่เก็บใน server-side
  browser session
- `downstream_request`: encrypted payload ของ client, exact callback, scope,
  state, nonce และ PKCE challenge
- `upstream_state_hash`: keyed HMAC และ unique เมื่อ provider รองรับ state
- `selected_provider`
- `status`: `pending`, `provider_selected`, `organization_required`,
  `approved`, `issuing`, `consumed` หรือ `denied`
- `user_id`, `access_grant_id`, `organization_id` เมื่อ policy อนุมัติแล้ว
- `expires_at`, `authenticated_at`, `consumed_at`
- timestamps

ธุรกรรมมีอายุ 5 นาทีและออก authorization code ได้ครั้งเดียว สถานะ `issuing`
ถูก claim แบบ atomic ก่อนเรียก Passport เพื่อป้องกัน concurrent replay
ข้อมูล state, nonce, session ID และ downstream request ดิบไม่ถูกเก็บเป็น plaintext

## audit_logs

- `public_id` UUID unique
- `actor_user_id`
- `action`
- `auditable_type`, `auditable_id`
- `target_public_id`
- `ip_hash`, `user_agent_hash`
- `context` JSON ที่ allowlist แล้ว
- `created_at`

ไม่มี raw CID, access token, API key หรือ client secret

## ตาราง framework

- `sessions`
- `cache`, `cache_locks`
- `jobs`, `job_batches`, `failed_jobs`
- `password_reset_tokens`

## Foreign key behavior

- API key และ access grant ถูก cascade เมื่อลบ parent จริง
- organization ที่มี effective grant ไม่อนุญาตให้ลบผ่าน Admin API
- การลบ application จะ revoke key และ grant ก่อน soft delete
- การลบ user จะ revoke token และ grant ก่อน soft delete

## งานฐานข้อมูลในเฟสถัดไป

- provider configuration โดยเก็บเพียง secret reference
- organization membership จาก Provider ID

## Decision ที่ยังค้าง

- legal basis และ retention สำหรับ raw CID ที่เข้ารหัส
- source of truth และ synchronization policy ของ hcode
- retention/purge policy ของ audit log

## Decision ที่ยืนยันแล้ว

- ThaID ใช้ keyed HMAC ของ PID ที่ตรวจสอบแล้วเพื่อจับคู่ `cid_hash`
- Provider ID ใช้ keyed HMAC อีกชุดครอบ `hash_cid` ที่ตรวจสอบรูปแบบแล้ว
- ThaID ไม่มี organization claim จึงเลือกได้เฉพาะ local effective access grant
- Provider ID ใช้ intersection ระหว่าง provider organizations กับ local grants
