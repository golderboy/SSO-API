# SSO Discovery และ Scrutiny Findings

## เป้าหมาย

สร้าง SSO กลางที่รับการยืนยันตัวตนจากผู้ให้บริการภายนอก ตรวจสิทธิ์ผู้ใช้กับแอปและหน่วยงานในฐานข้อมูลภายใน แล้วส่งผลกลับเว็บไซต์ต้นทางผ่าน flow ที่ปลอดภัย

## ขอบเขตที่ยืนยันได้

- ระบบต้องรองรับเว็บไซต์หลายระบบและ callback ที่ลงทะเบียนล่วงหน้า
- การ Authentication จากภายนอกต้องแยกจาก Authorization ภายใน
- สิทธิ์ขึ้นกับตัวบุคคล แอปพลิเคชัน และข้อมูลหน่วยงาน
- Provider ID อาจคืนหน่วยงานมากกว่าหนึ่งรายการ
- เอกสารต้นฉบับมีอายุมากกว่าสองปีเมื่อเริ่มโครงการ จึงต้องทำ contract test กับ UAT ก่อนยืนยัน API contract

รายละเอียด endpoint และตัวอย่าง payload จากเอกสารภายในไม่ถูกทำซ้ำใน public repository นี้

## Findings จากการทบทวนแบบ outsider

### Blocker: ยังไม่มีวิธีจับคู่ CID ที่ยืนยันได้

**เหตุผล:** Provider ID ให้ค่า CID แบบ hash ขณะที่ฐานสิทธิ์ยังไม่ได้กำหนดว่าจะเก็บ CID จริงหรือค่าใด หากจับคู่ผิด ผู้ใช้ที่ถูกต้องจะถูกปฏิเสธ หรือร้ายกว่านั้นคือผูกสิทธิ์ผิดคน

**การแก้ไขขั้นต่ำ:** ขอข้อกำหนดอย่างเป็นทางการเกี่ยวกับ normalization และ hash contract หรือใช้ subject identifier ที่ผู้ให้บริการรับรองร่วมกับขั้นตอน enrollment

### Blocker: การสร้าง Authorization Server เองเพิ่มความเสี่ยงโดยไม่จำเป็น

**เหตุผล:** การออก code/token, signing, key rotation, discovery, revocation และ validation เป็น security-critical surface

**ทางเลือกที่เล็กกว่า:** ใช้ OIDC server/broker ที่ผ่านการใช้งานจริง แล้วเขียนเฉพาะ upstream provider adapter และ authorization policy integration หากระบบปลายทางเก่ารองรับ OIDC ไม่ได้ จึงค่อยเพิ่ม one-time ticket bridge ที่มีขอบเขตจำกัด

### Major: Dynamic Authenticator อาจถูกออกแบบกว้างเกินความจำเป็น

**เหตุผล:** หาก request เลือก URL หรือ configuration ได้เอง จะเกิด SSRF, mix-up attack และ credential leakage

**การแก้ไขขั้นต่ำ:** client เลือกได้เพียง provider key ที่ allowlist ไว้ และ server เป็นผู้ resolve endpoint กับ credential จาก trusted configuration

### Major: การเลือก organization ยังไม่มี business rule

**เหตุผล:** การใช้ organization รายการแรกทำให้สิทธิ์ขึ้นกับลำดับ payload ที่ผู้ให้บริการอาจเปลี่ยนได้

**การแก้ไขขั้นต่ำ:** กำหนดนโยบายต่อแอป เช่น exact hcode, intersection กับ access grant หรือให้ผู้ใช้เลือกจากรายการที่มีสิทธิ์เท่านั้น

### Major: Public repository ห้ามมีเอกสารต้นฉบับ

**เหตุผล:** เอกสารอย่างน้อยหนึ่งฉบับมีเครื่องหมาย confidential และข้อจำกัดการเผยแพร่

**การแก้ไขขั้นต่ำ:** ignore PDF และ source document ทั้งหมด ตรวจ staged files และ Git history ก่อน push ทุกครั้ง

## Assumptions ที่ใช้เฉพาะการวางแผน

- downstream application สามารถแก้ไข integration ได้อย่างน้อยบางส่วน
- การตรวจสิทธิ์ต้อง fail closed เมื่อ policy database หรือ provider ใช้งานไม่ได้
- production ใช้ HTTPS เท่านั้น
- credential จะถูกส่งเข้า runtime ผ่าน secret manager

## Verdict

**Rework before implementation:** แนวคิดใช้งานได้ แต่ต้องตัดสินใจเรื่อง identity matching และเลือก downstream OIDC engine ก่อนเขียน production code
