# Decision Gates

ต้องตอบและบันทึกเป็น Architecture Decision Record ก่อน production implementation

| ID | คำถาม | เหตุผลที่เป็น gate | สถานะ |
| --- | --- | --- | --- |
| D-001 | รองรับ provider ใดในรุ่นแรก | กำหนด adapter และข้อมูล identity | Decided: 2 ส่วน — ThaID และ MOPH ID (Health ID → Provider ID) |
| D-002 | ใช้อะไรเป็นตัวผูกบุคคล และจับคู่ Provider ID hash CID อย่างไร | ป้องกันผูกสิทธิ์ผิดคน | Decided: ThaID ใช้ verified `sub` + `pid`; Provider ID ใช้ verified `account_id` + `hash_cid` SHA-256 ตามเอกสาร แล้ว HMAC ซ้ำด้วย key แยกก่อน lookup |
| D-003 | เก็บ raw CID หรือไม่ และมี legal basis/retention อย่างไร | กระทบ privacy และ schema | Blocker |
| D-004 | ผู้ใช้หลายหน่วยงานเลือกหน่วยงานอย่างไร | กระทบ authorization | Decided: ThaID ใช้ local effective grants; Provider ID ใช้ intersection กับ local grants แล้วให้เลือกหนึ่งแห่ง |
| D-005 | เว็บไซต์ปลายทางรองรับ OAuth 2.0/OIDC หรือมี legacy bridge | กำหนด downstream protocol | Partly decided: testsso ใช้ OAuth 2.0 Authorization Code + PKCE + userinfo; OIDC เต็มรูปแบบยังต้องยืนยันรายเว็บไซต์ |
| D-006 | ใช้ authorization server/broker ใด | หลีกเลี่ยงสร้าง security engine เอง | Decided: Laravel Passport 13.7.5 + League OAuth2 Server แยกจาก Sanctum Admin |
| D-007 | ใช้ application framework และ database ใด | กำหนด implementation/deployment | Decided: PHP 8.3 + Laravel 13 + MariaDB/MySQL |
| D-008 | URL และ callback URI ของ application รุ่น pilot คืออะไร | ต้องทำ exact allowlist | Decided for UAT pilot: entry/success คือ `/testsso/index.php`; registered callback exact match คือ `https://sobmoeiservice.moph.go.th/testsso/callback.php` |
| D-009 | production topology, secret manager, cache และ HA เป็นอย่างไร | กำหนด operations | Decision required |
| D-010 | source of truth ของ organization/hcode คือระบบใด | ป้องกันข้อมูลสิทธิ์ล้าสมัย | Decision required |
| D-011 | session lifetime, logout และ concurrent session policy | กำหนด token/session model | Decided for UAT: authorization transaction/code 5 นาที; local access/Admin token/session 30 นาที; upstream ตาม provider |
| D-012 | ใครอนุมัติ grant และใครตรวจ audit | กำหนด governance/admin roles | Decided: Admin หนึ่งคนทำได้ทุกอย่าง; SuperAdmin หลายคนจัดการ grant; ทั้งคู่ดู audit |

## ทางเลือกเริ่มต้นที่ควรประเมิน

1. **OIDC product/broker + custom adapter/policy service**

   ความเสี่ยงด้าน protocol ต่ำกว่า แต่ต้องประเมิน extension, operations และ license

2. **Framework application + mature OAuth/OIDC library**

   ยืดหยุ่นกว่า แต่ทีมต้องรับผิดชอบ protocol surface มากขึ้น

3. **One-time ticket เฉพาะระบบเก่า**

   ใช้เป็น compatibility bridge เท่านั้น ไม่ควรเป็น protocol หลัก
