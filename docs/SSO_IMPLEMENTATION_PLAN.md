# แผนพัฒนาระบบ

## Phase 0 - Decision และ governance

- ตอบ decision gates
- ระบุ data owner, system owner และ security owner
- ยืนยันสิทธิ์ใช้เอกสารและ upstream API
- เลือก OIDC engine, application stack, database, cache และ secret manager

**Exit criteria:** ADR ได้รับอนุมัติและไม่มี blocker เรื่อง identity matching

## Phase 1 - Repository และ local development

- scaffold ตาม stack ที่เลือก
- reproducible local environment
- configuration validation
- migration framework
- CI สำหรับ test, lint, secret scan และ dependency scan

**Exit criteria:** developer ใหม่เริ่มระบบและรัน test ได้จากเอกสาร

## Phase 2 - Core downstream OIDC

- application registration และ redirect allowlist
- authorization transaction
- code/token flow ผ่าน engine ที่เลือก
- signing key และ rotation
- session, logout และ revocation

**Exit criteria:** mock application ผ่าน happy path และ security regression

## Phase 3 - Provider adapters

- adapter interface
- mock provider
- 2 provider sections: ThaID และ MOPH ID (Health ID → Provider ID)
- strict validation, timeout และ error mapping

**Exit criteria:** contract test ผ่าน UAT โดยไม่บันทึก credential หรือ PII

## Phase 4 - Identity และ authorization

- enrollment/mapping
- user, organization, role และ grant
- multi-organization policy
- admin approval/revocation
- audit event

**Exit criteria:** authorization matrix และ negative tests ผ่าน

## Phase 5 - Operations

- metrics, tracing, alert และ sanitized log
- backup/restore
- key/secret rotation
- rate limit และ capacity test
- incident and provider outage runbook

**Exit criteria:** operational readiness review ผ่าน

## Phase 6 - Pilot และ rollout

- เริ่มจาก application เดียว
- จำกัดกลุ่มผู้ใช้
- monitor denial/error/token exchange
- เพิ่ม application ทีละระบบ

**Exit criteria:** owner ของแต่ละ application ลงนามรับรองและมี rollback plan

## Definition of Done

- requirement มี acceptance test
- code review authentication path อย่างน้อยสองรอบ
- test จริงผ่านและมีหลักฐาน
- ไม่มี high/critical security finding
- ไม่มี secret/PII ใน Git, artifact และ log
- deployment และ rollback ผ่าน staging rehearsal
