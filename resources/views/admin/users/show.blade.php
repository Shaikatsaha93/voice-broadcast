@extends('layouts.app')
@section('content')
<div class="mb-4">
    <h1 class="vb-title">{{ $user->name }}</h1>
    <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-base-content/75">{{ $user->email }} · {{ $user->roles->first()?->label }} · <x-status :value="$user->status" />@if($user->rate_per_pulse !== null) · call charge {{ \App\Support\Money::rate($user->rate_per_pulse, $user->pulse_seconds) }} · balance {{ \App\Support\Money::tk($user->balance ?? 0) }}@endif@if($user->valid_until) · valid until {{ $user->valid_until->format('Y-m-d') }}@if($user->isExpired()) <span class="badge badge-error badge-soft badge-sm">EXPIRED</span>@endif @endif</div>
</div>
<div class="mb-6 flex flex-wrap gap-2">
    <form method="POST" action="{{ route('admin.users.toggle', $user) }}">@csrf<button class="btn btn-outline btn-sm">{{ $user->status === 'active' ? 'Deactivate' : 'Activate' }}</button></form>
    <form method="POST" action="{{ route('admin.users.reset', $user) }}" onsubmit="return confirm('Reset password?')">@csrf<button class="btn btn-outline btn-error btn-sm"><i class="bi bi-key"></i>Reset password</button></form>
</div>
@if($user->isAdmin())
<div class="vb-card mb-6 p-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-semibold"><i class="bi bi-wallet2"></i> Balance</h2>
            <div class="mt-1 text-3xl font-bold tabular-nums">{{ \App\Support\Money::tk($user->balance ?? 0) }}</div>
            @if($user->rate_per_pulse !== null)<div class="text-sm text-base-content/70">Call charge: <b>{{ \App\Support\Money::tk($user->rate_per_pulse) }}</b> for every <b>{{ $user->pulse_seconds }} sec</b>.</div>@endif
        </div>
        @if(auth()->id() !== $user->id)
        <form method="POST" action="{{ route('admin.users.balance', $user) }}" class="flex flex-wrap items-end gap-2">@csrf
            <label class="block"><span class="mb-1 block text-xs">Amount</span><span class="input input-sm flex w-36 items-center gap-1"><span class="font-semibold text-base-content/70">{{ config('broadcast.currency', '৳') }}</span><input name="amount" type="number" step="0.0001" min="0.0001" class="grow" required></span></label>
            <label class="block"><span class="mb-1 block text-xs">Note (optional)</span><input name="note" maxlength="190" class="input input-sm w-44"></label>
            <button name="action" value="add" class="btn btn-success btn-sm text-white"><i class="bi bi-plus-lg"></i> Add</button>
            <button name="action" value="deduct" class="btn btn-error btn-sm text-white" onclick="return confirm('Remove this amount from the balance?')"><i class="bi bi-dash-lg"></i> Remove</button>
        </form>
        @endif
    </div>
    <div class="mt-4 overflow-x-auto">
        <table class="vb-table table-sm w-full">
            <thead><tr><th class="text-left">Time</th><th class="text-left">Type</th><th class="text-right">Amount</th><th class="text-right">Balance</th><th class="text-left">Note</th><th class="text-left">By</th></tr></thead>
            <tbody>@forelse($transactions as $t)<tr><td class="whitespace-nowrap tabular-nums">{{ $t->created_at }}</td><td>{{ strtoupper($t->type) }}</td><td class="text-right tabular-nums {{ $t->amount < 0 ? 'text-error' : 'text-success' }}">{{ $t->amount > 0 ? '+' : '' }}{{ \App\Support\Money::tk($t->amount) }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::tk($t->balance_after) }}</td><td>{{ $t->note }}</td><td>{{ $t->author?->name }}</td></tr>
            @empty<tr><td colspan="6" class="py-4 text-center text-base-content/70">No balance history yet.</td></tr>@endforelse</tbody>
        </table>
    </div>
</div>
@endif
<h2 class="mb-2 font-semibold">Assigned DIDs</h2>
<ul class="vb-card divide-y divide-base-200">
    @forelse($user->dids as $d)<li class="p-3">{{ $d->number }} <span class="text-base-content/75">({{ $d->label }})</span> — max {{ $d->max_concurrent_calls }}</li>@empty<li class="p-3 text-base-content/75">None</li>@endforelse
</ul>
@endsection
