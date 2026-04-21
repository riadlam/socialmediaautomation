<?php

namespace App\Http\Controllers;

use App\Models\Script;
use App\Models\ScriptLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ScriptMonitorController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'script' => ['required', 'string', 'max:65535'],
        ]);

        Script::query()->create([
            'script' => trim($validated['script']),
            'status' => 'pending',
            'start_date' => null,
            'finish_date' => null,
            'heygen_session_id' => null,
            'video_id' => null,
            'video_url' => null,
            'poll_attempts' => 0,
            'error' => null,
            'publish_response' => null,
        ]);

        return redirect()
            ->route('scripts.monitor')
            ->with('success', 'Script queued successfully.');
    }

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
            'recentLogs' => ScriptLog::query()
                ->latest('id')
                ->limit(30)
                ->get(),
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

