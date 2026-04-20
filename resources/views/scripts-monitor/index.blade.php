<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scripts Monitor</title>
    <style>
        :root {
            color-scheme: light dark;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            margin: 20px;
        }
        h1 {
            margin-bottom: 8px;
        }
        .subtitle {
            margin-bottom: 16px;
            opacity: 0.8;
        }
        .cards {
            display: grid;
            grid-template-columns: repeat(6, minmax(110px, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }
        .card {
            border: 1px solid #7774;
            border-radius: 8px;
            padding: 10px;
        }
        .card strong {
            display: block;
            font-size: 20px;
            margin-top: 4px;
        }
        .toolbar {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        select, button, a.btn {
            padding: 6px 10px;
            border: 1px solid #7777;
            border-radius: 6px;
            background: transparent;
            color: inherit;
            text-decoration: none;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        th, td {
            border: 1px solid #7774;
            padding: 8px;
            vertical-align: top;
        }
        th {
            text-align: left;
            white-space: nowrap;
        }
        .status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            border: 1px solid #7777;
            font-size: 12px;
            text-transform: uppercase;
        }
        .script-cell {
            max-width: 340px;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .error-cell {
            max-width: 260px;
            white-space: pre-wrap;
            word-break: break-word;
            color: #ff7676;
        }
        .pagination {
            margin-top: 14px;
        }
    </style>
    <meta http-equiv="refresh" content="20">
</head>
<body>
<h1>Scripts Monitor</h1>
<div class="subtitle">Auto-refresh every 20 seconds.</div>

<div class="cards">
    <div class="card">All <strong>{{ $counts['all'] }}</strong></div>
    <div class="card">Pending <strong>{{ $counts['pending'] }}</strong></div>
    <div class="card">Generating <strong>{{ $counts['generating'] }}</strong></div>
    <div class="card">Publishing <strong>{{ $counts['publishing'] }}</strong></div>
    <div class="card">Done <strong>{{ $counts['done'] }}</strong></div>
    <div class="card">Error <strong>{{ $counts['error'] }}</strong></div>
</div>

<form method="GET" action="{{ route('scripts.monitor') }}" class="toolbar">
    <label for="status">Filter status:</label>
    <select id="status" name="status">
        <option value="" @selected($status === '')>All</option>
        <option value="pending" @selected($status === 'pending')>pending</option>
        <option value="generating" @selected($status === 'generating')>generating</option>
        <option value="publishing" @selected($status === 'publishing')>publishing</option>
        <option value="done" @selected($status === 'done')>done</option>
        <option value="error" @selected($status === 'error')>error</option>
    </select>
    <button type="submit">Apply</button>
    <a class="btn" href="{{ route('scripts.monitor') }}">Reset</a>
</form>

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
            <td><span class="status">{{ $script->status }}</span></td>
            <td class="script-cell">{{ $script->script }}</td>
            <td>{{ $script->video_id ?: '-' }}</td>
            <td>
                @if($script->video_url)
                    <a href="{{ $script->video_url }}" target="_blank" rel="noopener noreferrer">Open video</a>
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

<div class="pagination">
    {{ $scripts->links() }}
</div>
</body>
</html>

