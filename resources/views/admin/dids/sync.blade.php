@extends('layouts.app')
@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="vb-title">Add DID from Asterisk</h1>
        <p class="mt-1 text-sm text-base-content/75">Pick a trunk registered in Asterisk, then enter the number and concurrency.</p>
    </div>
    <a class="btn btn-outline btn-sm" href="{{ route('admin.dids.index') }}"><i class="bi bi-arrow-left"></i>Back</a>
</div>

@if($dryRun)
    <div role="alert" class="alert alert-warning alert-soft mb-5"><i class="bi bi-info-circle-fill"></i><span>Dry-run mode (<code>ASTERISK_DRY_RUN=true</code>): showing sample trunks, not real ones from Asterisk.</span></div>
@endif

@if($trunks === null)
    <div role="alert" class="alert alert-error alert-soft mb-5"><i class="bi bi-plug-fill"></i><span>Cannot reach Asterisk (ARI). Check <code>ASTERISK_ARI_URL</code>, username and password in <code>.env</code>, then reload.</span></div>
@elseif(empty($trunks))
    <div class="vb-card"><div class="card-body items-center text-base-content/75">No PJSIP trunks found in Asterisk.</div></div>
@else
    <div class="grid items-start gap-4 md:grid-cols-2">
        @foreach($trunks as $t)
            @php
                $online = in_array(strtolower($t['state']), ['online', 'not_inuse', 'inuse', 'busy', 'ringing']);
                $count = collect($used)->filter(fn ($u) => str_ends_with($u, '@'.$t['name']))->count();
            @endphp
            <form method="POST" action="{{ route('admin.dids.sync.import') }}" class="vb-card">
                @csrf
                <input type="hidden" name="trunk" value="{{ $t['name'] }}">
                <div class="card-body gap-3">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="flex items-center gap-2 font-semibold"><i class="bi bi-hdd-network text-primary"></i>{{ $t['name'] }}</h2>
                        <span class="badge badge-soft badge-sm font-semibold {{ $online ? 'badge-success' : 'badge-error' }}">{{ strtoupper($t['state']) }}</span>
                    </div>
                    <p class="text-xs text-base-content/75">Dial string: <code>PJSIP/{number}{{ '@'.$t['name'] }}</code> · {{ $count }} DID(s) already use this trunk</p>
                    <label class="block"><span class="mb-1 block text-sm">Number</span><input name="number" value="{{ old('trunk') === $t['name'] ? old('number') : '' }}" placeholder="e.g. 8801700000000" class="input w-full" required></label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="mb-1 block text-sm">Label</span><input name="label" value="{{ old('trunk') === $t['name'] ? old('label') : '' }}" class="input w-full"></label>
                        <label class="block"><span class="mb-1 block text-sm">Max concurrent</span><input name="max_concurrent_calls" type="number" min="1" value="{{ old('trunk') === $t['name'] ? old('max_concurrent_calls', 1) : 1 }}" class="input w-full" required></label>
                    </div>
                    <button class="btn btn-primary" @disabled(! $online)><i class="bi bi-plus-lg"></i>Add DID</button>
                    @unless($online)<p class="text-xs text-error">Trunk is not online, so it can't be added right now.</p>@endunless
                </div>
            </form>
        @endforeach
    </div>
@endif
@endsection
