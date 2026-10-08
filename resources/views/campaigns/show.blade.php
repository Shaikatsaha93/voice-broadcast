@extends('layouts.app')
@section('content')
@php $s = $campaign->status->value; @endphp
<h1 class="mb-4">{{ $campaign->name }} <x-status :value="$s" /></h1>
@if(auth()->user()->isManager())<p class="-mt-2 mb-4 text-sm text-base-content/70">Owner: {{ $campaign->user->name }} ({{ $campaign->user->email }})</p>@endif
@if($campaign->status->value === 'RUNNING' && $campaign->blocked_reason === 'INSUFFICIENT_BALANCE')
<div role="alert" class="alert alert-warning alert-soft my-4"><i class="bi bi-wallet2"></i><span>Waiting: the DID <b>{{ $campaign->did->number }}</b> does not have enough balance for another call (balance {{ \App\Support\Money::tk($campaign->did->balance) }}). No calls are generated until it is topped up; the campaign then continues by itself.</span></div>
@endif
@php $lastImport = $campaign->imports->sortByDesc('id')->first(); @endphp
@if($campaign->rejection_reason || $campaign->audio || $lastImport)
<div class="card p-4 my-4 text-sm space-y-1">
    @if($campaign->rejection_reason)<div class="text-red-700">Rejected: {{ $campaign->rejection_reason }}</div>@endif
    @if($campaign->audio)<audio controls preload="none" src="{{ route('audio.stream', $campaign->audio) }}"></audio>@endif
    @if($lastImport)<div>Last import: {{ $lastImport->status }} · total {{ $lastImport->total_rows }} · valid {{ $lastImport->valid_rows }} · invalid {{ $lastImport->invalid_rows }} · duplicate {{ $lastImport->duplicate_rows }} · imported {{ $lastImport->imported_rows }}</div>@endif
</div>
@endif
<div class="flex flex-wrap gap-2">
    @can('update', $campaign)
        <a class="btn btn-outline" href="{{ route('campaigns.edit', $campaign) }}">Edit</a>
        <form method="POST" action="{{ route('campaigns.import', $campaign) }}" enctype="multipart/form-data" class="flex gap-1">@csrf<input type="file" name="numbers" accept=".csv,.txt" required><button class="btn btn-outline">Upload CSV</button></form>
    @endcan
    @can('submit', $campaign)<form method="POST" action="{{ route('campaigns.submit', $campaign) }}">@csrf<button class="btn btn-primary">Submit for approval</button></form>@endcan
    @can('control', $campaign)
        @if($s === 'APPROVED')<form method="POST" action="{{ route('campaigns.start', $campaign) }}">@csrf<button class="btn btn-success"><i class="bi bi-play-fill"></i> Start</button></form>@endif
        @if($s === 'RUNNING')<form method="POST" action="{{ route('campaigns.pause', $campaign) }}">@csrf<button class="btn btn-warning"><i class="bi bi-pause-circle"></i> Pause</button></form>@endif
        @if($s === 'PAUSED')<form method="POST" action="{{ route('campaigns.resume', $campaign) }}">@csrf<button class="btn btn-info"><i class="bi bi-play-circle"></i> Resume</button></form>@endif
        @if(in_array($s, ['APPROVED', 'QUEUED', 'RUNNING', 'PAUSED', 'PENDING_APPROVAL', 'DRAFT']))<form method="POST" action="{{ route('campaigns.cancel', $campaign) }}" onsubmit="return confirm('Cancel campaign?')">@csrf<button class="btn btn-error text-white"><i class="bi bi-x-circle"></i> Cancel</button></form>@endif
    @endcan
    @can('delete', $campaign)<form method="POST" action="{{ route('campaigns.destroy', $campaign) }}">@csrf @method('DELETE')<button class="btn btn-error text-white"><i class="bi bi-trash"></i> Delete</button></form>@endcan
</div>
@if(in_array($s, ['COMPLETED', 'CANCELLED', 'RUNNING', 'PAUSED']))
@php
    $canRetry = auth()->user()->can('control', $campaign);
    $retryMeta = [
        'ANSWERED' => ['bi-telephone-inbound', 'Picked up the call', 'emerald', 'Answered'],
        'NO_ANSWER' => ['bi-telephone-x', 'Ringing but nobody answered', 'amber', 'No answer'],
        'BUSY' => ['bi-telephone-minus', 'Busy or declined', 'sky', 'Busy'],
        'FAILED' => ['bi-x-octagon', 'Could not be connected', 'pink', 'Failed'],
        'CANCELLED' => ['bi-slash-circle', 'Numbers cancelled before dialing', 'indigo', 'Cancelled'],
    ];
    $retryTotal = collect(\App\Services\Calls\CampaignService::RETRY_SECTIONS)->sum(fn ($k) => $stats[$k] ?? 0);
@endphp
<form method="POST" action="{{ route('campaigns.retry', $campaign) }}" @if($canRetry) data-retry onsubmit="return confirm('Retry the selected calls?')" @endif class="card p-4 mt-4 space-y-3">@csrf
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 class="font-semibold"><i class="bi bi-bar-chart-line"></i> Call results</h2>
            <p class="text-sm text-base-content/70">{{ number_format($totals['calls']) }} {{ \Illuminate\Support\Str::plural('call', $totals['calls']) }} made to {{ number_format($totals['numbers']) }} {{ \Illuminate\Support\Str::plural('number', $totals['numbers']) }}@if($totals['to_call']) &middot; {{ number_format($totals['to_call']) }} still to call @endif. @if($canRetry)Select a result to call those numbers again.@endif</p>
        </div>
        @if($canRetry)<label class="btn btn-sm btn-ghost gap-2"><input type="checkbox" class="checkbox checkbox-sm" data-all> Select all ({{ $retryTotal }})</label>@endif
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
        @foreach(\App\Services\Calls\CampaignService::RETRY_SECTIONS as $k)
            @php $n = $stats[$k] ?? 0; $made = $calls[$k] ?? 0; $wait = $waiting[$k] ?? 0; [$icon, $desc, $tone, $title] = $retryMeta[$k]; $pick = $canRetry && $n; @endphp
            @continue($k === 'CANCELLED' && ! $made && ! $n)
            <label class="block {{ $pick ? 'cursor-pointer' : '' }}">
                @if($canRetry)<input type="checkbox" name="sections[]" value="{{ $k }}" class="peer sr-only" data-count="{{ $n }}" @disabled(! $n)>@endif
                <div class="vb-stat vb-{{ $tone }} h-full flex items-start gap-3 ring-offset-2 ring-offset-transparent peer-checked:ring-4 peer-checked:ring-indigo-500 peer-focus-visible:ring-4 peer-focus-visible:ring-indigo-400">
                    <i class="bi {{ $icon }} text-2xl relative z-10"></i>
                    <div class="flex-1 min-w-0 relative z-10">
                        <div class="label">{{ $title }}</div>
                        <div class="value">{{ number_format($made) }}</div>
                        <div class="text-xs text-slate-700">{{ $desc }}</div>
                        <div class="mt-1 text-xs font-semibold text-slate-800">
                            @if($n){{ number_format($n) }} {{ \Illuminate\Support\Str::plural('number', $n) }} can be retried
                            @elseif($wait)<span class="font-normal">{{ number_format($wait) }} waiting for automatic retry</span>
                            @else<span class="font-normal opacity-70">Nothing to retry</span>@endif
                        </div>
                    </div>
                    @if($canRetry)<i class="bi bi-check-circle-fill text-indigo-700 text-2xl relative z-10 opacity-0 transition" data-tick></i>@endif
                </div>
            </label>
        @endforeach
    </div>
    @if($canRetry)
    <div class="flex justify-end">
        <button class="btn btn-warning w-full sm:w-auto" data-submit disabled><i class="bi bi-arrow-repeat"></i> <span data-btn>Retry</span></button>
    </div>
    @endif
</form>
<script>
document.querySelectorAll('form[data-retry]').forEach(f => {
    const boxes = [...f.querySelectorAll('input[name="sections[]"]:not(:disabled)')], all = f.querySelector('[data-all]'),
          btn = f.querySelector('[data-submit]'), btnText = f.querySelector('[data-btn]');
    const sync = () => {
        const on = boxes.filter(b => b.checked), n = on.reduce((s, b) => s + +b.dataset.count, 0);
        boxes.forEach(b => b.parentElement.querySelector('[data-tick]').style.opacity = b.checked ? 1 : 0);
        all.checked = boxes.length > 0 && on.length === boxes.length;
        all.indeterminate = on.length > 0 && on.length < boxes.length;
        all.disabled = boxes.length === 0;
        btn.disabled = on.length === 0;
        btnText.textContent = on.length ? `Retry ${n} number${n === 1 ? '' : 's'}` : 'Retry';
    };
    all.addEventListener('change', () => { boxes.forEach(b => b.checked = all.checked); sync(); });
    boxes.forEach(b => b.addEventListener('change', sync));
    sync();
});
</script>
@endif
<p class="mt-4"><a class="link" href="{{ route('reports', ['campaignId' => $campaign->id]) }}">View call report</a></p>
@endsection
