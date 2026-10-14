<div class="space-y-4">
    <form wire:submit="applyFilters" class="vb-card"><div class="card-body gap-3 p-4">
        <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
            <select wire:model="campaignId" class="select select-sm w-full"><option value="">All campaigns</option>@foreach($campaigns as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
            <select wire:model="didId" class="select select-sm w-full"><option value="">All DIDs</option>@foreach($dids as $d)<option value="{{ $d->id }}">{{ $d->number }}</option>@endforeach</select>
            <select wire:model="status" class="select select-sm w-full"><option value="">All statuses</option>@foreach($statuses as $s)<option>{{ $s }}</option>@endforeach</select>
            <input wire:model="phone" placeholder="Phone" class="input input-sm w-full">
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-base-300 pt-3">
            <span class="text-sm font-medium"><i class="bi bi-calendar3 mr-1 text-primary"></i>Date</span>
            <div class="join">
                <button type="button" wire:click="$set('dateMode', 'single')" class="btn btn-sm join-item {{ $dateMode === 'single' ? 'btn-primary' : 'btn-outline' }}">Single date</button>
                <button type="button" wire:click="$set('dateMode', 'range')" class="btn btn-sm join-item {{ $dateMode === 'range' ? 'btn-primary' : 'btn-outline' }}">Custom range</button>
            </div>

            @if($dateMode === 'single')
                <label class="flex items-center gap-2 text-sm whitespace-nowrap"><span class="text-base-content/75">On</span>
                    <input wire:model="date" type="date" max="{{ now()->toDateString() }}" class="input input-sm w-40 min-w-[10rem] pr-2"></label>
                <div class="flex gap-1">
                    <button type="button" x-on:click="$wire.date = '{{ now()->toDateString() }}'" class="btn btn-ghost btn-xs">Today</button>
                    <button type="button" x-on:click="$wire.date = '{{ now()->subDay()->toDateString() }}'" class="btn btn-ghost btn-xs">Yesterday</button>
                </div>
            @else
                <label class="flex items-center gap-2 text-sm whitespace-nowrap"><span class="text-base-content/75">From</span>
                    <input wire:model="from" type="date" max="{{ now()->toDateString() }}" class="input input-sm w-40 min-w-[10rem] pr-2"></label>
                <label class="flex items-center gap-2 text-sm whitespace-nowrap"><span class="text-base-content/75">To</span>
                    <input wire:model="to" type="date" max="{{ now()->toDateString() }}" class="input input-sm w-40 min-w-[10rem] pr-2"></label>
                <div class="flex gap-1">
                    <button type="button" x-on:click="$wire.to = '{{ now()->toDateString() }}'; $wire.from = '{{ now()->subDays(6)->toDateString() }}'" class="btn btn-ghost btn-xs">Last 7 days</button>
                    <button type="button" x-on:click="$wire.to = '{{ now()->toDateString() }}'; $wire.from = '{{ now()->subDays(29)->toDateString() }}'" class="btn btn-ghost btn-xs">Last 30 days</button>
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-base-300 pt-3">
            <button type="button" wire:click="resetFilters" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-counterclockwise"></i>Reset</button>
            <button type="submit" class="btn btn-primary btn-sm px-6" wire:loading.attr="disabled" wire:target="applyFilters">
                <span wire:loading.remove wire:target="applyFilters"><i class="bi bi-search"></i> Search</span>
                <span wire:loading wire:target="applyFilters"><span class="loading loading-spinner loading-xs"></span> Searching...</span>
            </button>
        </div>
    </div></form>
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="badge badge-primary badge-soft gap-1 py-3"><i class="bi bi-calendar-range"></i> Showing: <b>{{ $this->dateLabel() }}</b></span>
        <span class="text-base-content/70">Cards, table and CSV cover this period. Change the date above and press Search.</span>
    </div>
    @php
        $isMgr = auth()->user()->isManager();
        $callCards = ['Attempts' => $summary->total, 'Answered' => $summary->answered, 'No Answer' => $summary->no_answer, 'Busy' => $summary->busy, 'Failed' => $summary->failed, 'Duration (s)' => $summary->duration];
        $moneyCards = $isMgr
            ? ['Billsec' => $summary->billsec, 'Charged' => \App\Support\Money::tk($summary->cost), 'Admin cost' => \App\Support\Money::tk($summary->admin_cost), 'Profit' => \App\Support\Money::tk($summary->profit), 'MRC (monthly)' => \App\Support\Money::tk($mrcDids->sum('mrc'))]
            : ['Billsec' => $summary->billsec, 'Cost' => \App\Support\Money::tk($summary->cost)];
        $tones = ['indigo', 'emerald', 'amber', 'sky', 'pink', 'indigo'];
    @endphp
    <div class="space-y-3">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach($callCards as $l => $v)
                <div class="vb-stat vb-{{ $tones[$loop->index] }} !p-4"><div class="label !text-xs">{{ $l }}</div><div class="value !text-2xl">{{ $v ?? 0 }}</div></div>
            @endforeach
        </div>
        <div class="grid grid-cols-2 gap-3 {{ $isMgr ? 'sm:grid-cols-3 lg:grid-cols-5' : 'sm:grid-cols-2 lg:grid-cols-2' }}">
            @foreach($moneyCards as $l => $v)
                <div class="vb-stat vb-{{ $tones[($loop->index + 2) % 6] }} !p-4"><div class="label !text-xs">{{ $l }}</div><div class="value !text-2xl">{{ $v ?? 0 }}</div></div>
            @endforeach
        </div>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-base-content/75">{{ number_format($summary->total) }} {{ \Illuminate\Support\Str::plural('result', $summary->total) }} match the current filters.</p>
        <button type="button" wire:click="export" wire:loading.attr="disabled" wire:target="export" class="btn btn-success btn-sm text-white" @disabled($summary->total == 0)>
            <span wire:loading.remove wire:target="export"><i class="bi bi-download"></i> Download CSV</span>
            <span wire:loading wire:target="export"><span class="loading loading-spinner loading-xs"></span> Preparing...</span>
        </button>
    </div>
    <div class="vb-card overflow-x-auto p-2">
        <table class="vb-table table-sm w-full table-fixed {{ auth()->user()->isManager() ? 'min-w-[82rem]' : 'min-w-[64rem]' }}">
            <colgroup>@if(auth()->user()->isManager())<col class="w-[9.5rem]"><col><col class="w-[7.5rem]"><col class="w-[9rem]"><col class="w-[4.5rem]"><col class="w-[8.5rem]"><col class="w-[5rem]"><col class="w-[5rem]"><col class="w-[6rem]"><col class="w-[7.5rem]"><col class="w-[6rem]"><col class="w-[5.5rem]">@else<col class="w-[11rem]"><col><col class="w-[9rem]"><col class="w-[10rem]"><col class="w-[6rem]"><col class="w-[9rem]"><col class="w-[6rem]"><col class="w-[6rem]"><col class="w-[6.5rem]">@endif</colgroup>
            <thead><tr><th class="text-left">Time</th><th class="text-left">Campaign</th><th class="text-left">DID</th><th class="text-left">Phone</th><th class="text-center">Attempt</th><th class="text-center">Status</th><th class="text-right">Duration</th><th class="text-right">Billsec</th><th class="text-right">{{ auth()->user()->isManager() ? 'Charged' : 'Cost' }}</th>@if(auth()->user()->isManager())<th class="text-left">User</th><th class="text-right">Admin cost</th><th class="text-right">Profit</th>@endif</tr></thead>
            <tbody>@forelse($attempts as $a)<tr class="align-middle"><td class="whitespace-nowrap tabular-nums">{{ $a->created_at }}</td><td class="truncate" title="{{ $a->campaign->name }}">{{ $a->campaign->name }}</td><td class="tabular-nums">{{ $a->did->number }}</td><td class="tabular-nums">{{ $a->phone }}</td><td class="text-center">#{{ $a->attempt_no }}</td><td class="text-center"><x-status :value="$a->status->value" /></td><td class="text-right tabular-nums">{{ $a->duration }}</td><td class="text-right tabular-nums">{{ $a->billsec }}</td><td class="text-right tabular-nums">{{ $a->cost > 0 ? \App\Support\Money::tk($a->cost) : '-' }}</td>@if(auth()->user()->isManager())<td class="truncate" title="{{ $a->user?->name }}">{{ $a->user?->name }}</td><td class="text-right tabular-nums">{{ $a->admin_id && $a->admin_cost > 0 ? \App\Support\Money::tk($a->admin_cost) : '-' }}</td><td class="text-right tabular-nums">@if($a->admin_id && $a->billed_at){{ \App\Support\Money::tk((float) $a->cost - (float) $a->admin_cost) }}@else-@endif</td>@endif</tr>
            @empty<tr><td colspan="{{ auth()->user()->isManager() ? 12 : 9 }}" class="py-6 text-center text-base-content/75">No calls.</td></tr>@endforelse</tbody>
        </table>
    </div>
    {{ $attempts->links() }}

    @if(auth()->user()->isManager())
    <div class="vb-card p-4">
        <h2 class="font-semibold"><i class="bi bi-cash-coin"></i> Cost by user and DID</h2>
        <p class="mb-2 text-xs text-base-content/70">For the calls above (answered calls that were charged). <b>Charged</b> is what the user paid on the DID, <b>Admin cost</b> is what the Admin paid for the same calls, <b>Profit</b> = Charged - Admin cost. <b>MRC / month</b> is the monthly recurring charge of that DID (counted once per DID in the total).</p>
        <div class="overflow-x-auto">
            <table class="vb-table table-sm w-full">
                <thead><tr><th class="text-left">User</th><th class="text-left">DID</th>@unless(auth()->user()->isAdmin())<th class="text-left">Admin</th>@endunless<th class="text-right">Calls</th><th class="text-right">Billsec</th><th class="text-right">Charged</th><th class="text-right">Admin cost</th><th class="text-right">Profit</th><th class="text-right">MRC / month</th></tr></thead>
                <tbody>
                @php $tc = $ta = $tp = 0; $tn = 0; $mrcSeen = []; @endphp
                @forelse($breakdown as $r)
                    @php $profit = $r->admin_id ? (float) $r->charged - (float) $r->admin_cost : null; $tc += (float) $r->charged; $ta += (float) $r->admin_cost; $tp += (float) $profit; $tn += $r->calls; $mrcSeen[$r->did_id] = (float) $r->did_mrc; @endphp
                    <tr><td>{{ $r->user_name }}</td><td class="tabular-nums">{{ $r->did_number }}</td>@unless(auth()->user()->isAdmin())<td>{{ $r->admin_name ?? '-' }}</td>@endunless<td class="text-right tabular-nums">{{ $r->calls }}</td><td class="text-right tabular-nums">{{ $r->billsec }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::tk($r->charged) }}</td><td class="text-right tabular-nums">{{ $r->admin_id ? \App\Support\Money::tk($r->admin_cost) : '-' }}</td><td class="text-right font-semibold tabular-nums {{ $profit !== null && $profit < 0 ? 'text-error' : 'text-success' }}">{{ $profit !== null ? \App\Support\Money::tk($profit) : '-' }}</td><td class="text-right tabular-nums">{{ $r->did_mrc > 0 ? \App\Support\Money::tk($r->did_mrc) : '-' }}</td></tr>
                @empty
                    <tr><td colspan="{{ auth()->user()->isAdmin() ? 8 : 9 }}" class="py-4 text-center text-base-content/70">No charged calls for these filters.</td></tr>
                @endforelse
                </tbody>
                @if($breakdown->isNotEmpty())
                <tfoot><tr class="font-semibold"><td colspan="{{ auth()->user()->isAdmin() ? 2 : 3 }}">Total</td><td class="text-right tabular-nums">{{ $tn }}</td><td></td><td class="text-right tabular-nums">{{ \App\Support\Money::tk($tc) }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::tk($ta) }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::tk($tp) }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::tk(array_sum($mrcSeen)) }}</td></tr></tfoot>
                @endif
            </table>
        </div>
    </div>
    @endif

    @if(auth()->user()->isManager() && $mrcDids->isNotEmpty())
    <div class="vb-card p-4">
        <h2 class="font-semibold"><i class="bi bi-calendar-check"></i> MRC of DIDs</h2>
        <p class="mb-2 text-xs text-base-content/70">Own DIDs have no per-call charge, only a fixed monthly recurring charge (MRC). Reseller DIDs are listed too when they have an MRC. This is the MRC per month, not tied to the date filter.</p>
        <div class="overflow-x-auto">
            <table class="vb-table table-sm w-full">
                <thead><tr><th class="text-left">DID</th><th class="text-left">Label</th><th class="text-left">Type</th><th class="text-left">Status</th><th class="text-left">Valid until</th><th class="text-right">MRC / month</th></tr></thead>
                <tbody>@foreach($mrcDids as $m)<tr><td class="tabular-nums">{{ $m->number }}</td><td>{{ $m->label }}</td><td>{{ $m->did_type === 'own' ? 'Own' : 'Reseller' }}</td><td><x-status :value="$m->status" /></td><td class="whitespace-nowrap">@if($m->valid_until){{ $m->valid_until->format('Y-m-d') }}@if($m->isExpired()) <span class="badge badge-error badge-soft badge-sm">EXPIRED</span>@endif @else-@endif</td><td class="text-right tabular-nums">{{ \App\Support\Money::tk($m->mrc) }}</td></tr>@endforeach</tbody>
                <tfoot><tr class="font-semibold"><td colspan="5">Total MRC</td><td class="text-right tabular-nums">{{ \App\Support\Money::tk($mrcDids->sum('mrc')) }}</td></tr></tfoot>
            </table>
        </div>
    </div>
    @endif
</div>
