<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrator sign in · Dream Hosting Monitor</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-vh-100 d-flex align-items-center">
<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-7 col-lg-5 col-xl-4">
            <div class="monitor-card p-4 p-lg-5">
                <div class="app-brand-mark mb-4">D</div>
                <p class="small-label mb-2">Self-hosted monitoring</p>
                <h1 class="h3 fw-bold mb-2">Welcome back</h1>
                <p class="text-secondary mb-4">Sign in to manage your monitored servers.</p>
                @if ($errors->any())
                    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
                @endif
                <form method="POST" action="{{ route('login.store') }}" autocomplete="on">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="email">Email address</label>
                        <input class="form-control form-control-lg" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" required>
                    </div>
                    <div class="form-check mb-4">
                        <input class="form-check-input" id="remember" name="remember" type="checkbox" value="1">
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>
                    <button class="btn btn-primary btn-lg w-100" type="submit">Sign in</button>
                </form>
                <p class="small text-secondary mt-4 mb-0">Administrator accounts are created from the server console.</p>
            </div>
            <p class="small text-center text-secondary mt-3">Dream Hosting Server Monitor · Open source</p>
        </div>
    </div>
</main>
</body>
</html>
