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
@if($did->hasSip())
<div class="vb-card mb-6 p-4" id="sip-card" data-url="{{ route('admin.dids.sip.status', $did) }}">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-semibold"><i class="bi bi-hdd-network"></i> SIP trunk</h2>
        <div class="flex gap-2">
            <button type="button" class="btn btn-outline btn-sm" id="sip-check"><i class="bi bi-arrow-clockwise"></i> Check now</button>
            <form method="POST" action="{{ route('admin.dids.sip.apply', $did) }}">@csrf<button class="btn btn-outline btn-sm" title="Rewrite the config and reload Asterisk"><i class="bi bi-cloud-upload"></i> Re-apply</button></form>
        </div>
    </div>
    <div class="mt-3 flex flex-wrap items-center gap-3">
        <span id="sip-badge"><x-status :value="$did->sip_status ?? 'pending'" /></span>
        <span class="text-sm" id="sip-detail">{{ $did->sip_status_detail }}</span>
    </div>
    <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
        <div><dt class="inline text-base-content/70">Server:</dt> <dd class="inline">{{ $did->sip_host }}:{{ $did->sip_port }}</dd></div>
        <div><dt class="inline text-base-content/70">Username:</dt> <dd class="inline">{{ $did->sip_username }}</dd></div>
        <div><dt class="inline text-base-content/70">Password:</dt> <dd class="inline">saved (hidden)</dd></div>
        <div><dt class="inline text-base-content/70">Last checked:</dt> <dd class="inline" id="sip-checked">{{ $did->sip_checked_at?->diffForHumans() ?? 'never' }}</dd></div>
    </dl>
</div>
<script>
(() => {
    const card = document.getElementById('sip-card'), badge = document.getElementById('sip-badge'), detail = document.getElementById('sip-detail'), checked = document.getElementById('sip-checked');
    const tone = { registered: 'badge-success', rejected: 'badge-error', unreachable: 'badge-error', unregistered: 'badge-warning', pending: 'badge-warning', inactive: 'badge-error' };
    let timer = null;
    async function check() {
        try {
            const r = await fetch(card.dataset.url, { headers: { 'Accept': 'application/json' } });
            if (!r.ok) throw new Error();
            const d = await r.json();
            badge.innerHTML = `<span class="badge badge-soft badge-sm font-semibold ${tone[d.status] || 'badge-neutral'}">${d.status.toUpperCase()}</span>`;
            detail.textContent = d.detail || '';
            checked.textContent = d.checked_at || 'just now';
            if (['registered', 'rejected', 'inactive'].includes(d.status)) { clearInterval(timer); timer = null; }
        } catch (e) { detail.textContent = 'Could not read the status.'; }
    }
    document.getElementById('sip-check').addEventListener('click', () => { check(); if (!timer) timer = setInterval(check, 5000); });
    @if(! in_array($did->sip_status, ['registered', 'rejected', 'inactive']))
    timer = setInterval(check, 5000); check();
    @endif
})();
</script>
@endif
<div class="vb-card mb-6 p-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-semibold"><i class="bi bi-wallet2"></i> Balance</h2>
            <div class="mt-1 text-3xl font-bold tabular-nums">{{ \App\Support\Money::tk($did->balance) }}@unless($did->canAffordAnotherCall()) <span class="badge badge-error badge-soft align-middle">LOW - calls are not generated</span>@endunless</div>
            <div class="text-sm text-base-content/70">@if($did->rate_per_pulse > 0)Each call is charged <b>{{ \App\Support\Money::tk($did->rate_per_pulse) }}</b> for every <b>{{ $did->pulse_seconds }} sec</b> of answered time.@else Calls on this DID are free (no rate set).@endif</div>
        </div>
        <form method="POST" action="{{ route('admin.dids.balance', $did) }}" class="flex flex-wrap items-end gap-2">@csrf
            <label class="block"><span class="mb-1 block text-xs">Amount</span><span class="input input-sm flex w-36 items-center gap-1"><span class="font-semibold text-base-content/70">{{ config('broadcast.currency', '৳') }}</span><input name="amount" type="number" step="0.0001" min="0.0001" class="grow" required></span></label>
            <label class="block"><span class="mb-1 block text-xs">Note (optional)</span><input name="note" maxlength="190" class="input input-sm w-44"></label>
            <button name="action" value="add" class="btn btn-success btn-sm text-white"><i class="bi bi-plus-lg"></i> Add</button>
            <button name="action" value="deduct" class="btn btn-error btn-sm text-white" onclick="return confirm('Remove this amount from the balance?')"><i class="bi bi-dash-lg"></i> Remove</button>
        </form>
    </div>
    <div class="mt-4 overflow-x-auto">
        <table class="vb-table table-sm w-full">
            <thead><tr><th class="text-left">Time</th><th class="text-left">Type</th><th class="text-right">Amount</th><th class="text-right">Balance</th><th class="text-left">Note</th><th class="text-left">By</th></tr></thead>
            <tbody>@forelse($transactions as $t)<tr><td class="whitespace-nowrap tabular-nums">{{ $t->created_at }}</td><td>{{ strtoupper($t->type) }}</td><td class="text-right tabular-nums {{ $t->amount < 0 ? 'text-error' : 'text-success' }}">{{ $t->amount > 0 ? '+' : '' }}{{ \App\Support\Money::tk($t->amount) }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::tk($t->balance_after) }}</td><td>{{ $t->note }}</td><td>{{ $t->author?->name ?? 'system' }}</td></tr>
            @empty<tr><td colspan="6" class="py-4 text-center text-base-content/70">No balance history yet.</td></tr>@endforelse</tbody>
        </table>
    </div>
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
