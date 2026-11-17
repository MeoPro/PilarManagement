<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Admin | Pilar Management</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
    <main class="login-card">
        <a class="brand" href="{{ route('login') }}">
            <span class="brand-mark">P</span>
            <span class="brand-name">Pilar<span>Management</span></span>
        </a>

        <p class="page-eyebrow">Panel administrasi</p>
        <h1>Selamat datang kembali</h1>
        <p>Masuk untuk melanjutkan pengelolaan Pilar Management.</p>

        @if($errors->any())
            <div class="error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form action="{{ route('login.store') }}" method="POST">
            @csrf
            <div class="form-group">
                <label for="email">Email</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    autocomplete="username"
                    placeholder="nama@email.com"
                    required
                    autofocus
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    placeholder="Masukkan password"
                    required
                >
            </div>

            <div class="login-remember">
                <input id="remember" type="checkbox" name="remember" value="1" checked>
                <label for="remember">Tetap masuk di perangkat ini</label>
                <small>Hapus centang jika menggunakan perangkat bersama.</small>
            </div>

            <button class="btn btn-primary" type="submit">Masuk ke Dashboard</button>
        </form>

        <div class="login-credit">
            Designed &amp; developed by
            <a href="https://datadropx.net" target="_blank" rel="noopener noreferrer">DataDropX</a>
        </div>
    </main>
</body>
</html>
