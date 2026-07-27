# Decision Gates

ต้องตอบและบันทึกเป็น Architecture Decision Record ก่อน production implementation

| ID | คำถาม | เหตุผลที่เป็น gate | สถานะ |
| --- | --- | --- | --- |
| D-001 | รองรับ ThaID, Health ID, Provider ID หรือชุดใดในรุ่นแรก | กำหนด adapter และข้อมูล identity | Decision required |
| D-002 | ใช้อะไรเป็นตัวผูกบุคคล และจับคู่ Provider ID hash CID อย่างไร | ป้องกันผูกสิทธิ์ผิดคน | Blocker |
| D-003 | เก็บ raw CID หรือไม่ และมี legal basis/retention อย่างไร | กระทบ privacy และ schema | Blocker |
| D-004 | ผู้ใช้หลายหน่วยงานเลือกหน่วยงานอย่างไร | กระทบ authorization | Decision required |
| D-005 | เว็บไซต์ปลายทางรองรับ OIDC หรือมี legacy bridge | กำหนด downstream protocol | Decision required |
| D-006 | เลือก OIDC server/broker ใด | หลีกเลี่ยงสร้าง security engine เอง | Blocker |
| D-007 | ใช้ application framework และ database ใด | กำหนด implementation/deployment | Blocker |
| D-008 | URL และ callback URI ของ application รุ่น pilot คืออะไร | ต้องทำ exact allowlist | Decision required |
| D-009 | production topology, secret manager, cache และ HA เป็นอย่างไร | กำหนด operations | Decision required |
| D-010 | source of truth ของ organization/hcode คือระบบใด | ป้องกันข้อมูลสิทธิ์ล้าสมัย | Decision required |
| D-011 | session lifetime, logout และ concurrent session policy | กำหนด token/session model | Decision required |
| D-012 | ใครอนุมัติ grant และใครตรวจ audit | กำหนด governance/admin roles | Decision required |

## ทางเลือกเริ่มต้นที่ควรประเมิน

1. **OIDC product/broker + custom adapter/policy service**

   ความเสี่ยงด้าน protocol ต่ำกว่า แต่ต้องประเมิน extension, operations และ license

2. **Framework application + mature OAuth/OIDC library**

   ยืดหยุ่นกว่า แต่ทีมต้องรับผิดชอบ protocol surface มากขึ้น

3. **One-time ticket เฉพาะระบบเก่า**

   ใช้เป็น compatibility bridge เท่านั้น ไม่ควรเป็น protocol หลัก
