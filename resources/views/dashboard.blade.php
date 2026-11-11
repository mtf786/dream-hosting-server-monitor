@extends('layouts.app')

@section('title', 'Overview')

@section('content')
    <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3 mb-4">
        <div>
            <p class="small-label mb-2">Fleet overview</p>
            <h1 class="display-6 fw-bold mb-1">Your servers at a glance</h1>
            <p class="text-secondary mb-0">Latest CPU, memory, disk, and heartbeat data from your agents.</p>
        </div>
        <a class="btn btn-primary btn-lg" href="{{ route('servers.create') }}">Add a server</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4"><div class="monitor-card p-4 h-100"><div class="small-label">Registered servers</div><div class="metric-value mt-2">{{ $totalServers }}</div></div></div>
        <div class="col-12 col-md-4"><div class="monitor-card p-4 h-100"><div class="small-label">Online</div><div class="metric-value mt-2 text-success">{{ $onlineServers }}</div></div></div>
        <div class="col-12 col-md-4"><div class="monitor-card p-4 h-100"><div class="small-label">Offline</div><div class="metric-value mt-2 {{ $offlineServers ? 'text-danger' : '' }}">{{ $offlineServers }}</div></div></div>
    </div>

    <section class="monitor-card overflow-hidden">
        <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom">
            <div><h2 class="h5 fw-bold mb-1">Monitored servers</h2><p class="small text-secondary mb-0">Server status changes after three missed one-minute check-ins.</p></div>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('servers.index') }}">View all</a>
        </div>
        @if ($servers->isEmpty())
            <div class="text-center px-4 py-5">
                <div class="app-brand-mark mx-auto mb-3">+</div>
                <h3 class="h5 fw-bold">No servers registered yet</h3>
                <p class="text-secondary">Register a Linux or Windows server to generate a one-time enrollment token.</p>
                <a class="btn btn-primary" href="{{ route('servers.create') }}">Register your first server</a>
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Server</th><th>Status</th><th>CPU</th><th>Memory</th><th>Disk</th><th>Last seen</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($servers as $server)
                        @php
                            $latest = $server->latestMetric;
                            $memoryPercent = $latest && $latest->memory_total_bytes > 0 ? round($latest->memory_used_bytes / $latest->memory_total_bytes * 100, 1) : null;
                            $diskTotal = $latest?->diskSamples->sum('total_bytes') ?? 0;
                            $diskUsed = $latest?->diskSamples->sum('used_bytes') ?? 0;
                            $diskPercent = $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : null;
                        @endphp
                        <tr>
                            <td><a class="fw-semibold text-decoration-none" href="{{ route('servers.show', $server) }}">{{ $server->name }}</a><div class="small text-secondary">{{ $server->hostname ?? ucfirst($server->platform) }}</div></td>
                            <td><span class="status-dot {{ $server->status }} me-2"></span><span class="text-capitalize">{{ $server->status }}</span></td>
                            <td>{{ $latest ? number_format((float) $latest->cpu_percent, 1).'%' : '—' }}</td>
                            <td>{{ $memoryPercent !== null ? $memoryPercent.'%' : '—' }}</td>
                            <td>{{ $diskPercent !== null ? $diskPercent.'%' : '—' }}</td>
                            <td class="text-secondary small">{{ $server->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
                            <td><a class="btn btn-sm btn-light" href="{{ route('servers.show', $server) }}">Details</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3">{{ $servers->links() }}</div>
        @endif
    </section>
@endsection
