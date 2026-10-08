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
    <div class="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-3">
        @foreach(['Attempts' => $summary->total, 'Answered' => $summary->answered, 'No Answer' => $summary->no_answer, 'Busy' => $summary->busy, 'Failed' => $summary->failed, 'Duration (s)' => $summary->duration, 'Billsec' => $summary->billsec, 'Cost' => \App\Support\Money::tk($summary->cost)] as $l => $v)
            @php $tone = ['indigo', 'emerald', 'amber', 'sky', 'pink', 'indigo', 'emerald', 'amber'][$loop->index % 8]; @endphp
            <div class="vb-stat vb-{{ $tone }} !p-4"><div class="label !text-xs">{{ $l }}</div><div class="value !text-2xl">{{ $v ?? 0 }}</div></div>
        @endforeach
    </div>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-base-content/75">{{ number_format($summary->total) }} {{ \Illuminate\Support\Str::plural('result', $summary->total) }} match the current filters.</p>
        <button type="button" wire:click="export" wire:loading.attr="disabled" wire:target="export" class="btn btn-success btn-sm text-white" @disabled($summary->total == 0)>
            <span wire:loading.remove wire:target="export"><i class="bi bi-download"></i> Download CSV</span>
            <span wire:loading wire:target="export"><span class="loading loading-spinner loading-xs"></span> Preparing...</span>
        </button>
    </div>
    <div class="vb-card overflow-x-auto p-2">
        <table class="vb-table table-sm w-full min-w-[56rem] table-fixed">
            <colgroup><col class="w-[11rem]"><col><col class="w-[9rem]"><col class="w-[10rem]"><col class="w-[6rem]"><col class="w-[11rem]"><col class="w-[6.5rem]"><col class="w-[6.5rem]"><col class="w-[6.5rem]"></colgroup>
            <thead><tr><th class="text-left">Time</th><th class="text-left">Campaign</th><th class="text-left">DID</th><th class="text-left">Phone</th><th class="text-center">Attempt</th><th class="text-center">Status</th><th class="text-right">Duration</th><th class="text-right">Billsec</th><th class="text-right">Cost</th></tr></thead>
            <tbody>@forelse($attempts as $a)<tr class="align-middle"><td class="whitespace-nowrap tabular-nums">{{ $a->created_at }}</td><td class="truncate" title="{{ $a->campaign->name }}">{{ $a->campaign->name }}</td><td class="tabular-nums">{{ $a->did->number }}</td><td class="tabular-nums">{{ $a->phone }}</td><td class="text-center">#{{ $a->attempt_no }}</td><td class="text-center"><x-status :value="$a->status->value" /></td><td class="text-right tabular-nums">{{ $a->duration }}</td><td class="text-right tabular-nums">{{ $a->billsec }}</td><td class="text-right tabular-nums">{{ $a->cost > 0 ? \App\Support\Money::tk($a->cost) : '-' }}</td></tr>
            @empty<tr><td colspan="9" class="py-6 text-center text-base-content/75">No calls.</td></tr>@endforelse</tbody>
        </table>
    </div>
    {{ $attempts->links() }}
</div>
