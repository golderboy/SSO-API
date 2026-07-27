# แบบจำลองข้อมูล

Phase 1 ใช้ internal auto-increment ID สำหรับ foreign key และใช้ `public_id` แบบ UUID
สำหรับ URL/API เพื่อป้องกันการเดาเลขลำดับข้อมูล

## users

- `public_id` UUID unique
- `name`
- `email` nullable unique
- `password` nullable และ hash ด้วย password hasher ของ Laravel
- `cid_hash` keyed HMAC-SHA256 สำหรับ lookup
- `cid_encrypted` encrypted ด้วย `APP_KEY`
- `is_active`
- `is_super_admin`
- `last_login_at`
- timestamps และ soft delete

API Resource ไม่คืน password, CID, CID hash หรือ encrypted CID

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

- external identities และ provider subject
- provider configuration โดยเก็บเพียง secret reference
- registered callback URI แบบ exact match
- authentication transactions
- downstream authorization codes และ sessions
- organization membership จาก Provider ID

## Decision ที่ยังค้าง

- legal basis และ retention สำหรับ raw CID ที่เข้ารหัส
- วิธีจับคู่ Provider ID `hash_cid`
- source of truth และ synchronization policy ของ hcode
- retention/purge policy ของ audit log
