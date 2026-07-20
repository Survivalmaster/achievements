<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unlock Tracker</title>
    <link rel="stylesheet" href="{{ asset('assets/tracker.css') }}">
    <script src="{{ asset('assets/tracker.js') }}" defer></script>
</head>
<body class="auth-screen">
    <main class="login-shell">
        <section class="login-panel">
            <div class="brand-lock">
                <span></span>
            </div>
            <h1>Achievement Tracker</h1>
            <p>Sign in with Steam to track your own achievement library.</p>

            <div class="login-form">
                @error('steam')
                    <div class="form-error">{{ $message }}</div>
                @enderror
                <a class="steam-login-button" href="{{ route('steam.redirect') }}">Sign in through Steam</a>
            </div>
        </section>
    </main>
</body>
</html>
