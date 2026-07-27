# SSO API - Sobmoei

โครงการเตรียมพัฒนาระบบ Single Sign-On (SSO) กลางสำหรับเว็บไซต์ของหน่วยงาน โดยแยกการยืนยันตัวตนจากผู้ให้บริการภายนอกออกจากการตรวจสิทธิ์ภายในตามผู้ใช้ แอปพลิเคชัน และหน่วยงาน

## สถานะโครงการ

### Planning / Architecture phase - ยังไม่พร้อมใช้งานจริง

Repository นี้ยังไม่มี production code และยังไม่ควรนำไปติดตั้งบนเซิร์ฟเวอร์ จนกว่าจะตัดสินใจเรื่อง Identity Provider, วิธีจับคู่ CID, technology stack, รูปแบบ downstream SSO และโครงสร้าง production infrastructure

## หลักการสำคัญ

- ใช้ Authorization Code flow สำหรับเว็บไซต์ปลายทาง
- ตรวจ callback URI แบบ exact match เพื่อป้องกัน open redirect
- ไม่ส่ง upstream token, CID หรือข้อมูลส่วนบุคคลผ่าน URL
- ตรวจสิทธิ์จากฐานข้อมูลภายในก่อนออก authorization code ของระบบ
- ใช้ provider adapter เพื่อแยก ThaID, Health ID และ Provider ID
- ไม่สร้าง OAuth/OIDC cryptographic engine เอง หากใช้ผลิตภัณฑ์หรือไลบรารีที่ผ่านการตรวจสอบได้
- ไม่เก็บ secret, private key, token จริง หรือเอกสารภายในใน Git

## เอกสาร

- [ข้อค้นพบและข้อทักท้วง](docs/SSO_DISCOVERY.md)
- [แผนสถาปัตยกรรม](docs/SSO_ARCHITECTURE.md)
- [แบบจำลองข้อมูล](docs/SSO_DATA_MODEL.md)
- [ข้อกำหนดด้านความปลอดภัย](docs/SSO_SECURITY.md)
- [กลยุทธ์การทดสอบ](docs/SSO_TEST_STRATEGY.md)
- [แผนพัฒนา](docs/SSO_IMPLEMENTATION_PLAN.md)
- [แนวทางติดตั้ง](docs/SSO_DEPLOYMENT.md)
- [Decision gates](docs/SSO_DECISIONS.md)
- [ร่าง OpenAPI](docs/SSO_API_SPEC.yaml)

## เอกสารต้นฉบับ

เอกสาร API ต้นฉบับเป็นเอกสารภายในและไม่ถูกติดตามด้วย Git เนื่องจาก repository นี้เป็น public ผู้พัฒนาต้องได้รับสิทธิ์เข้าถึงเอกสารจากช่องทางที่หน่วยงานอนุมัติ

## การเริ่มพัฒนา

1. ตอบ decision gates ใน `docs/SSO_DECISIONS.md`
2. เลือก downstream OIDC engine และ technology stack
3. สร้าง threat model review และ data-protection review
4. ทำ prototype กับ mock provider
5. ทดสอบ contract กับ UAT โดยเก็บ credential ใน secret manager
6. ผ่าน security และ deployment readiness gate ก่อน production
