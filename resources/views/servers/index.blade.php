@extends('layouts.app')

@section('title', 'Servers')

@section('content')
    <div class="d-flex flex-column flex-md-row align-items-md-end justify-content-between gap-3 mb-4">
        <div><p class="small-label mb-2">Inventory</p><h1 class="display-6 fw-bold mb-1">Servers</h1><p class="text-secondary mb-0">Manage enrolled hosts and their agent access.</p></div>
        <a class="btn btn-primary" href="{{ route('servers.create') }}">Add server</a>
    </div>
    <section class="monitor-card overflow-hidden">
        @if ($servers->isEmpty())
            <div class="p-5 text-center"><h2 class="h5 fw-bold">No servers yet</h2><p class="text-secondary">Register a Linux or Windows host to begin monitoring.</p><a class="btn btn-primary" href="{{ route('servers.create') }}">Register server</a></div>
        @else
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Name</th><th>Platform</th><th>Status</th><th>Agent</th><th>Last seen</th><th></th></tr></thead>
                <tbody>
                @foreach ($servers as $server)
                    <tr>
                        <td class="fw-semibold">{{ $server->name }}<div class="small text-secondary">{{ $server->hostname ?? 'Hostname pending' }}</div></td>
                        <td class="text-capitalize">{{ $server->platform }}</td>
                        <td><span class="status-dot {{ $server->status }} me-2"></span><span class="text-capitalize">{{ $server->status }}</span></td>
                        <td>{{ $server->agent_version ?? '—' }}</td>
                        <td class="text-secondary small">{{ $server->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
                        <td><a class="btn btn-sm btn-outline-secondary" href="{{ route('servers.show', $server) }}">Open</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="px-4 py-3">{{ $servers->links() }}</div>
        @endif
    </section>
@endsection
