@extends('layouts.app')

@section('title', 'Enroll agent')

@section('content')
    <div class="row justify-content-center"><div class="col-lg-8 col-xl-7">
        <div class="monitor-card p-4 p-lg-5">
            <p class="small-label mb-2">Next step</p>
            <h1 class="h2 fw-bold">{{ $server->name }} is ready to enroll</h1>
            <p class="text-secondary">This one-time token expires in 30 minutes. Copy it now; it is only shown on this page.</p>
            <div class="alert alert-warning small">Keep this token private. Anyone who obtains it can enroll an agent to this pending server.</div>
            <label class="form-label fw-semibold" for="enrollment-token">One-time enrollment token</label>
            <div class="input-group mb-4">
                <input class="form-control font-monospace copy-secret" id="enrollment-token" value="{{ $plainToken }}" readonly>
                <button class="btn btn-outline-primary" type="button" data-copy="#enrollment-token">Copy</button>
            </div>
            <p class="mb-2 fw-semibold">Agent enrollment endpoint</p>
            <code class="d-block p-3 bg-light rounded text-break mb-4">{{ url('/api/v1/agent/enroll') }}</code>
            <p class="small text-secondary">The enrollment request must use HTTPS on your VPS. After enrollment, the API returns a per-server credential once; store it in a protected agent configuration file.</p>
            <a class="btn btn-primary" href="{{ route('servers.show', $server) }}">Open server details</a>
        </div>
    </div></div>
@endsection
