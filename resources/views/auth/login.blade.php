<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unlock Tracker</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-screen">
    <main class="login-shell">
        <section class="login-panel">
            <div class="brand-lock">
                <span></span>
            </div>
            <h1>Achievement Tracker</h1>
            <p>Private Steam achievement dashboard</p>

            <form method="POST" action="{{ route('login.store') }}" class="login-form">
                @csrf
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required autofocus autocomplete="current-password">
                @error('password')
                    <div class="form-error">{{ $message }}</div>
                @enderror
                <button type="submit">Unlock</button>
            </form>
        </section>
    </main>
</body>
</html>
