<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>ไม่สามารถเข้าสู่ระบบได้ · Sobmoei SSO</title>
</head>
<body>
<main>
    <h1>ไม่สามารถเข้าสู่ระบบได้</h1>
    <p>{{ $message }}</p>
    <p>รหัสข้อผิดพลาด: {{ $error }}</p>
    @isset($correlationId)
        <p>รหัสอ้างอิง: {{ $correlationId }}</p>
    @endisset
</main>
</body>
</html>
