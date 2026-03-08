<?php

namespace App\Http\Requests\Simulation;

use Illuminate\Foundation\Http\FormRequest;

class CompareJourneyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('view-simulation') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'route_batch_id' => ['required', 'integer', 'exists:route_batches,id'],
            'return_to_depot' => ['nullable', 'boolean'],
        ];
    }
}
