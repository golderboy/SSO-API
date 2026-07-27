# Security Policy

## Reporting

ห้ามเปิดเผยช่องโหว่ token, credential, CID, callback URI ภายใน หรือข้อมูลส่วนบุคคลผ่าน public issue

ให้รายงานผ่านช่องทางภายในของผู้ดูแลระบบที่หน่วยงานกำหนด เมื่อกำหนดช่องทางแล้วต้องอัปเดตไฟล์นี้ก่อนเปิดใช้งาน production

## Secrets

- ห้าม commit client secret, access token, refresh token, private key หรือ production configuration
- ใช้ secret manager หรือไฟล์ที่ mount จากระบบ deployment
- หากพบ secret ใน Git history ให้ revoke และ rotate ทันที การลบไฟล์ออกจาก commit ล่าสุดไม่เพียงพอ

## Supported Versions

ยังไม่มี release ที่รองรับ production
