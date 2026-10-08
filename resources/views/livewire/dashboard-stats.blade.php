<div wire:poll.5s class="space-y-6">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-4">
        @foreach($cards as $label => $value)
            @php $tone = ['indigo', 'pink', 'sky', 'emerald', 'amber'][$loop->index % 5]; @endphp
            <div class="vb-stat vb-{{ $tone }}"><div class="label">{{ $label }}</div><div class="value">{{ $value }}</div></div>
        @endforeach
    </div>
    <div class="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-4">
        @foreach($today as $label => $value)
            @php $tone = ['emerald', 'sky', 'amber', 'pink', 'indigo'][$loop->index % 5]; @endphp
            <div class="vb-stat vb-{{ $tone }}"><div class="label">{{ $label }} (today)</div><div class="value">{{ $value }}</div></div>
        @endforeach
    </div>
    <div class="vb-card">
        <h2 class="px-5 pt-5 font-semibold"><i class="bi bi-telephone mr-1 text-primary"></i>DID usage</h2>
        <div class="overflow-x-auto p-2">
            <table class="vb-table">
                <thead><tr><th>DID</th><th class="text-right">Balance</th><th class="text-right">Call rate</th><th>Active calls</th><th>Max concurrent</th><th>Available slots</th></tr></thead>
                <tbody>@forelse($dids as $d)<tr><td class="font-medium">{{ $d['number'] }}</td>
                    <td class="text-right tabular-nums"><span class="font-semibold">{{ \App\Support\Money::tk($d['balance']) }}</span>@if(! $d['can_call'])<span class="badge badge-error badge-soft badge-sm ml-1" title="Not enough balance for a call: calls on this DID are not generated until it is topped up">LOW</span>@endif</td>
                    <td class="text-right tabular-nums">@if($d['rate'] > 0){{ \App\Support\Money::tk($d['rate']) }} <span class="text-xs text-base-content/60">per {{ $d['pulse'] }} sec</span>@else<span class="text-base-content/60">free</span>@endif</td>
                    <td>{{ $d['active'] }}</td><td>{{ $d['max'] }}</td><td>{{ $d['free'] }}</td></tr>
                @empty<tr><td colspan="6" class="py-6 text-center text-base-content/75">No DIDs.</td></tr>@endforelse</tbody>
            </table>
        </div>
    </div>
</div>
