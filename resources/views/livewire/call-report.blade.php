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
                <label class="flex items-center gap-2 text-sm"><span class="text-base-content/75">On</span>
                    <input wire:model="date" type="date" max="{{ now()->toDateString() }}" class="input input-sm"></label>
                <div class="flex gap-1">
                    <button type="button" x-on:click="$wire.date = '{{ now()->toDateString() }}'" class="btn btn-ghost btn-xs">Today</button>
                    <button type="button" x-on:click="$wire.date = '{{ now()->subDay()->toDateString() }}'" class="btn btn-ghost btn-xs">Yesterday</button>
                </div>
            @else
                <label class="flex items-center gap-2 text-sm"><span class="text-base-content/75">From</span>
                    <input wire:model="from" type="date" max="{{ now()->toDateString() }}" class="input input-sm"></label>
                <label class="flex items-center gap-2 text-sm"><span class="text-base-content/75">To</span>
                    <input wire:model="to" type="date" max="{{ now()->toDateString() }}" class="input input-sm"></label>
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
        @foreach(['Attempts' => $summary->total, 'Answered' => $summary->answered, 'No Answer' => $summary->no_answer, 'Busy' => $summary->busy, 'Failed' => $summary->failed, 'Duration (s)' => $summary->duration, 'Billsec' => $summary->billsec] as $l => $v)
            @php $tone = ['indigo', 'emerald', 'amber', 'sky', 'pink', 'indigo', 'emerald'][$loop->index]; @endphp
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
        <table class="vb-table table-sm">
            <thead><tr><th>Time</th><th>Campaign</th><th>DID</th><th>Phone</th><th>Attempt</th><th>Status</th><th>Duration</th><th>Billsec</th></tr></thead>
            <tbody>@forelse($attempts as $a)<tr><td class="whitespace-nowrap">{{ $a->created_at }}</td><td>{{ $a->campaign->name }}</td><td>{{ $a->did->number }}</td><td>{{ $a->phone }}</td><td>#{{ $a->attempt_no }}</td><td><x-status :value="$a->status->value" /></td><td>{{ $a->duration }}</td><td>{{ $a->billsec }}</td></tr>
            @empty<tr><td colspan="8" class="py-6 text-center text-base-content/75">No calls.</td></tr>@endforelse</tbody>
        </table>
    </div>
    {{ $attempts->links() }}
</div>
