<?php

namespace App\Http\Controllers;

use App\Services\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return view('profile', ['user' => $request->user()->load('roles')]);
    }

    /** Every user can change their own password; the current one is required. */
    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12), 'different:current_password'],
        ]);

        $user = $request->user();
        $user->update(['password' => $data['password']]);
        $request->session()->regenerate();
        Audit::log('user.password_changed', $user);

        return redirect()->route('profile')->with('status', 'Password changed.');
    }
}
