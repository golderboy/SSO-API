<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>เลือกช่องทางยืนยันตัวตน · Sobmoei SSO</title>
</head>
<body>
<main>
    <h1>เลือกช่องทางยืนยันตัวตน</h1>
    <p>
        เข้าสู่ระบบ
        {{ $transaction->ssoConfig->application->name }}
        ผ่านผู้ให้บริการที่ได้รับอนุญาต
    </p>

    @foreach ($transaction->ssoConfig->allowed_providers as $provider)
        <form
            method="post"
            action="{{ route('sso.broker.select-provider', $transaction) }}"
        >
            @csrf
            <input type="hidden" name="provider" value="{{ $provider->value }}">
            <button type="submit">
                {{ $provider->value === 'thaid' ? 'เข้าสู่ระบบด้วย ThaID' : 'เข้าสู่ระบบด้วย Provider ID' }}
            </button>
        </form>
    @endforeach
</main>
</body>
</html>
