<?php

namespace App\Http\Requests;

use App\Support\RegistrationInput;
use Illuminate\Foundation\Http\FormRequest;

class RegisterSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(RegistrationInput::normalize($this->all(), true));
    }

    public function rules(): array
    {
        return RegistrationInput::rules();
    }

    public function messages(): array
    {
        return RegistrationInput::messages();
    }
}
