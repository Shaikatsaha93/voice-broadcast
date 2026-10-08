<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /** Super Admin sees every account; an Admin sees normal users only. */
    public function index(Request $request)
    {
        Gate::authorize('manage', User::class);
        $users = User::with('roles', 'creator:id,name')->withCount('dids')
            ->when(! $request->user()->isSuperAdmin(), fn ($q) => $q->whereIn('users.id', $request->user()->managedUserIds()))
            ->orderBy('id')->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        Gate::authorize('manage', User::class);

        return view('admin.users.form', ['user' => new User(['status' => 'active']), 'admins' => $this->admins()]);
    }

    public function store(StoreUserRequest $request)
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->only(['name', 'email', 'password', 'status']) + ['created_by' => $this->ownerId($request)]);
            $user->roles()->sync([Role::where('name', $request->role)->value('id')]);
            Audit::log('user.created', $user, null, null, $user->only('name', 'email', 'status') + ['role' => $request->role]);

            return $user;
        });

        return redirect()->route('admin.users.show', $user)->with('status', 'User created.');
    }

    /** Admins a Super Admin can put a normal user under. */
    private function admins()
    {
        return User::whereHas('roles', fn ($r) => $r->where('name', Role::ADMIN))->orderBy('name')->get(['id', 'name']);
    }

    /**
     * The account that supervises a new normal user: whoever creates it. Only a Super Admin may
     * pick a different Admin (owner_admin_id); an Admin's requests with that field are rejected.
     */
    private function ownerId(Request $request): int
    {
        if ($request->user()->isSuperAdmin() && $request->filled('owner_admin_id') && $request->role === Role::USER) {
            $owner = User::findOrFail($request->owner_admin_id);
            abort_unless($owner->isAdmin(), 422, 'The selected owner is not an Admin.');

            return $owner->id;
        }

        return $request->user()->id;
    }

    public function show(User $user)
    {
        Gate::authorize('manage', $user);

        return view('admin.users.show', ['user' => $user->load('roles', 'dids')]);
    }

    public function edit(User $user)
    {
        Gate::authorize('manage', $user);

        return view('admin.users.form', ['user' => $user->load('roles'), 'admins' => $this->admins()]);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $currentRole = $user->roles->first()?->name;
        if ($user->id === $request->user()->id && ($request->status !== 'active' || $request->role !== $currentRole)) {
            return back()->withErrors(['status' => 'You cannot deactivate or change the role of your own account.']);
        }

        DB::transaction(function () use ($request, $user) {
            $old = $user->only('name', 'email', 'status') + ['role' => $user->roles->first()?->name];
            $data = $request->safe()->only(['name', 'email', 'status']);
            if ($request->filled('owner_admin_id')) {
                $data['created_by'] = $this->ownerId($request); // Super Admin moves a normal user under another Admin
            }
            if ($request->filled('password')) {
                $data['password'] = $request->password;
            }
            $user->update($data);
            $user->roles()->sync([Role::where('name', $request->role)->value('id')]);
            Audit::log($old['status'] !== $user->status ? 'user.status_changed' : 'user.updated', $user, null, $old, $user->only('name', 'email', 'status') + ['role' => $request->role]);
        });

        return redirect()->route('admin.users.show', $user)->with('status', 'User updated.');
    }

    public function toggle(Request $request, User $user)
    {
        Gate::authorize('manage', $user);
        abort_if($user->id === $request->user()->id, 422, 'You cannot deactivate yourself.');

        $old = $user->status;
        $user->update(['status' => $old === 'active' ? 'inactive' : 'active']);
        Audit::log('user.status_changed', $user, null, ['status' => $old], ['status' => $user->status]);

        return back()->with('status', 'User status changed.');
    }

    public function resetPassword(Request $request, User $user)
    {
        Gate::authorize('manage', $user);
        $new = Str::password(16, symbols: false);
        $user->update(['password' => $new]);
        Audit::log('user.password_reset', $user);

        return back()->with('status', 'Temporary password (shown once): '.$new);
    }
}
