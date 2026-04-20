<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scripts Monitor</title>
    <meta http-equiv="refresh" content="20">
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

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
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

        .script-cell,
        .error-cell {
            max-width: 300px;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .error-cell {
            color: #fca5a5;
        }

        .pagination {
            margin-top: 14px;
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
            <div class="subtitle">Auto-refresh every 20 seconds.</div>
        </div>
        <button type="button" class="btn btn-primary" id="toggle-create-form">+ Add Script</button>
    </div>

    @if(session('success'))
        <div class="flash flash-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="flash flash-error">{{ $errors->first() }}</div>
    @endif

    <div class="create-form panel @if($errors->any()) show @endif" id="create-form">
        <form method="POST" action="{{ route('scripts.monitor.store') }}">
            @csrf
            <label for="script">Script content (required)</label>
            <textarea id="script" name="script" required>{{ old('script') }}</textarea>
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
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Status</th>
                    <th>Script</th>
                    <th>Video ID</th>
                    <th>Video URL</th>
                    <th>Poll</th>
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
                        <td class="script-cell">{{ $script->script }}</td>
                        <td>{{ $script->video_id ?: '-' }}</td>
                        <td>
                            @if($script->video_url)
                                <a class="btn btn-ghost" href="{{ $script->video_url }}" target="_blank" rel="noopener noreferrer">Open video</a>
                            @else
                                -
                            @endif
                        </td>
                        <td>{{ $script->poll_attempts }}</td>
                        <td>{{ optional($script->start_date)->toDateTimeString() ?: '-' }}</td>
                        <td>{{ optional($script->finish_date)->toDateTimeString() ?: '-' }}</td>
                        <td class="error-cell">{{ $script->error ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">No script rows found.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="pagination">
        {{ $scripts->links() }}
    </div>
</div>

<script>
    const createForm = document.getElementById('create-form');
    const toggleButton = document.getElementById('toggle-create-form');
    const cancelButton = document.getElementById('cancel-create-form');

    toggleButton.addEventListener('click', () => {
        createForm.classList.toggle('show');
    });

    cancelButton.addEventListener('click', () => {
        createForm.classList.remove('show');
    });
</script>
</body>
</html>

