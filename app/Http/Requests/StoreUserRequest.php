<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', \App\Models\User::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', Password::min(12)],
            // Super Admin: any role. Admin: normal users only (enforced here, not just hidden in the form).
            'role' => ['required', Rule::in($this->user()->assignableRoles())],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            // Super Admin only: put a normal user under a specific Admin.
            'owner_admin_id' => $this->user()->isSuperAdmin() ? ['nullable', 'integer', 'exists:users,id'] : ['prohibited'],
        ];
    }
}
