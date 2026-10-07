@extends('layouts.app')
@section('content')
<div class="mb-4">
    <h1 class="vb-title">{{ $user->name }}</h1>
    <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-base-content/75">{{ $user->email }} · {{ $user->roles->first()?->label }} · <x-status :value="$user->status" /></div>
</div>
<div class="mb-6 flex flex-wrap gap-2">
    <form method="POST" action="{{ route('admin.users.toggle', $user) }}">@csrf<button class="btn btn-outline btn-sm">{{ $user->status === 'active' ? 'Deactivate' : 'Activate' }}</button></form>
    <form method="POST" action="{{ route('admin.users.reset', $user) }}" onsubmit="return confirm('Reset password?')">@csrf<button class="btn btn-outline btn-error btn-sm"><i class="bi bi-key"></i>Reset password</button></form>
</div>
<h2 class="mb-2 font-semibold">Assigned DIDs</h2>
<ul class="vb-card divide-y divide-base-200">
    @forelse($user->dids as $d)<li class="p-3">{{ $d->number }} <span class="text-base-content/75">({{ $d->label }})</span> — max {{ $d->max_concurrent_calls }}</li>@empty<li class="p-3 text-base-content/75">None</li>@endforelse
</ul>
@endsection
