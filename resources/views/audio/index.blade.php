@extends('layouts.app')
@section('content')
<h1 class="vb-title mb-5">Audio files</h1>
<form method="POST" action="{{ route('audio.store') }}" enctype="multipart/form-data" class="vb-card mb-5">@csrf
    <div class="card-body flex-col gap-2 p-4 sm:flex-row sm:items-center">
        <input type="file" name="audio" accept=".wav,.mp3" required class="file-input file-input-bordered w-full">
        <button class="btn btn-primary"><i class="bi bi-cloud-arrow-up"></i>Upload</button>
    </div>
</form>
@php $pending = $audios->filter(fn ($a) => in_array($a->status, ['UPLOADED', 'PROCESSING']))->pluck('id'); @endphp
<div id="audio-table" class="vb-card overflow-x-auto p-2" data-status-url="{{ route('audio.status') }}" data-pending="{{ $pending->implode(',') }}">
    <table class="vb-table">
        <thead><tr><th>Name</th><th>Status</th><th>Duration</th><th>Play</th><th>Replace</th>@if(auth()->user()->isManager())<th></th>@endif</tr></thead>
        <tbody>
        @forelse($audios as $a)
            <tr>
                <td class="font-medium">{{ $a->original_name }}</td>
                <td><x-status :value="$a->status" />@if(in_array($a->status, ['UPLOADED', 'PROCESSING']))<span class="loading loading-spinner loading-xs ml-1 align-middle text-primary" title="Processing, this updates by itself"></span>@endif <span class="text-xs text-error">{{ $a->error }}</span></td>
                <td class="whitespace-nowrap tabular-nums" @if($a->duration) title="Exact length: {{ $a->duration }} sec" @endif>@if($a->duration){{ intdiv((int) $a->duration, 60) }}:{{ str_pad((string) ((int) $a->duration % 60), 2, '0', STR_PAD_LEFT) }}@else<span class="text-base-content/50">-</span>@endif</td>
                <td>@if($a->isReady())<audio controls preload="none" src="{{ route('audio.stream', $a) }}"></audio>@endif</td>
                <td>@if($a->user_id === auth()->id())<form method="POST" action="{{ route('audio.replace', $a) }}" enctype="multipart/form-data" class="flex min-w-64 gap-2">@csrf<input type="file" name="audio" accept=".wav,.mp3" required class="file-input file-input-bordered file-input-xs w-full"><button class="btn btn-outline btn-primary btn-xs">Replace</button></form>@endif</td>
                @if(auth()->user()->isManager())<td>@can('delete', $a)<form method="POST" action="{{ route('audio.destroy', $a) }}" onsubmit="return confirm('Delete this audio file permanently?')">@csrf @method('DELETE')<button class="btn btn-outline btn-error btn-xs"><i class="bi bi-trash"></i>Delete</button></form>@endcan</td>@endif
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-center text-base-content/75">No audio files yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $audios->links() }}</div>
<script>
// While a file is still being processed, check every 2 seconds and refresh the list by itself once it is ready.
(() => {
    const box = document.getElementById('audio-table');
    const watch = () => {
        const ids = (box.dataset.pending || '').split(',').filter(Boolean);
        if (!ids.length) return;
        let tries = 0;
        const timer = setInterval(async () => {
            if (++tries > 150) { clearInterval(timer); return; } // give up after ~5 minutes
            try {
                const r = await fetch(box.dataset.statusUrl + '?ids=' + ids.join(','), { headers: { Accept: 'application/json' } });
                if (!r.ok) return;
                const st = await r.json();
                if (ids.some(id => !['UPLOADED', 'PROCESSING'].includes(st[id]))) {
                    clearInterval(timer);
                    const html = await (await fetch(location.href, { headers: { Accept: 'text/html' } })).text();
                    const fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('audio-table');
                    if (!fresh) { location.reload(); return; }
                    box.innerHTML = fresh.innerHTML;
                    box.dataset.pending = fresh.dataset.pending || '';
                    watch(); // other files may still be processing
                }
            } catch (e) { /* network hiccup: try again on the next tick */ }
        }, 2000);
    };
    watch();
})();
</script>
@endsection
