{{-- Plain text cell: fixed max height + optional Expand (shown only when content overflows). --}}
@php
    $text = (string) ($content ?? '');
@endphp
<div class="cell-clamp" data-cell-clamp>
    <div class="cell-clamp-inner">{{ $text !== '' ? $text : '—' }}</div>
    <button type="button" class="btn btn-ghost expand-cell-btn" data-cell-expand hidden>Expand</button>
</div>
