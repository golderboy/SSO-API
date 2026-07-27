# Database upgrades

ไฟล์ในโฟลเดอร์นี้ใช้กับฐานข้อมูลที่ติดตั้งไปแล้วเท่านั้น
ฐานข้อมูลใหม่ให้ใช้ `database/schema/sso_mariadb.sql`

## System roles

ก่อนอัปเกรดให้สำรองฐานข้อมูลและตรวจจำนวนบัญชี Admin เดิม:

```bash
mysql --host=127.0.0.1 --port=3309 --user=DB_USER -p \
  --database=sso \
  --execute="SELECT id, public_id, email FROM users WHERE is_super_admin = 1;"
```

ต้องพบไม่เกินหนึ่งแถว หากมากกว่าหนึ่งแถว SQL upgrade จะหยุดโดยไม่เดาว่า
บัญชีใดควรเป็น Admin

นำเข้า:

```bash
mysql --host=127.0.0.1 --port=3309 --user=DB_USER -p \
  < database/schema/upgrades/2026_07_27_063318_system_roles.sql
```

ตรวจผล:

```bash
mysql --host=127.0.0.1 --port=3309 --user=DB_USER -p \
  --database=sso \
  --execute="SELECT public_id, email, system_role FROM users WHERE system_role <> 'user';"

sudo -u apache php artisan migrate:status
```

จากนั้น deploy application code รุ่นเดียวกันและสร้าง Laravel cache ใหม่:

```bash
sudo -u apache php artisan optimize:clear
sudo -u apache php artisan optimize
```

Rollback ต้อง rollback application code พร้อมกัน:

```bash
mysql --host=127.0.0.1 --port=3309 --user=DB_USER -p \
  < database/schema/upgrades/2026_07_27_063318_system_roles_rollback.sql
```

หลัง rollback เฉพาะ Admin เดิมเท่านั้นที่ยังเข้า Admin API ได้ บัญชี SuperAdmin
จะถูกปิดสิทธิชั่วคราวเพื่อไม่ให้ได้รับสิทธิเต็มแบบ schema รุ่นเก่า
