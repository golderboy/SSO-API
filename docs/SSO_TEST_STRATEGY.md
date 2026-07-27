# กลยุทธ์การทดสอบ

## Test pyramid

### Unit tests

- callback URI exact matching
- state/nonce/PKCE validation
- JWT claim validation
- organization intersection
- access grant validity
- single-use code consumption
- redaction ของ log

### Integration tests

- mock ThaID/Health ID/Provider ID adapter
- database transaction และ concurrent code exchange
- key rotation
- provider timeout/retry/circuit breaker
- session revocation

### Contract tests

รันกับ UAT เท่านั้นโดยใช้ credential จาก secret manager ตรวจ request/response จริงและบันทึกเฉพาะ schema หรือ sanitized result

### End-to-end tests

- หลาย application และ callback
- ผู้ใช้มีสิทธิ์และไม่มีสิทธิ์
- ผู้ใช้มีหลาย organization
- application/organization/user ถูกปิดระหว่าง flow
- logout และ revocation

## Security regression suite

- state mismatch
- nonce mismatch
- callback ปลอมและ URL encoding edge cases
- code ใช้ซ้ำหรือหมดอายุ
- signature, issuer และ audience ผิด
- provider key ที่ไม่ได้อนุญาต
- organization/role tampering
- sensitive value ไม่ปรากฏใน log

## Release gate

ทุก release ต้องมี:

- test command และผลลัพธ์ที่ทำซ้ำได้
- static analysis และ dependency scan
- migration test ทั้ง upgrade และ rollback
- secret scan ของ staged files และ Git history
- manual review ของ authentication/authorization path
- UAT sign-off ก่อน production
