@extends('layouts.app')
@section('content')
<h1 class="vb-title mb-5">Pending approvals</h1>
<div class="space-y-4">
@forelse($campaigns as $c)
<div class="vb-card"><div class="card-body gap-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div><span class="text-lg font-semibold">{{ $c->name }}</span> <span class="text-base-content/75">by {{ $c->user->name }}</span></div>
        <span class="text-xs text-base-content/75">created {{ $c->created_at }}</span>
    </div>
    @php $start = $c->scheduled_at; @endphp
    <div class="grid grid-cols-2 gap-3 text-sm md:grid-cols-3 lg:grid-cols-5">
        <div class="rounded-lg bg-primary/10 p-2 -m-2">
            <div class="text-base-content/75"><i class="bi bi-clock"></i> Start time</div>
            @if($start && $start->isFuture())
                <div class="font-semibold">{{ $start->format('Y-m-d H:i') }}</div>
                <div class="text-xs text-base-content/75">{{ $start->diffForHumans() }} &middot; {{ config('app.timezone') }}</div>
            @elseif($start)
                <div class="font-semibold">{{ $start->format('Y-m-d H:i') }}</div>
                <div class="text-xs"><span class="badge badge-warning badge-soft badge-sm">time passed</span> starts immediately on approval</div>
            @else
                <div class="font-semibold">Immediately</div>
                <div class="text-xs text-base-content/75">no schedule set: starts when approved</div>
            @endif
        </div>
        <div><div class="text-base-content/75">DID</div><div class="font-semibold">{{ $c->did->number }} (max {{ $c->did->max_concurrent_calls }})</div></div>
        <div><div class="text-base-content/75">Requested concurrency</div><div class="font-semibold">{{ $c->requested_concurrency }}</div></div>
        <div><div class="text-base-content/75">Numbers</div><div class="font-semibold">{{ $c->recipients_count }}</div></div>
        <div><div class="text-base-content/75">Max attempts</div><div class="font-semibold">{{ $c->max_attempts }} (delay {{ $c->retry_delay_seconds }}s)</div></div>
    </div>
    @php $audioReady = $c->audio?->isReady(); @endphp
    @if($c->audio)
        <div class="rounded-box border border-base-300 bg-base-200/40 p-3">
            <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="font-semibold"><i class="bi bi-music-note-beamed"></i> Audio</span>
                <span class="text-base-content/75">{{ $c->audio->original_name }}</span>
                <x-status :value="$c->audio->status" />
                @if(! $audioReady && in_array($c->audio->status, ['UPLOADED', 'PROCESSING']))<span class="loading loading-spinner loading-xs" data-audio-pending="{{ $c->audio->id }}"></span>@endif
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if($audioReady)<audio controls preload="none" src="{{ route('audio.stream', $c->audio) }}"></audio>@endif
                @can('download', $c->audio)<a href="{{ route('audio.download', $c->audio) }}" class="btn btn-ghost btn-xs"><i class="bi bi-download"></i> Download original</a>@endcan
            </div>
            @can('replace', $c->audio)
                <form method="POST" action="{{ route('audio.replace', $c->audio) }}" enctype="multipart/form-data" class="join mt-3 w-full max-w-xl">@csrf
                    <input type="file" name="audio" accept=".wav,.mp3" required class="file-input file-input-bordered file-input-sm join-item w-full">
                    <button class="btn btn-outline btn-primary btn-sm join-item whitespace-nowrap"><i class="bi bi-arrow-repeat"></i> Replace audio</button>
                </form>
                <p class="mt-1 text-xs text-base-content/70">Replaces this user's audio file (it is converted again, then you can approve). Other campaigns of this user that use the same file get the new audio too.</p>
            @endcan
        </div>
    @else
        <div role="alert" class="alert alert-warning alert-soft"><i class="bi bi-exclamation-triangle"></i><span>This campaign has no audio file.</span></div>
    @endif
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
        <form method="POST" action="{{ route('admin.approvals.approve', $c) }}">@csrf<button class="btn btn-success text-white" @disabled(! $audioReady) @if(! $audioReady) title="Wait until the audio is READY" @endif><i class="bi bi-check-lg"></i>Approve</button></form>
        <form method="POST" action="{{ route('admin.approvals.reject', $c) }}" class="join w-full sm:w-auto">@csrf<input name="reason" required placeholder="Rejection reason" class="input join-item w-full"><button class="btn btn-error join-item text-white"><i class="bi bi-x-lg"></i>Reject</button></form>
    </div>
</div></div>
@empty
<div class="vb-card"><div class="card-body items-center text-base-content/75">No campaigns waiting.</div></div>
@endforelse
</div>
<div class="mt-4">{{ $campaigns->links() }}</div>
<script>
// A replaced audio file is converted in the background. Check every 2 seconds and reload once it is ready (or failed).
(() => {
    const ids = [...document.querySelectorAll('[data-audio-pending]')].map(el => el.dataset.audioPending);
    if (!ids.length) return;
    let tries = 0;
    const timer = setInterval(async () => {
        if (++tries > 150) { clearInterval(timer); return; } // stop after ~5 minutes
        try {
            const r = await fetch(@json(route('audio.status')) + '?ids=' + ids.join(','), { headers: { Accept: 'application/json' } });
            if (!r.ok) return;
            const st = await r.json();
            if (ids.some(id => !['UPLOADED', 'PROCESSING'].includes(st[id]))) { clearInterval(timer); location.reload(); }
        } catch (e) { /* network hiccup: try again on the next tick */ }
    }, 2000);
})();
</script>
@endsection
