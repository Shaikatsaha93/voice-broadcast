@extends('layouts.app')
@section('content')
<div class="mb-5 flex items-center justify-between gap-3">
    <h1 class="vb-title">Campaigns</h1>
    <a class="btn btn-primary btn-sm sm:btn-md" href="{{ route('campaigns.create') }}"><i class="bi bi-plus-lg"></i>New campaign</a>
</div>
<div class="vb-card overflow-x-auto p-2">
    <table class="vb-table">
        <thead><tr><th>Name</th><th>Owner</th><th>DID</th><th>Status</th><th>Created</th><th>Scheduled start</th><th></th></tr></thead>
        <tbody>
        @forelse($campaigns as $c)
            <tr><td class="font-medium">{{ $c->name }}</td><td>{{ $c->user->name }}</td><td>{{ $c->did->number }}</td><td><x-status :value="$c->status->value" /></td><td class="whitespace-nowrap text-sm text-base-content/75">{{ $c->created_at }}</td><td class="whitespace-nowrap text-sm">@if($c->scheduled_at)<span class="{{ $c->status->value === 'QUEUED' ? 'font-semibold text-primary' : 'text-base-content/75' }}"><i class="bi bi-clock"></i> {{ $c->scheduled_at->format('Y-m-d H:i:s') }}</span>@else<span class="text-base-content/50">Manual start</span>@endif</td><td class="text-right"><a class="btn btn-outline btn-primary btn-xs" href="{{ route('campaigns.show', $c) }}">Open</a></td></tr>
        @empty
            <tr><td colspan="7" class="py-6 text-center text-base-content/75">No campaigns yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $campaigns->links() }}</div>
@endsection
