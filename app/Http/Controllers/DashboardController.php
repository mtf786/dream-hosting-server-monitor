<?php

namespace App\Http\Controllers;

use App\Models\Server;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $servers = Server::query()
            ->where('user_id', auth()->id())
            ->with('latestMetric.diskSamples')
            ->orderBy('name')
            ->paginate(12);

        $totalServers = Server::where('user_id', auth()->id())->count();
        $onlineServers = Server::where('user_id', auth()->id())->where('status', 'online')->count();
        $offlineServers = Server::where('user_id', auth()->id())->where('status', 'offline')->count();

        return view('dashboard', compact('servers', 'totalServers', 'onlineServers', 'offlineServers'));
    }
}
