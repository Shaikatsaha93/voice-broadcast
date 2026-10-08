<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('approve', $this->route('campaign'));
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:250']];
    }
}
