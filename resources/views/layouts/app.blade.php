<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name', 'Dream Hosting Monitor') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark app-nav">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="{{ route('dashboard') }}">
            <span class="app-brand-mark">D</span>
            <span>Dream Hosting <span class="text-white-50">Monitor</span></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <div class="navbar-nav me-auto ms-lg-4">
                <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">Overview</a>
                <a class="nav-link {{ request()->routeIs('servers.*') ? 'active' : '' }}" href="{{ route('servers.index') }}">Servers</a>
            </div>
            @auth
                <div class="d-flex align-items-center gap-3 text-white-50 small">
                    <span>{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-light" type="submit">Sign out</button>
                    </form>
                </div>
            @endauth
        </div>
    </div>
</nav>

<main class="container-fluid px-3 px-lg-5 py-4 py-lg-5">
    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="status">
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif
    @yield('content')
</main>
</body>
</html>
