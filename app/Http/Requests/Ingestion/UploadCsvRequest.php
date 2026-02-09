<?php

namespace App\Http\Requests\Ingestion;

use Illuminate\Foundation\Http\FormRequest;

class UploadCsvRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:invoices,drivers'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ];
    }
}
