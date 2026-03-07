<?php

namespace App\Http\Requests\Planning;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanningScenarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_date' => ['required', 'date_format:Y-m-d'],
            'depot_id' => ['required', 'integer', 'exists:depots,id'],
        ];
    }
}
