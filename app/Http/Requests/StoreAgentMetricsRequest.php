<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAgentMetricsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->attributes->has('agent_server');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'batch_uuid' => ['required', 'uuid'],
            'agent_version' => ['nullable', 'string', 'max:64'],
            'samples' => ['required', 'array', 'min:1', 'max:120'],
            'samples.*.collected_at' => ['required', 'date'],
            'samples.*.cpu_percent' => ['required', 'numeric', 'between:0,100'],
            'samples.*.memory_total_bytes' => ['required', 'integer', 'min:1'],
            'samples.*.memory_used_bytes' => ['required', 'integer', 'min:0'],
            'samples.*.disks' => ['sometimes', 'array', 'max:32'],
            'samples.*.disks.*.filesystem' => ['required', 'string', 'max:255'],
            'samples.*.disks.*.total_bytes' => ['required', 'integer', 'min:1'],
            'samples.*.disks.*.used_bytes' => ['required', 'integer', 'min:0'],
        ];
    }
}
