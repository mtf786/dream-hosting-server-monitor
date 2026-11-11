@extends('layouts.app')

@section('title', $server->name)

@section('content')
    @php
        $latest = $server->latestMetric;
        $memoryPercent = $latest && $latest->memory_total_bytes > 0 ? round($latest->memory_used_bytes / $latest->memory_total_bytes * 100, 1) : null;
        $diskTotal = $latest?->diskSamples->sum('total_bytes') ?? 0;
        $diskUsed = $latest?->diskSamples->sum('used_bytes') ?? 0;
        $diskPercent = $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : null;
    @endphp
    <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3 mb-4">
        <div><a class="small text-decoration-none" href="{{ route('servers.index') }}">← All servers</a><p class="small-label mt-3 mb-1">{{ ucfirst($server->platform) }} server</p><h1 class="display-6 fw-bold mb-1">{{ $server->name }}</h1><p class="text-secondary mb-0">{{ $server->hostname ?? 'Hostname pending enrollment' }} · {{ $server->os_version ?? 'OS version pending enrollment' }} · Agent {{ $server->agent_version ?? 'not enrolled' }}</p></div>
        <div class="d-flex flex-wrap gap-2">
            @if ($server->status === 'pending')
                <form method="POST" action="{{ route('servers.enrollment.reissue', $server) }}" onsubmit="return confirm('Create a fresh token and revoke any previous unused enrollment token?')">
                    @csrf<button class="btn btn-outline-primary" type="submit">Reissue enrollment token</button>
                </form>
            @endif
            @if ($server->status !== 'disabled')
                <form method="POST" action="{{ route('servers.disable', $server) }}" onsubmit="return confirm('Disable this server and revoke its agent credentials?')">
                    @csrf<button class="btn btn-outline-danger" type="submit">Disable server</button>
                </form>
            @endif
        </div>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="monitor-card p-4 h-100"><div class="small-label">Status</div><div class="h4 mt-2 mb-0 text-capitalize"><span class="status-dot {{ $server->status }} me-2"></span>{{ $server->status }}</div></div></div>
        <div class="col-6 col-xl-3"><div class="monitor-card p-4 h-100"><div class="small-label">CPU</div><div class="metric-value mt-2">{{ $latest ? number_format((float) $latest->cpu_percent, 1).'%' : '—' }}</div></div></div>
        <div class="col-6 col-xl-3"><div class="monitor-card p-4 h-100"><div class="small-label">Memory</div><div class="metric-value mt-2">{{ $memoryPercent !== null ? $memoryPercent.'%' : '—' }}</div></div></div>
        <div class="col-6 col-xl-3"><div class="monitor-card p-4 h-100"><div class="small-label">Disk</div><div class="metric-value mt-2">{{ $diskPercent !== null ? $diskPercent.'%' : '—' }}</div></div></div>
    </div>
    <section class="monitor-card p-4 mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3"><div><h2 class="h5 fw-bold mb-1">Resource history</h2><p class="small text-secondary mb-0">Latest 24 hours. Charts refresh once per minute.</p></div><span class="small text-secondary">Last check-in {{ $server->last_seen_at?->diffForHumans() ?? 'never' }}</span></div>
        <div class="chart-wrap"><canvas data-metrics-url="{{ route('servers.metrics', $server) }}" aria-label="CPU, memory and disk usage history" role="img"></canvas></div>
        @unless ($latest)<p class="small text-center text-secondary mb-0">No metric samples received yet. Enroll the agent to populate this chart.</p>@endunless
    </section>
    <section class="monitor-card overflow-hidden">
        <div class="px-4 py-3 border-bottom"><h2 class="h5 fw-bold mb-0">Filesystem usage</h2></div>
        @if ($latest && $latest->diskSamples->isNotEmpty())
            <div class="table-responsive"><table class="table align-middle mb-0"><thead class="table-light"><tr><th>Filesystem</th><th>Used</th><th>Total</th><th>Usage</th></tr></thead><tbody>
            @foreach ($latest->diskSamples as $disk)
                <tr><td>{{ $disk->filesystem }}</td><td>{{ number_format($disk->used_bytes / 1073741824, 2) }} GB</td><td>{{ number_format($disk->total_bytes / 1073741824, 2) }} GB</td><td>{{ $disk->total_bytes > 0 ? number_format($disk->used_bytes / $disk->total_bytes * 100, 1).'%' : '—' }}</td></tr>
            @endforeach
            </tbody></table></div>
        @else
            <p class="p-4 text-secondary mb-0">Filesystem details will appear after the agent sends its first sample.</p>
        @endif
    </section>
@endsection
