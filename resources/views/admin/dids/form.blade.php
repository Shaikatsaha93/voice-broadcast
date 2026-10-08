@extends('layouts.app')
@section('content')
<form method="POST" action="{{ $did->exists ? route('admin.dids.update', $did) : route('admin.dids.store') }}" class="vb-card max-w-2xl" autocomplete="off">
    @csrf @if($did->exists) @method('PUT') @endif
    <div class="card-body gap-3">
        <h1 class="vb-title">{{ $did->exists ? 'Edit' : 'Create' }} DID</h1>
        <label class="block"><span class="mb-1 block text-sm">Number</span><input name="number" value="{{ old('number', $did->number) }}" class="input w-full" required></label>
        <label class="block"><span class="mb-1 block text-sm">Label</span><input name="label" value="{{ old('label', $did->label) }}" class="input w-full"></label>
        <label class="block"><span class="mb-1 block text-sm">Max concurrent calls</span><input name="max_concurrent_calls" type="number" min="1" value="{{ old('max_concurrent_calls', $did->max_concurrent_calls) }}" class="input w-full" required></label>
        <label class="block"><span class="mb-1 block text-sm">Status</span><select name="status" class="select w-full">@foreach(['active', 'inactive'] as $s)<option @selected(old('status', $did->status) === $s)>{{ $s }}</option>@endforeach</select></label>

        <div class="divider my-1">Call charge and balance</div>
        <div id="rate-box" class="rounded-box border border-base-300 bg-base-200/40 p-4">
            <p class="mb-2 text-sm font-semibold">How much is a call charged?</p>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-2 text-sm">
                <span>Charge</span>
                <label class="input input-sm flex w-36 items-center gap-1"><span class="font-semibold text-base-content/70">{{ config('broadcast.currency', '৳') }}</span><input name="rate_per_pulse" type="number" step="0.0001" min="0" value="{{ old('rate_per_pulse', $did->rate_per_pulse ?? 0) }}" class="grow" required></label>
                <span>taka for every</span>
                <label class="input input-sm flex w-32 items-center gap-1"><input name="pulse_seconds" type="number" min="1" max="3600" value="{{ old('pulse_seconds', $did->pulse_seconds ?? 60) }}" class="grow" required><span class="text-base-content/70">sec</span></label>
                <span>of answered call time (1 pulse).</span>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                <span class="text-base-content/60">Pulse length:</span>
                @foreach([1 => '1 sec', 6 => '6 sec', 30 => '30 sec', 60 => '1 min'] as $sec => $label)<button type="button" data-pulse="{{ $sec }}" class="btn btn-xs btn-outline">{{ $label }}</button>@endforeach
            </div>
            <div class="mt-3 rounded-lg bg-base-100 p-3 text-sm">
                <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-base-content/60">Example</div>
                <ul id="rate-example" class="space-y-0.5 tabular-nums"></ul>
                <p class="mt-2 text-xs text-base-content/60">A call is charged only if it is answered. A partly used pulse is charged as a full pulse, minimum 1 pulse. Rate 0 = free (no balance needed).</p>
            </div>
        </div>
        @unless($did->exists)
            <label class="block"><span class="mb-1 block text-sm">Opening balance</span><label class="input flex items-center gap-1"><span class="font-semibold text-base-content/70">{{ config('broadcast.currency', '৳') }}</span><input name="opening_balance" type="number" step="0.0001" min="0" value="{{ old('opening_balance', 0) }}" class="grow"></label></label>
        @else
            <p class="text-xs text-base-content/70">To add or remove balance, use the Balance section on the DID page.</p>
        @endunless
        <script>
        (() => {
            const box = document.getElementById('rate-box'), rate = box.querySelector('[name=rate_per_pulse]'), sec = box.querySelector('[name=pulse_seconds]'), list = document.getElementById('rate-example'), sym = @json(config('broadcast.currency', '৳'));
            const fmt = v => { let s = v.toFixed(4).replace(/0+$/, ''); const d = (s.split('.')[1] || '').length; return d < 2 ? v.toFixed(2) : s; };
            const dur = s => s < 60 ? s + ' sec' : Math.floor(s / 60) + ' min' + (s % 60 ? ' ' + (s % 60) + ' sec' : '');
            const render = () => {
                const r = parseFloat(rate.value) || 0, p = Math.max(1, parseInt(sec.value) || 60);
                const samples = [Math.min(p, 30), p + 1, p * 2 + Math.ceil(p / 2)].filter((v, i, a) => v > 0 && a.indexOf(v) === i);
                list.innerHTML = r <= 0 ? '<li>Free: no balance is used.</li>' : samples.map(s => { const n = Math.max(1, Math.ceil(s / p)); return `<li>${dur(s)} call = ${n} pulse${n > 1 ? 's' : ''} = <b>${sym}${fmt(n * r)}</b></li>`; }).join('');
            };
            box.querySelectorAll('[data-pulse]').forEach(b => b.addEventListener('click', () => { sec.value = b.dataset.pulse; render(); }));
            rate.addEventListener('input', render); sec.addEventListener('input', render); render();
        })();
        </script>

        <div class="divider my-1">SIP trunk (registers this DID in Asterisk)</div>
        <p class="text-xs text-base-content/70">Fill the SIP details from your provider. Asterisk registers automatically and the status shows on the DID page.</p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <label class="block sm:col-span-2"><span class="mb-1 block text-sm">SIP server (host / IP)</span><input name="sip_host" @required(! $did->exists || $did->hasSip()) value="{{ old('sip_host', $did->sip_host) }}" placeholder="sip.provider.com" class="input w-full" autocomplete="off"></label>
            <label class="block"><span class="mb-1 block text-sm">Port</span><input name="sip_port" type="number" min="1" max="65535" value="{{ old('sip_port', $did->sip_port ?? 5060) }}" class="input w-full"></label>
            <label class="block"><span class="mb-1 block text-sm">SIP username</span><input name="sip_username" @required(! $did->exists || $did->hasSip()) value="{{ old('sip_username', $did->sip_username) }}" class="input w-full" autocomplete="off"></label>
            <label class="block sm:col-span-2"><span class="mb-1 block text-sm">SIP password @if($did->sip_password)<span class="text-xs text-base-content/65">(leave blank to keep the saved one)</span>@endif</span><input name="sip_password" type="password" @required(! $did->exists || ! $did->sip_password) class="input w-full" autocomplete="new-password" placeholder="{{ $did->sip_password ? '••••••••' : '' }}"></label>
        </div>
        <div class="card-actions mt-2"><button class="btn btn-primary px-8">Save</button><a href="{{ route('admin.dids.index') }}" class="btn btn-ghost">Cancel</a></div>
    </div>
</form>
@endsection
