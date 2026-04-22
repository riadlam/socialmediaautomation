<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scripts Monitor</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #0f172a;
            --panel: #111827;
            --border: #334155;
            --text: #e5e7eb;
            --muted: #94a3b8;
            --accent: #22c55e;
            --danger: #ef4444;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .container {
            max-width: 1320px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .title {
            margin: 0;
            font-size: 24px;
        }

        .subtitle {
            margin-top: 6px;
            color: var(--muted);
            font-size: 14px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(6, minmax(120px, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .card {
            background: #0b1220;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px;
            color: var(--muted);
            font-size: 13px;
        }

        .card strong {
            display: block;
            color: var(--text);
            font-size: 24px;
            margin-top: 4px;
        }

        .actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .btn,
        button,
        select,
        textarea {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #0b1220;
            color: var(--text);
            font-size: 14px;
        }

        .btn,
        button,
        select {
            padding: 8px 12px;
            text-decoration: none;
            cursor: pointer;
        }

        .btn-primary {
            background: #14532d;
            border-color: #166534;
        }

        .btn-ghost {
            background: transparent;
        }

        textarea {
            width: 100%;
            min-height: 120px;
            padding: 10px;
            resize: vertical;
        }

        .field-group {
            margin-top: 12px;
        }

        .field-group label {
            display: block;
            margin-bottom: 6px;
        }

        .field-hint {
            font-size: 12px;
            color: var(--muted);
            margin-top: 4px;
        }

        .create-form {
            display: none;
            margin-bottom: 14px;
        }

        .create-form.show {
            display: block;
        }

        .create-actions {
            margin-top: 10px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .flash {
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 12px;
            border: 1px solid;
        }

        .flash-success {
            background: #052e16;
            border-color: #166534;
            color: #86efac;
        }

        .flash-error {
            background: #450a0a;
            border-color: #991b1b;
            color: #fca5a5;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table.scripts-monitor-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            table-layout: fixed;
        }

        th, td {
            border-bottom: 1px solid var(--border);
            padding: 10px 8px;
            text-align: left;
            vertical-align: top;
        }

        th {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }

        .status {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: #1f2937;
            font-size: 12px;
            text-transform: uppercase;
        }

        .status.done {
            border-color: #166534;
            color: #86efac;
        }

        .status.error {
            border-color: #991b1b;
            color: #fca5a5;
        }

        .td-clamp {
            vertical-align: top;
            max-width: 14rem;
        }

        .td-clamp-wide {
            max-width: 18rem;
        }

        .cell-clamp {
            max-height: 5.25rem;
            overflow: hidden;
            border-radius: 4px;
        }

        .cell-clamp.is-expanded {
            max-height: min(42vh, 22rem);
            overflow-y: auto;
            border: 1px solid var(--border);
            padding: 6px;
            background: #0b1220;
        }

        .cell-clamp-inner {
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 13px;
            line-height: 1.35;
        }

        .expand-cell-btn {
            margin-top: 6px;
            padding: 3px 8px;
            font-size: 11px;
        }

        .error-cell .cell-clamp-inner {
            color: #fca5a5;
        }

        .pagination {
            margin-top: 14px;
        }

        .logs-panel {
            margin-top: 14px;
        }

        .logs-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
        }

        .logs-panel-header h2 {
            margin: 0;
        }

        .logs-list {
            display: grid;
            gap: 8px;
        }

        .log-item {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px;
            background: #0b1220;
            font-size: 13px;
        }

        .log-head {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            color: var(--muted);
            margin-bottom: 5px;
        }

        .log-level {
            padding: 2px 8px;
            border-radius: 999px;
            border: 1px solid var(--border);
            text-transform: uppercase;
            font-size: 11px;
            color: #bae6fd;
        }

        .log-level.error {
            color: #fca5a5;
            border-color: #991b1b;
        }

        @media (max-width: 900px) {
            .cards {
                grid-template-columns: repeat(2, minmax(130px, 1fr));
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1 class="title">Scripts Monitor</h1>
            <div class="subtitle">Refresh the page manually to see the latest status.</div>
        </div>
        <button type="button" class="btn btn-primary" id="toggle-create-form">+ Add Script</button>
    </div>

    @if(session('success'))
        <div class="flash flash-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="flash flash-error">
            <strong>Could not save:</strong>
            <ul style="margin: 8px 0 0 18px;">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="create-form panel @if($errors->any() || filled(old('script')) || filled(old('caption'))) show @endif" id="create-form">
        <form method="POST" action="{{ route('scripts.monitor.store') }}">
            @csrf
            <label for="script">HeyGen Video Agent prompt</label>
            <textarea id="script" name="script" required rows="14" placeholder="Paste the full `prompt` you would send in Postman (niche, style, rules, voiceover lines — everything in one field).">{{ old('script') }}</textarea>
            <div class="field-hint">Stored as <code>scripts.script</code> and sent as HeyGen <code>prompt</code> only (line breaks normalized to <code>\n</code> like a Postman JSON string). Request body uses the same keys as Postman: <code>prompt</code>, <code>mode</code>, <code>orientation</code>, <code>incognito_mode</code>, plus <code>avatar_id</code> / <code>voice_id</code> from env when set. Not the Zerno caption.</div>

            <div class="field-group">
                <label for="caption">Post caption (Zerno)</label>
                <textarea id="caption" name="caption" required placeholder="Short caption shown on TikTok / Instagram…">{{ old('caption') }}</textarea>
                <div class="field-hint">Published as Zerno <code>content</code> (not the video script above).</div>
            </div>

            <div class="field-group">
                <label for="hashtags">Hashtags (optional)</label>
                <textarea id="hashtags" name="hashtags" rows="3" placeholder="#growth, #mindset (or one tag per line)">{{ old('hashtags') }}</textarea>
                <div class="field-hint">One per line or comma-separated. Sent as Zerno <code>hashtags</code> array; <code>#</code> is added if missing.</div>
            </div>

            <div class="create-actions">
                <button type="submit" class="btn btn-primary">Queue Script</button>
                <button type="button" class="btn btn-ghost" id="cancel-create-form">Cancel</button>
            </div>
        </form>
    </div>

    <div class="cards">
        <div class="card">All <strong>{{ $counts['all'] }}</strong></div>
        <div class="card">Pending <strong>{{ $counts['pending'] }}</strong></div>
        <div class="card">Generating <strong>{{ $counts['generating'] }}</strong></div>
        <div class="card">Publishing <strong>{{ $counts['publishing'] }}</strong></div>
        <div class="card">Done <strong>{{ $counts['done'] }}</strong></div>
        <div class="card">Error <strong>{{ $counts['error'] }}</strong></div>
    </div>

    <div class="panel">
        <form method="GET" action="{{ route('scripts.monitor') }}" class="actions">
            <label for="status">Status:</label>
            <select id="status" name="status">
                <option value="" @selected($status === '')>All</option>
                <option value="pending" @selected($status === 'pending')>pending</option>
                <option value="generating" @selected($status === 'generating')>generating</option>
                <option value="publishing" @selected($status === 'publishing')>publishing</option>
                <option value="done" @selected($status === 'done')>done</option>
                <option value="error" @selected($status === 'error')>error</option>
            </select>
            <button type="submit">Apply</button>
            <a class="btn btn-ghost" href="{{ route('scripts.monitor') }}">Reset</a>
        </form>

        <div class="table-wrap">
            <table class="scripts-monitor-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Status</th>
                    <th>Script</th>
                    <th>Caption</th>
                    <th>Tags</th>
                    <th>Video ID</th>
                    <th>Video URL</th>
                    <th>Published On</th>
                    <th>Poll</th>
                    <th>Last Poll</th>
                    <th>Start</th>
                    <th>Finish</th>
                    <th>Error</th>
                </tr>
                </thead>
                <tbody>
                @forelse($scripts as $script)
                    <tr>
                        <td>{{ $script->id }}</td>
                        <td>
                            <span class="status {{ $script->status === 'done' ? 'done' : '' }} {{ $script->status === 'error' ? 'error' : '' }}">
                                {{ $script->status }}
                            </span>
                        </td>
                        <td class="td-clamp td-clamp-wide">
                            @include('scripts-monitor._clamped', ['content' => (string) $script->script])
                        </td>
                        <td class="td-clamp">
                            @include('scripts-monitor._clamped', ['content' => (string) ($script->caption ?? '')])
                        </td>
                        <td class="td-clamp">
                            @php
                                $tagsStr = (is_array($script->hashtags) && count($script->hashtags) > 0)
                                    ? implode(' ', $script->hashtags)
                                    : '';
                            @endphp
                            @include('scripts-monitor._clamped', ['content' => $tagsStr])
                        </td>
                        <td class="td-clamp">
                            @include('scripts-monitor._clamped', ['content' => (string) ($script->video_id ?: '')])
                        </td>
                        <td class="td-clamp">
                            @if($script->video_url)
                                <a class="btn btn-ghost" href="{{ $script->video_url }}" target="_blank" rel="noopener noreferrer">Open video</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="td-clamp">
                            @include('scripts-monitor._clamped', ['content' => (string) ($script->published_platform ?? '')])
                        </td>
                        <td>{{ $script->poll_attempts }}</td>
                        <td>{{ optional($script->last_polled_at)->toDateTimeString() ?: '-' }}</td>
                        <td>{{ optional($script->start_date)->toDateTimeString() ?: '-' }}</td>
                        <td>{{ optional($script->finish_date)->toDateTimeString() ?: '-' }}</td>
                        <td class="td-clamp td-clamp-wide error-cell">
                            @include('scripts-monitor._clamped', ['content' => (string) ($script->error ?? '')])
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="13">No script rows found.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel logs-panel">
        <div class="logs-panel-header">
            <h2>Recent Updates</h2>
            <form method="POST" action="{{ route('scripts.monitor.clear-logs') }}" onsubmit="return confirm('Clear all recent updates from the log?');">
                @csrf
                <button type="submit" class="btn btn-ghost">Clear log</button>
            </form>
        </div>
        <div class="logs-list">
            @forelse($recentLogs as $log)
                <div class="log-item">
                    <div class="log-head">
                        <span>{{ $log->created_at?->toDateTimeString() }}</span>
                        <span>Script #{{ $log->script_id ?: '-' }}</span>
                        <span>Stage: {{ $log->stage }}</span>
                        <span class="log-level {{ $log->level === 'error' ? 'error' : '' }}">{{ $log->level }}</span>
                    </div>
                    <div class="log-message-wrap">
                        @include('scripts-monitor._clamped', ['content' => (string) $log->message])
                    </div>
                </div>
            @empty
                <div class="log-item">No updates yet.</div>
            @endforelse
        </div>
    </div>

    <div class="pagination">
        {{ $scripts->links() }}
    </div>
</div>

<script>
    function initCellClamps() {
        document.querySelectorAll('[data-cell-clamp]').forEach((clamp) => {
            const inner = clamp.querySelector('.cell-clamp-inner');
            const btn = clamp.querySelector('[data-cell-expand]');
            if (!inner || !btn) {
                return;
            }
            const measure = () => {
                const expanded = clamp.classList.contains('is-expanded');
                if (expanded) {
                    btn.hidden = false;
                    return;
                }
                const needs = inner.scrollHeight > clamp.clientHeight + 2;
                btn.hidden = !needs;
            };
            requestAnimationFrame(measure);
        });

        document.querySelectorAll('[data-cell-expand]').forEach((btn) => {
            if (btn.dataset.cellExpandBound === '1') {
                return;
            }
            btn.dataset.cellExpandBound = '1';
            btn.addEventListener('click', () => {
                const clamp = btn.closest('[data-cell-clamp]');
                if (!clamp) {
                    return;
                }
                const expanded = clamp.classList.toggle('is-expanded');
                btn.textContent = expanded ? 'Collapse' : 'Expand';
                btn.hidden = false;
            });
        });
    }

    const createForm = document.getElementById('create-form');
    const toggleButton = document.getElementById('toggle-create-form');
    const cancelButton = document.getElementById('cancel-create-form');

    toggleButton.addEventListener('click', () => {
        createForm.classList.toggle('show');
    });

    cancelButton.addEventListener('click', () => {
        createForm.classList.remove('show');
    });

    initCellClamps();
</script>
</body>
</html>

