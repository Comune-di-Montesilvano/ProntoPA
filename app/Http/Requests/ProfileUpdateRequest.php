<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                // Unica tra ditte e scuole attive (locale+spid): può coincidere
                // con quella di un utente AD (v1.2).
                Rule::unique(User::class)
                    ->where(fn ($q) => $q->whereIn('auth_source', ['locale', 'spid'])->where('attivo', true))
                    ->ignore($this->user()->id),
            ],
        ];
    }
}
