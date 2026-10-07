@extends('layouts.app')
@section('content')
@php $s = $campaign->status->value; @endphp
<h1 class="">{{ $campaign->name }} <x-status :value="$s" /></h1>
<div class="card p-4 my-4 text-sm space-y-1">
    <div>Owner: {{ $campaign->user->name }} · DID: {{ $campaign->did->number }} (max {{ $campaign->did->max_concurrent_calls }}) · Requested concurrency: {{ $campaign->requested_concurrency }} · Max attempts: {{ $campaign->max_attempts }} · Active calls: {{ $active }}</div>
    @if($campaign->rejection_reason)<div class="text-red-700">Rejected: {{ $campaign->rejection_reason }}</div>@endif
    @if($campaign->audio)<audio controls preload="none" src="{{ route('audio.stream', $campaign->audio) }}"></audio>@else<div>No audio selected.</div>@endif
    <div>Recipients: @forelse($stats as $k => $v){{ $k }}={{ $v }} @empty none @endforelse</div>
    @foreach($campaign->imports->sortByDesc('id')->take(1) as $i)<div>Last import: {{ $i->status }} · total {{ $i->total_rows }} · valid {{ $i->valid_rows }} · invalid {{ $i->invalid_rows }} · duplicate {{ $i->duplicate_rows }} · imported {{ $i->imported_rows }}</div>@endforeach
</div>
<div class="flex flex-wrap gap-2">
    @can('update', $campaign)
        <a class="btn btn-outline" href="{{ route('campaigns.edit', $campaign) }}">Edit</a>
        <form method="POST" action="{{ route('campaigns.import', $campaign) }}" enctype="multipart/form-data" class="flex gap-1">@csrf<input type="file" name="numbers" accept=".csv,.txt" required><button class="btn btn-outline">Upload CSV</button></form>
    @endcan
    @can('submit', $campaign)<form method="POST" action="{{ route('campaigns.submit', $campaign) }}">@csrf<button class="btn btn-primary">Submit for approval</button></form>@endcan
    @can('control', $campaign)
        @if($s === 'APPROVED')<form method="POST" action="{{ route('campaigns.start', $campaign) }}">@csrf<button class="btn btn-success">Start</button></form>@endif
        @if($s === 'RUNNING')<form method="POST" action="{{ route('campaigns.pause', $campaign) }}">@csrf<button class="btn btn-outline">Pause</button></form>@endif
        @if($s === 'PAUSED')<form method="POST" action="{{ route('campaigns.resume', $campaign) }}">@csrf<button class="btn btn-outline">Resume</button></form>@endif
        @if(in_array($s, ['APPROVED', 'QUEUED', 'RUNNING', 'PAUSED', 'PENDING_APPROVAL', 'DRAFT']))<form method="POST" action="{{ route('campaigns.cancel', $campaign) }}" onsubmit="return confirm('Cancel campaign?')">@csrf<button class="btn btn-outline-danger">Cancel</button></form>@endif
    @endcan
    @can('delete', $campaign)<form method="POST" action="{{ route('campaigns.destroy', $campaign) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete</button></form>@endcan
</div>
<p class="mt-4"><a class="link" href="{{ route('reports', ['campaignId' => $campaign->id]) }}">View call report</a></p>
@endsection
