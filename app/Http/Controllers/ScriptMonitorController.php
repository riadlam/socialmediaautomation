<?php

namespace App\Http\Controllers;

use App\Models\Script;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ScriptMonitorController extends Controller
{
    public function index(Request $request): View
    {
        $status = trim((string) $request->query('status', ''));

        $query = Script::query()->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        $scripts = $query->paginate(25)->withQueryString();

        $counts = Script::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('scripts-monitor.index', [
            'scripts' => $scripts,
            'status' => $status,
            'counts' => [
                'all' => Script::query()->count(),
                'pending' => (int) ($counts['pending'] ?? 0),
                'generating' => (int) ($counts['generating'] ?? 0),
                'publishing' => (int) ($counts['publishing'] ?? 0),
                'done' => (int) ($counts['done'] ?? 0),
                'error' => (int) ($counts['error'] ?? 0),
            ],
        ]);
    }
}

