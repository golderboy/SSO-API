# แบบจำลองข้อมูล

## ตารางหลัก

### applications

- `id` internal UUID
- `client_id` unique public identifier
- `name`
- `status`
- `client_type`
- `token_policy_id`
- `created_at`, `updated_at`

### application_redirect_uris

- `application_id`
- `redirect_uri`
- `status`
- unique `(application_id, redirect_uri)`

ต้องเทียบ URI แบบ exact match หลัง canonicalization ที่กำหนดชัดเจน ห้ามใช้ SQL wildcard

### provider_configs

- `id`
- `provider_key` unique
- `provider_type`
- `environment`
- `issuer_or_base_url`
- `secret_reference`
- `status`
- `version`

เก็บเพียง secret reference ไม่เก็บ client secret ในตารางนี้

### users

- `id` internal UUID
- `status`
- `display_name`
- `cid_lookup` nullable และต้องเป็น keyed HMAC หากจำเป็น
- `cid_ciphertext` nullable เฉพาะ use case ที่ได้รับอนุมัติ
- `created_at`, `updated_at`, `disabled_at`

### external_identities

- `id`
- `user_id`
- `provider_config_id`
- `issuer`
- `subject`
- `provider_account_id`
- `provider_hash_cid` nullable
- unique `(issuer, subject)`

### organizations

- `id`
- `hcode` nullable unique ตามแหล่งข้อมูล
- `business_id` nullable
- `name`
- `status`

### user_organizations

- `user_id`
- `organization_id`
- `source`
- `source_verified_at`
- `valid_from`, `valid_until`
- `status`
- unique `(user_id, organization_id, source)`

### access_grants

- `id`
- `user_id`
- `application_id`
- `organization_id` nullable
- `role_id`
- `valid_from`, `valid_until`
- `status`
- `approved_by`
- `approved_at`

### auth_transactions

- `id`
- hashed state/nonce/code verifier binding
- `application_id`
- `provider_config_id`
- `redirect_uri_id`
- `expires_at`
- `consumed_at`
- `result`

ข้อมูล transaction ต้องมีอายุสั้นและลบตาม retention policy

### sessions และ authorization_codes

เก็บ identifier แบบ hash, expiration, consumed/revoked timestamp และ binding ที่จำเป็น ห้ามเก็บ raw bearer token

### audit_events

- immutable event ID
- timestamp
- actor type และ pseudonymous actor ID
- action, result, application ID, organization ID
- correlation ID
- source IP แบบลดความละเอียดตามนโยบาย
- metadata ที่ allowlist แล้ว

## Index และ constraint สำคัญ

- unique issuer + subject
- unique application + redirect URI
- index grant ตาม user + application + status + validity
- authorization code ต้อง atomic consume ได้ครั้งเดียว
- foreign key ต้องป้องกัน orphan grant
- soft delete ใช้กับข้อมูลที่ต้อง audit แต่ต้องมี retention และ purge job

## Decision required

- ชนิดฐานข้อมูล
- วิธี identity enrollment และ CID matching
- การเก็บ raw CID จำเป็นหรือไม่
- source of truth สำหรับ hcode
- retention ของ transaction, session และ audit
