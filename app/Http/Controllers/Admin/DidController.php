<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDidRequest;
use App\Jobs\DispatchCampaignCalls;
use App\Models\Campaign;
use App\Models\Did;
use App\Models\Role;
use App\Models\User;
use App\Services\Asterisk\TrunkProvisioner;
use App\Services\Audit\Audit;
use App\Services\Did\DidBilling;
use App\Support\Money;
use App\Services\Did\DidSlotManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DidController extends Controller
{
    public function index(DidSlotManager $slots, TrunkProvisioner $trunks)
    {
        Gate::authorize('manage', Did::class);
        $trunks->refreshStatuses();
        $dids = Did::visibleTo(auth()->user())->with('users:id,name')->withCount('slots as active_calls')->orderBy('id')->paginate(25);

        return view('admin.dids.index', compact('dids'));
    }

    public function create()
    {
        Gate::authorize('manage', Did::class);

        return view('admin.dids.form', ['did' => new Did(['status' => 'active', 'max_concurrent_calls' => 1])]);
    }

    /** Split the SIP fields out of the validated data. Blank password on edit keeps the stored one; blank host removes SIP. */
    private function sipAttributes(array $data, ?Did $did = null): array
    {
        unset($data['opening_balance']);
        $sip = ['sip_host', 'sip_port', 'sip_username', 'sip_password'];
        $rest = array_diff_key($data, array_flip($sip));

        if (blank($data['sip_host'] ?? null)) {
            return $rest + array_fill_keys($sip, null) + ['sip_status' => null, 'sip_status_detail' => null, 'sip_checked_at' => null, 'sip_port' => 5060]
                + (($did?->hasSip()) ? ['trunk' => null] : []);
        }

        $attrs = $rest + [
            'sip_host' => strtolower($data['sip_host']),
            'sip_port' => $data['sip_port'] ?? 5060,
            'sip_username' => $data['sip_username'],
            'sip_status' => 'pending',
            'sip_status_detail' => 'Waiting for Asterisk to load the registration.',
            'sip_checked_at' => null,
        ];
        if (filled($data['sip_password'] ?? null)) {
            $attrs['sip_password'] = $data['sip_password'];
        }

        return $attrs;
    }

    /** Write the trunk config to Asterisk and reload; returns a flash message pair. */
    private function provision(Did $did, TrunkProvisioner $trunks): array
    {
        if ($did->hasSip() && $did->trunk !== $trunks->dialString($did)) {
            $did->update(['trunk' => $trunks->dialString($did)]);
        }
        $error = $trunks->apply();
        if ($did->hasSip() && ! $error) {
            $trunks->refreshStatuses();
        }

        return $error ? ['warning', $error] : ['status', $did->hasSip() ? 'Saved. Asterisk is registering the trunk, status updates below.' : 'Saved.'];
    }

    public function store(StoreDidRequest $request, TrunkProvisioner $trunks)
    {
        $v = $request->validated();
        $did = Did::create($this->sipAttributes($v) + ['created_by' => $request->user()->id, 'balance' => (float) ($v['opening_balance'] ?? 0)]);
        app(DidBilling::class)->recordOpening($did, $request->user()->id);
        Audit::log('did.created', $did, null, null, $did->only('number', 'label', 'status', 'max_concurrent_calls', 'sip_host', 'sip_port', 'sip_username'));
        [$key, $msg] = $this->provision($did, $trunks);

        return redirect()->route('admin.dids.show', $did)->with($key, 'DID created. '.$msg);
    }

    public function sipApply(Did $did, TrunkProvisioner $trunks)
    {
        Gate::authorize('manage', $did);
        abort_unless($did->hasSip(), 404);
        [$key, $msg] = $this->provision($did, $trunks);
        Audit::log('did.sip_applied', $did, null, null, $did->only('sip_host', 'sip_port', 'sip_username'));

        return back()->with($key, $msg);
    }

    /** JSON status, polled by the DID page. Asks Asterisk each time so it is live. */
    public function sipStatus(Did $did, TrunkProvisioner $trunks)
    {
        Gate::authorize('manage', $did);
        abort_unless($did->hasSip(), 404);
        $trunks->refreshStatuses();
        $did->refresh();

        return response()->json([
            'status' => $did->sip_status ?? 'pending',
            'detail' => $did->sip_status_detail,
            'checked_at' => $did->sip_checked_at?->diffForHumans(),
        ]);
    }

    public function show(Request $request, Did $did)
    {
        Gate::authorize('manage', $did);

        return view('admin.dids.show', [
            'did' => $did->load('users'),
            'active' => $did->slots()->count(),
            'transactions' => $did->transactions()->with('author:id,name')->latest('id')->limit(25)->get(),
            'assignable' => $this->assignableUsers($request->user())->whereNotIn('users.id', $did->users->pluck('id'))->orderBy('name')->get(),
        ]);
    }

    /** Active normal users the viewer may hand a DID to: everyone for a Super Admin, only their own users for an Admin. */
    private function assignableUsers(User $viewer)
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', Role::USER))->where('status', 'active')
            ->when(! $viewer->isSuperAdmin(), fn ($q) => $q->whereIn('users.id', $viewer->managedUserIds()));
    }

    public function edit(Did $did)
    {
        Gate::authorize('manage', $did);

        return view('admin.dids.form', compact('did'));
    }

    public function update(StoreDidRequest $request, Did $did, TrunkProvisioner $trunks)
    {
        $old = $did->only('number', 'label', 'status', 'max_concurrent_calls', 'rate_per_pulse', 'pulse_seconds', 'trunk', 'sip_host', 'sip_port', 'sip_username');
        $did->update($this->sipAttributes($request->validated(), $did));
        $did->refresh();
        $action = $old['max_concurrent_calls'] != $did->max_concurrent_calls ? 'did.concurrency_changed' : 'did.updated';
        Audit::log($action, $did, null, $old, $did->only(array_keys($old)));
        [$key, $msg] = $this->provision($did, $trunks);

        return redirect()->route('admin.dids.show', $did)->with($key, 'DID updated. '.$msg);
    }

    /** Add or remove balance by hand (Super Admin, or the Admin who owns the DID). Every change is in the ledger + audit log. */
    public function adjustBalance(Request $request, Did $did, DidBilling $billing)
    {
        Gate::authorize('manage', $did);
        $data = $request->validate([
            'action' => ['required', 'in:add,deduct'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);
        $add = $data['action'] === 'add';
        $billing->adjust($did, $add ? (float) $data['amount'] : -(float) $data['amount'], $add ? 'topup' : 'deduct', $data['note'] ?? null, $request->user()->id);

        if ($add) { // campaigns that were waiting for balance start dialing right away
            Campaign::where('did_id', $did->id)->where('status', 'RUNNING')->pluck('id')->each(fn ($id) => DispatchCampaignCalls::dispatch($id));
        }

        return back()->with('status', ($add ? 'Balance added.' : 'Balance removed.').' New balance: '.Money::tk($did->balance));
    }

    public function toggle(Did $did, TrunkProvisioner $trunks)
    {
        Gate::authorize('manage', $did);
        $old = $did->status;
        $did->update(['status' => $old === 'active' ? 'inactive' : 'active']);
        Audit::log('did.status_changed', $did, null, ['status' => $old], ['status' => $did->status]);
        if ($did->hasSip()) {
            $trunks->apply();
            $trunks->refreshStatuses();
        }

        return back()->with('status', 'DID status changed.');
    }

    public function destroy(Did $did, TrunkProvisioner $trunks)
    {
        Gate::authorize('manage', $did);
        if (! $did->isDeletable()) {
            return back()->withErrors(['did' => 'DID is used by campaigns or active calls; deactivate it instead.']);
        }
        $snapshot = $did->only('number', 'label');
        $did->delete();
        Audit::log('did.deleted', 'Did', $did->id, $snapshot);
        $trunks->apply();

        return redirect()->route('admin.dids.index')->with('status', 'DID deleted.');
    }

    /** Many-to-many; one user per DID unless config broadcast.shared_did_assignment is enabled. */
    public function assign(Request $request, Did $did)
    {
        Gate::authorize('manage', $did);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        // An Admin can only hand the DID to a normal user they created.
        abort_unless($this->assignableUsers($request->user())->whereKey($data['user_id'])->exists(), 403);

        DB::transaction(function () use ($did, $data) {
            Did::whereKey($did->id)->lockForUpdate()->first();
            $user = User::findOrFail($data['user_id']);

            if (! config('broadcast.shared_did_assignment') && $did->users()->where('users.id', '!=', $user->id)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'This DID is already assigned to another user (shared assignment is disabled).']);
            }
            $did->users()->syncWithoutDetaching([$user->id]);
            Audit::log('did.assigned', $did, null, null, ['user_id' => $user->id]);
        });

        return back()->with('status', 'DID assigned.');
    }

    public function unassign(Did $did, User $user)
    {
        Gate::authorize('manage', $did);
        $inUse = $did->campaigns()->where('user_id', $user->id)->whereIn('status', ['PENDING_APPROVAL', 'APPROVED', 'QUEUED', 'RUNNING', 'PAUSED'])->exists();
        if ($inUse) {
            return back()->withErrors(['did' => 'User has active campaigns on this DID.']);
        }
        $did->users()->detach($user->id);
        Audit::log('did.unassigned', $did, null, ['user_id' => $user->id], null);

        return back()->with('status', 'Assignment removed.');
    }
}
