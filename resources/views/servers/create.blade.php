@extends('layouts.app')

@section('title', 'Add server')

@section('content')
    <div class="row justify-content-center"><div class="col-lg-7 col-xl-6">
        <a class="small text-decoration-none" href="{{ route('servers.index') }}">← Back to servers</a>
        <div class="monitor-card p-4 p-lg-5 mt-3">
            <p class="small-label mb-2">Server registration</p>
            <h1 class="h2 fw-bold">Add a server</h1>
            <p class="text-secondary mb-4">We’ll create a pending server record and a one-time enrollment token for its agent.</p>
            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    <p class="fw-semibold mb-1">Please correct the following errors:</p>
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form method="POST" action="{{ route('servers.store') }}">
                @csrf
                <div class="mb-3"><label class="form-label" for="name">Display name</label><input class="form-control form-control-lg" id="name" name="name" value="{{ old('name') }}" maxlength="120" required autofocus placeholder="Production web server"></div>
                <div class="mb-4"><label class="form-label" for="platform">Operating system</label><select class="form-select form-select-lg" id="platform" name="platform" required><option value="linux" @selected(old('platform') === 'linux')>Linux</option><option value="windows" @selected(old('platform') === 'windows')>Windows</option></select></div>
                <div class="d-flex gap-2"><button class="btn btn-primary" type="submit">Create enrollment token</button><a class="btn btn-light" href="{{ route('servers.index') }}">Cancel</a></div>
            </form>
        </div>
    </div></div>
@endsection
