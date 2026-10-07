<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDidRequest;
use App\Models\Did;
use App\Models\Role;
use App\Models\User;
use App\Services\Asterisk\AsteriskService;
use App\Services\Audit\Audit;
use App\Services\Did\DidSlotManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DidController extends Controller
{
    public function index(DidSlotManager $slots)
    {
        Gate::authorize('manage', Did::class);
        $dids = Did::with('users:id,name')->withCount('slots as active_calls')->orderBy('id')->paginate(25);

        return view('admin.dids.index', compact('dids'));
    }

    /** List trunks registered in Asterisk so the admin picks one instead of typing the dial string. */
    public function sync(AsteriskService $asterisk)
    {
        Gate::authorize('manage', Did::class);
        $trunks = $asterisk->trunks();
        $used = Did::whereNotNull('trunk')->pluck('trunk')->all();

        return view('admin.dids.sync', [
            'trunks' => $trunks,
            'dryRun' => $asterisk->isDryRun(),
            'used' => $used,
        ]);
    }

    public function importSynced(Request $request, AsteriskService $asterisk)
    {
        Gate::authorize('manage', Did::class);
        $trunks = collect($asterisk->trunks() ?? [])->pluck('name')->all();
        $data = $request->validate([
            'trunk' => ['required', 'string', Rule::in($trunks)],
            'number' => ['required', 'regex:/^\+?[0-9]{5,20}$/', 'unique:dids,number'],
            'label' => ['nullable', 'string', 'max:100'],
            'max_concurrent_calls' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $did = Did::create([
            'number' => $data['number'],
            'label' => $data['label'] ?? null,
            'max_concurrent_calls' => $data['max_concurrent_calls'],
            'status' => 'active',
            'trunk' => 'PJSIP/{number}@'.$data['trunk'],
        ]);
        Audit::log('did.created', $did, null, null, $did->only('number', 'label', 'status', 'max_concurrent_calls', 'trunk'));

        return redirect()->route('admin.dids.show', $did)->with('status', 'DID created from Asterisk trunk.');
    }

    public function create()
    {
        Gate::authorize('manage', Did::class);

        return view('admin.dids.form', ['did' => new Did(['status' => 'active', 'max_concurrent_calls' => 1])]);
    }

    public function store(StoreDidRequest $request)
    {
        $did = Did::create($request->validated());
        Audit::log('did.created', $did, null, null, $did->only('number', 'label', 'status', 'max_concurrent_calls'));

        return redirect()->route('admin.dids.show', $did)->with('status', 'DID created.');
    }

    public function show(Did $did)
    {
        Gate::authorize('manage', Did::class);

        return view('admin.dids.show', [
            'did' => $did->load('users'),
            'active' => $did->slots()->count(),
            'assignable' => User::whereHas('roles', fn ($q) => $q->where('name', Role::USER))->where('status', 'active')->whereNotIn('id', $did->users->pluck('id'))->orderBy('name')->get(),
        ]);
    }

    public function edit(Did $did)
    {
        Gate::authorize('manage', Did::class);

        return view('admin.dids.form', compact('did'));
    }

    public function update(StoreDidRequest $request, Did $did)
    {
        $old = $did->only('number', 'label', 'status', 'max_concurrent_calls', 'trunk');
        $did->update($request->validated());
        $action = $old['max_concurrent_calls'] != $did->max_concurrent_calls ? 'did.concurrency_changed' : 'did.updated';
        Audit::log($action, $did, null, $old, $did->only(array_keys($old)));

        return redirect()->route('admin.dids.show', $did)->with('status', 'DID updated.');
    }

    public function toggle(Did $did)
    {
        Gate::authorize('manage', Did::class);
        $old = $did->status;
        $did->update(['status' => $old === 'active' ? 'inactive' : 'active']);
        Audit::log('did.status_changed', $did, null, ['status' => $old], ['status' => $did->status]);

        return back()->with('status', 'DID status changed.');
    }

    public function destroy(Did $did)
    {
        Gate::authorize('manage', Did::class);
        if (! $did->isDeletable()) {
            return back()->withErrors(['did' => 'DID is used by campaigns or active calls; deactivate it instead.']);
        }
        $snapshot = $did->only('number', 'label');
        $did->delete();
        Audit::log('did.deleted', 'Did', $did->id, $snapshot);

        return redirect()->route('admin.dids.index')->with('status', 'DID deleted.');
    }

    /** Many-to-many; one user per DID unless config broadcast.shared_did_assignment is enabled. */
    public function assign(Request $request, Did $did)
    {
        Gate::authorize('manage', Did::class);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

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
        Gate::authorize('manage', Did::class);
        $inUse = $did->campaigns()->where('user_id', $user->id)->whereIn('status', ['PENDING_APPROVAL', 'APPROVED', 'QUEUED', 'RUNNING', 'PAUSED'])->exists();
        if ($inUse) {
            return back()->withErrors(['did' => 'User has active campaigns on this DID.']);
        }
        $did->users()->detach($user->id);
        Audit::log('did.unassigned', $did, null, ['user_id' => $user->id], null);

        return back()->with('status', 'Assignment removed.');
    }
}
