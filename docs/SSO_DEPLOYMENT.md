# แนวทางติดตั้งและปฏิบัติการ

## หลักการ

ยังไม่สร้าง Dockerfile หรือ Compose ก่อนเลือก stack เพราะ deployment artifact ที่เดา framework จะกลายเป็นภาระและอาจให้ค่าความปลอดภัยผิด

หลังเลือก stack ให้จัดส่ง:

- immutable application image
- database migration job แยกจาก web process
- health endpoint แยก liveness/readiness
- runtime configuration ผ่าน environment และ secret mount
- reverse proxy configuration พร้อม HTTPS และ trusted proxy allowlist
- worker สำหรับ cleanup transaction, revocation และ audit export

## Environment

- `local`: mock provider และข้อมูลทดสอบเท่านั้น
- `test`: automated integration
- `uat`: upstream UAT credential
- `staging`: topology ใกล้ production
- `production`: production credential และ key แยกทั้งหมด

ห้ามใช้ credential หรือ signing key ร่วมข้าม environment

## Production topology ขั้นต่ำ

- TLS reverse proxy/load balancer
- SSO application อย่างน้อยสอง instance หากต้องการ high availability
- shared database
- shared cache สำหรับ short-lived transaction/session ตาม design
- secret manager
- centralized log/metric/trace โดยมี redaction
- encrypted backup

## Deployment flow

1. build และ sign artifact
2. test และ scan artifact เดียวกับที่จะ deploy
3. backup และตรวจ migration plan
4. deploy staging และรัน smoke/security tests
5. production canary หรือ rolling deployment
6. ตรวจ error, latency, denial และ token-exchange metrics
7. rollback เมื่อเกิน threshold ที่อนุมัติ

## Server checklist

- DNS และ certificate ถูกต้อง
- callback URI ถูกลงทะเบียนแบบ exact match
- clock sync ทำงาน
- outbound allowlist ถึง provider เท่านั้น
- database account ใช้ least privilege
- filesystem เป็น read-only ยกเว้นตำแหน่งที่จำเป็น
- debug ปิด
- backup restore และ key rotation ผ่านการซ้อม
