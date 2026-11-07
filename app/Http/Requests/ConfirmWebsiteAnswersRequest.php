<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use App\Services\WebsiteDetailsService;
use App\Support\WebsiteQuestions;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmWebsiteAnswersRequest extends FormRequest
{
    public function supplier(): Supplier
    {
        return $this->attributes->get('supplier')
            ?? tap(Supplier::where('invite_token', (string) $this->route('token'))->firstOrFail(), fn ($s) => $this->attributes->set('supplier', $s));
    }

    public function authorize(): bool
    {
        // Forbidden wins over validation errors: an expired, paid or locked supplier gets a 403, not a form error.
        return app(WebsiteDetailsService::class)->editable($this->supplier());
    }

    protected function prepareForValidation(): void
    {
        $this->merge(WebsiteQuestions::normalize($this->all(), $this->questions()));
    }

    public function rules(): array
    {
        return WebsiteQuestions::rules($this->supplier()->business_type);
    }

    /** Only keys defined for this supplier's questions are stored. */
    public function answers(): array
    {
        $top = array_filter(array_keys($this->rules()), fn ($k) => ! str_contains($k, '.'));

        return $this->safe()->only($top);
    }

    /** The questions this supplier is asked (their business type's). */
    private function questions(): array
    {
        $s = $this->supplier();

        return $this->attributes->get('questions')
            ?? tap(WebsiteQuestions::for($s->business_type, $s->business_name), fn ($q) => $this->attributes->set('questions', $q));
    }

    public function messages(): array
    {
        $messages = [
            'description.required' => 'A short description helps us build your website — please add a little more detail.',
            'description.min' => 'A short description helps us build your website — please add a little more detail.',
        ];

        foreach ($this->questions() as $q) {
            if ($q['key'] === 'description') {
                continue;
            }
            if ($q['type'] === 'choice') {
                $messages[$q['key'].'.required'] = 'Please choose an option.';
                $messages[$q['key'].'.in'] = 'Please choose one of the listed options.';
            } elseif (! $q['optional']) {
                $messages[$q['key'].'.required'] = 'Please answer this question.';
            }
        }

        return $messages;
    }
}
