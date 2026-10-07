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
    public function index()
    {
        Gate::authorize('manage', User::class);

        return view('admin.users.index', ['users' => User::with('roles')->withCount('dids')->orderBy('id')->paginate(20)]);
    }

    public function create()
    {
        Gate::authorize('manage', User::class);

        return view('admin.users.form', ['user' => new User(['status' => 'active'])]);
    }

    public function store(StoreUserRequest $request)
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->only(['name', 'email', 'password', 'status']));
            $user->roles()->sync([Role::where('name', $request->role)->value('id')]);
            Audit::log('user.created', $user, null, null, $user->only('name', 'email', 'status') + ['role' => $request->role]);

            return $user;
        });

        return redirect()->route('admin.users.show', $user)->with('status', 'User created.');
    }

    public function show(User $user)
    {
        Gate::authorize('manage', User::class);

        return view('admin.users.show', ['user' => $user->load('roles', 'dids')]);
    }

    public function edit(User $user)
    {
        Gate::authorize('manage', User::class);

        return view('admin.users.form', ['user' => $user->load('roles')]);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        if ($user->id === $request->user()->id && ($request->status !== 'active' || $request->role !== Role::SUPER_ADMIN)) {
            return back()->withErrors(['status' => 'You cannot deactivate or demote yourself.']);
        }

        DB::transaction(function () use ($request, $user) {
            $old = $user->only('name', 'email', 'status') + ['role' => $user->roles->first()?->name];
            $data = $request->safe()->only(['name', 'email', 'status']);
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
        Gate::authorize('manage', User::class);
        abort_if($user->id === $request->user()->id, 422, 'You cannot deactivate yourself.');

        $old = $user->status;
        $user->update(['status' => $old === 'active' ? 'inactive' : 'active']);
        Audit::log('user.status_changed', $user, null, ['status' => $old], ['status' => $user->status]);

        return back()->with('status', 'User status changed.');
    }

    public function resetPassword(Request $request, User $user)
    {
        Gate::authorize('manage', User::class);
        $new = Str::password(16, symbols: false);
        $user->update(['password' => $new]);
        Audit::log('user.password_reset', $user);

        return back()->with('status', 'Temporary password (shown once): '.$new);
    }
}
