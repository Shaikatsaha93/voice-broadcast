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
            // Admin / User accounts: validity date is required; a new date cannot be in the past.
            'valid_until' => [Rule::requiredIf(fn () => \App\Models\User::roleNeedsValidity($this->input('role'))), 'nullable', 'date', fn ($a, $v, $fail) => $this->validityOk($v) ?: $fail('The validity date cannot be in the past.')],
            // Super Admin only: put a normal user under a specific Admin.
            'owner_admin_id' => ! $this->user()->isAdmin() ? ['nullable', 'integer', 'exists:users,id'] : ['prohibited'],
        ];
    }

    private function validityOk(mixed $value): bool
    {
        $user = $this->route('user');
        if (blank($value) || ($user instanceof \App\Models\User && $user->valid_until?->toDateString() === $value)) {
            return true; // empty, or unchanged on edit
        }

        return $value >= now()->toDateString();
    }

    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $v) {
            // An account whose validity is over cannot be set active without a new date.
            if ($this->input('status') === 'active' && filled($this->input('valid_until')) && $this->input('valid_until') < now()->toDateString()) {
                $v->errors()->add('valid_until', 'This validity date is over. Set a new date to keep the account active.');
            }
        }];
    }
}
