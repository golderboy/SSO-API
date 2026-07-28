<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>เลือกหน่วยงาน · Sobmoei SSO</title>
</head>
<body>
<main>
    <h1>เลือกหน่วยงานที่ต้องการเข้าใช้งาน</h1>
    <p>
        บัญชีของคุณมีสิทธิในหลายหน่วยงานสำหรับ
        {{ $transaction->ssoConfig->application->name }}
    </p>

    @foreach ($grants as $grant)
        <form
            method="post"
            action="{{ route('sso.broker.select-organization', $transaction) }}"
        >
            @csrf
            <input
                type="hidden"
                name="access_grant"
                value="{{ $grant->public_id }}"
            >
            <button type="submit">
                {{ $grant->organization?->name_th ?? 'สิทธิส่วนกลาง' }}
                @if ($grant->organization?->hcode)
                    ({{ $grant->organization->hcode }})
                @endif
            </button>
        </form>
    @endforeach
</main>
</body>
</html>
