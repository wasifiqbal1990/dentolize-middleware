<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Whisper Login</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; background: #f6f7f9; color: #17202a; }
        main { max-width: 380px; margin: 12vh auto; background: #fff; border: 1px solid #dde2e8; border-radius: 8px; padding: 28px; }
        label { display: block; font-size: 14px; margin-top: 14px; }
        input { width: 100%; box-sizing: border-box; margin-top: 6px; padding: 10px; border: 1px solid #b8c0cc; border-radius: 6px; }
        button { margin-top: 18px; width: 100%; padding: 10px; border: 0; border-radius: 6px; background: #1769aa; color: #fff; font-weight: 700; }
        .error { color: #b42318; font-size: 14px; }
    </style>
</head>
<body>
<main>
    <h1>Whisper</h1>
    <form method="post" action="/admin/auth/login">
        @csrf
        <label>Email <input name="email" type="email" value="admin@example.com" required></label>
        <label>Password <input name="password" type="password" required></label>
        @if ($errors->any()) <p class="error">{{ $errors->first() }}</p> @endif
        <button type="submit">Sign in</button>
    </form>
</main>
</body>
</html>
