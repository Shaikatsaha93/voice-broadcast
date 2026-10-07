@extends('layouts.app')
@section('content')
<div class="mb-4">
    <h1 class="vb-title">{{ $did->number }}</h1>
    <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-base-content/75">{{ $did->label }} · <x-status :value="$did->status" /> · {{ $active }}/{{ $did->max_concurrent_calls }} active</div>
</div>
<div class="mb-6 flex flex-wrap gap-2">
    <a class="btn btn-outline btn-sm" href="{{ route('admin.dids.edit', $did) }}"><i class="bi bi-pencil"></i>Edit / concurrency</a>
    <form method="POST" action="{{ route('admin.dids.toggle', $did) }}">@csrf<button class="btn btn-outline btn-sm">{{ $did->status === 'active' ? 'Deactivate' : 'Activate' }}</button></form>
    <form method="POST" action="{{ route('admin.dids.destroy', $did) }}" onsubmit="return confirm('Delete DID?')">@csrf @method('DELETE')<button class="btn btn-outline btn-error btn-sm"><i class="bi bi-trash"></i>Delete</button></form>
</div>
<h2 class="mb-2 font-semibold">Assigned users</h2>
<ul class="vb-card mb-4 divide-y divide-base-200">
    @forelse($did->users as $u)
        <li class="flex items-center justify-between gap-2 p-3">
            <span>{{ $u->name }} <span class="text-sm text-base-content/75">({{ $u->email }})</span></span>
            <form method="POST" action="{{ route('admin.dids.unassign', [$did, $u]) }}">@csrf @method('DELETE')<button class="btn btn-outline btn-error btn-xs">Remove</button></form>
        </li>
    @empty
        <li class="p-3 text-base-content/75">None</li>
    @endforelse
</ul>
<form method="POST" action="{{ route('admin.dids.assign', $did) }}" class="join w-full max-w-lg">@csrf
    <select name="user_id" class="select join-item w-full">@foreach($assignable as $u)<option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>@endforeach</select>
    <button class="btn btn-primary join-item">Assign</button>
</form>
<p class="mt-2 text-xs text-base-content/75">Shared assignment: {{ config('broadcast.shared_did_assignment') ? 'enabled' : 'disabled (one user per DID)' }}</p>
@endsection
