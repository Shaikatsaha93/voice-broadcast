@extends('layouts.app')
@section('content')
<div class="mb-5 flex items-center justify-between gap-3">
    <h1 class="vb-title">DIDs</h1>
    <div class="flex gap-2">
        <a class="btn btn-primary btn-sm sm:btn-md" href="{{ route('admin.dids.create') }}"><i class="bi bi-plus-lg"></i>New DID</a>
    </div>
</div>
<div class="vb-card overflow-x-auto p-2">
    <table class="vb-table">
        <thead><tr><th>Number</th><th>Label</th><th>Status</th><th>SIP</th><th class="text-right">Balance</th><th>Active / Max</th><th>Users</th><th></th></tr></thead>
        <tbody>
        @foreach($dids as $d)
            <tr><td class="font-medium">{{ $d->number }}</td><td>{{ $d->label }}</td><td><x-status :value="$d->status" /></td><td>@if($d->hasSip())<span title="{{ $d->sip_status_detail }}"><x-status :value="$d->sip_status ?? 'pending'" /></span>@else<span class="text-base-content/50">-</span>@endif</td><td class="text-right tabular-nums">{{ \App\Support\Money::tk($d->balance) }}@unless($d->canAffordAnotherCall()) <span class="badge badge-error badge-soft badge-sm">LOW</span>@endunless</td><td>{{ $d->active_calls }} / {{ $d->max_concurrent_calls }}</td><td>{{ $d->users->pluck('name')->join(', ') }}</td><td class="text-right"><a class="btn btn-outline btn-primary btn-xs" href="{{ route('admin.dids.show', $d) }}">Manage</a></td></tr>
        @endforeach
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $dids->links() }}</div>
@endsection
