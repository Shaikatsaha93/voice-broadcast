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
    <div class="grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
        <div><div class="text-base-content/75">DID</div><div class="font-semibold">{{ $c->did->number }} (max {{ $c->did->max_concurrent_calls }})</div></div>
        <div><div class="text-base-content/75">Requested concurrency</div><div class="font-semibold">{{ $c->requested_concurrency }}</div></div>
        <div><div class="text-base-content/75">Numbers</div><div class="font-semibold">{{ $c->recipients_count }}</div></div>
        <div><div class="text-base-content/75">Max attempts</div><div class="font-semibold">{{ $c->max_attempts }} (delay {{ $c->retry_delay_seconds }}s)</div></div>
    </div>
    @if($c->audio)<audio controls preload="none" src="{{ route('audio.stream', $c->audio) }}"></audio>@endif
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
        <form method="POST" action="{{ route('admin.approvals.approve', $c) }}">@csrf<button class="btn btn-success text-white"><i class="bi bi-check-lg"></i>Approve</button></form>
        <form method="POST" action="{{ route('admin.approvals.reject', $c) }}" class="join w-full sm:w-auto">@csrf<input name="reason" required placeholder="Rejection reason" class="input join-item w-full"><button class="btn btn-error join-item text-white"><i class="bi bi-x-lg"></i>Reject</button></form>
    </div>
</div></div>
@empty
<div class="vb-card"><div class="card-body items-center text-base-content/75">No campaigns waiting.</div></div>
@endforelse
</div>
<div class="mt-4">{{ $campaigns->links() }}</div>
@endsection
