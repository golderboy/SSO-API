# External identity mapping

ระบบไม่สร้างผู้ใช้จาก ThaID หรือ Provider ID อัตโนมัติ การ login จะผ่านได้เมื่อ
upstream identity ที่ตรวจสอบแล้วจับคู่กับ `users` เดิมที่ active เท่านั้น

## Matching contract

### ThaID

1. ตรวจ ID Token signature, issuer, audience, expiry และ access-token binding
2. ตรวจ introspection เมื่อ contract กำหนด
3. ใช้ verified `sub` เป็น external subject
4. ใช้ verified `pid` คำนวณ keyed HMAC แล้วเทียบ `users.cid_hash`

### Provider ID

1. แลก Health ID authorization code ผ่าน TLS และใช้ Health ID access token
   เป็น opaque credential สำหรับแลก Provider ID token เท่านั้น
2. ตรวจ Provider ID token ด้วย RSA public key และ `RS256`
3. ตรวจให้ `account_id` จาก Health ID, Provider ID token response และ profile
   ตรงกัน
4. ใช้ verified `account_id` เป็น external subject
5. normalize `hash_cid` SHA-256 เป็น lowercase hex 64 ตัวอักษร
6. HMAC `hash_cid` ซ้ำแล้วเทียบ `users.provider_cid_hash`
7. ใช้เฉพาะ grant ที่ organization `hcode` อยู่ใน Provider ID profile

ไม่บันทึก raw subject, PID หรือ Provider ID `hash_cid` ลง
`external_identities`

## Keys

กำหนดค่าความลับคนละค่าและห้าม reuse:

- `CID_LOOKUP_KEY`
- `PROVIDER_CID_LOOKUP_KEY`
- `EXTERNAL_SUBJECT_LOOKUP_KEY`
- `TRANSACTION_HASH_KEY`
- `AUDIT_HASH_KEY`

การเปลี่ยน lookup key ต้องมี migration/rotation plan เพราะ hash เดิมจะค้นไม่เจอ
ห้ามเปลี่ยนค่าใน `.env` โดยไม่มีขั้นตอน re-key

## Upgrade ฐานข้อมูลเดิมบน AlmaLinux 9

สำรองฐานข้อมูลก่อน แล้วนำเข้า:

```bash
mysql --host=127.0.0.1 --port=3309 --user=DB_USER -p \
  < database/schema/upgrades/2026_07_27_063324_identity_mapping.sql
```

หลังใส่ key ทั้งสี่ค่าใน `.env` ให้ตรวจและ backfill โดยไม่มีการแสดง CID:

```bash
sudo -u apache php artisan optimize:clear
sudo -u apache php artisan sso:backfill-provider-cid-hashes --dry-run
sudo -u apache php artisan sso:backfill-provider-cid-hashes
sudo -u apache php artisan sso:check-installation
```

อย่าเปิด `THAID_ENABLED` หรือ `MOPH_ID_ENABLED` จนกว่าคำสั่งตรวจ installation
จะผ่านและ provider contract tests ผ่าน UAT

## Failure behavior

- ไม่พบ user: `identity_not_authorized`
- subject เดิมแต่ CID/hash เปลี่ยน: `identity_link_mismatch`
- subject ใหม่ชน provider CID ที่ link แล้ว: `identity_link_conflict`
- user inactive/deleted: ปฏิเสธ

เหตุการณ์เหล่านี้ต้อง fail closed และบันทึกเฉพาะ reason กับ correlation ID
ห้ามบันทึก PII หรือ upstream token
