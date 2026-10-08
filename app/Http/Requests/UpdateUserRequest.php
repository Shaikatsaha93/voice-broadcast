<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // An Admin may only edit normal users; a Super Admin anyone.
        return $this->user()->can('manage', $this->route('user'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'password' => ['nullable', Password::min(12)],
            'role' => ['required', Rule::in($this->user()->assignableRoles())],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            // Super Admin only: put a normal user under a specific Admin.
            'owner_admin_id' => $this->user()->isSuperAdmin() ? ['nullable', 'integer', 'exists:users,id'] : ['prohibited'],
        ];
    }
}
