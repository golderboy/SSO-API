# Phase 1 Implementation

## สิ่งที่พัฒนาแล้ว

- Laravel 13 API-only application
- Laravel Sanctum สำหรับ Admin Bearer Token
- Personnel, organization, application และ access-grant schema
- Admin CRUD controllers และ Form Request validation
- Application API key create, custom key, rotation และ revocation
- CID encryption และ keyed HMAC lookup
- Access decision service
- Audit logging แบบ pseudonymous
- Rate limiting ก่อนและหลัง API-key authentication
- Secure response headers
- Interactive command `sso:create-admin`
- SQLite automated test environment
- MariaDB/MySQL production configuration template

## OWASP API Security coverage

| Risk | Control ในเฟสนี้ |
| --- | --- |
| API1 Broken Object Level Authorization | UUID route key, nested API-key ownership check |
| API2 Broken Authentication | Sanctum, strong password rule, token expiry, generic errors, dummy hash verification |
| API3 Broken Object Property Authorization | Form Request allowlist และ API Resources |
| API4 Unrestricted Resource Consumption | Pagination cap, field length cap, layered rate limit |
| API5 Broken Function Level Authorization | `auth:sanctum`, token ability และ super-admin middleware |
| API6 Sensitive Business Flows | Audit log และ API key lifecycle |
| API7 SSRF | Provider URL มาจาก trusted environment และ `base_url` ยังไม่ถูก dereference |
| API8 Security Misconfiguration | `.env.example`, debug off, security headers |
| API9 Improper Inventory Management | Versioned `/api/v1` และ route inventory |
| API10 Unsafe Consumption of APIs | Provider adapter ยังไม่เปิดใช้จนกว่าจะมี contract test |

## ขอบเขตที่ยังไม่พัฒนา

- ThaID callback และ token validation
- MOPH ID adapter ที่ทำ flow Health ID → Provider ID
- Dynamic provider selection
- Downstream OAuth/OIDC authorization-code server
- Admin web user interface
- Contract tests กับ UAT
- Production MariaDB integration test

รายการข้างต้นเป็นงานเฟสถัดไปและต้องตอบ decision gates ที่เกี่ยวข้องก่อน

## Definition of Ready สำหรับเฟสถัดไป

- มี UAT credential ผ่าน secret manager
- ยืนยันวิธีจับคู่ Provider ID `hash_cid`
- ยืนยัน callback URI แบบ exact match
- ยืนยัน source of truth ของ hcode
- เลือก downstream OIDC engine
- มี MariaDB test instance และ production-like staging
