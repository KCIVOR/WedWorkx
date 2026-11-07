<?php

namespace App\Http\Requests\Admin;

use App\Models\WebsiteQuestion;
use App\Support\BusinessTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Input checks for adding or editing a website question. The business rules live in QuestionService. */
class SaveQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the `admin` middleware on the route is the gate
    }

    protected function prepareForValidation(): void
    {
        $options = collect($this->input('options', []))
            ->map(fn ($o) => is_array($o)
                ? ['value' => isset($o['value']) ? trim((string) $o['value']) : null, 'label' => trim((string) ($o['label'] ?? ''))]
                : ['value' => null, 'label' => trim((string) $o)])
            ->filter(fn ($o) => $o['label'] !== '')
            ->values()->all();

        $this->merge([
            'label' => is_string($this->input('label')) ? trim($this->input('label')) : $this->input('label'),
            'text' => is_string($this->input('text')) ? trim($this->input('text')) : $this->input('text'),
            'placeholder' => is_string($this->input('placeholder')) ? trim($this->input('placeholder')) : null,
            'options' => $options,
        ]);
    }

    public function rules(): array
    {
        $types = $this->isMethod('POST') ? WebsiteQuestion::CREATABLE : array_merge(WebsiteQuestion::CREATABLE, ['services', 'areas', 'media']);

        return [
            'label' => ['required', 'string', 'max:80'],
            'text' => ['required', 'string', 'max:500', function ($attr, $value, $fail) {
                if (preg_match('/\{(?!business_name\})[^}]*\}/', (string) $value)) {
                    $fail('Only {business_name} can be used inside curly brackets.');
                }
            }],
            'type' => ['required', Rule::in($types)],
            'placeholder' => ['nullable', 'string', 'max:160'],
            'required' => ['sometimes', 'boolean'],
            'scope' => ['required', Rule::in(['all', 'types'])],
            'types' => ['required_if:scope,types', 'array'],
            'types.*' => ['string', Rule::in(BusinessTypes::keys())],
            'options' => ['array', 'max:12'],
            'options.*.label' => ['required', 'string', 'max:80'],
            'options.*.value' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($this->input('scope') === 'types' && ! $this->input('types')) {
                $validator->errors()->add('types', 'Pick at least one business type, or choose all.');
            }
            if ($this->input('type') === 'choice') {
                $labels = collect($this->input('options'))->pluck('label')->map(fn ($l) => mb_strtolower($l));
                if ($labels->count() < 2) {
                    $validator->errors()->add('options', 'A choice question needs at least two options.');
                } elseif ($labels->unique()->count() !== $labels->count()) {
                    $validator->errors()->add('options', 'Each option must be different.');
                }
            }
        }];
    }

    /** The clean values the service expects. */
    public function question(): array
    {
        $data = $this->validated();

        return [
            'label' => $data['label'],
            'text' => $data['text'],
            'type' => $data['type'],
            'placeholder' => $data['placeholder'] ?? null,
            'required' => $this->boolean('required'),
            'applies_to' => $data['scope'] === 'all' ? null : array_values(array_unique($data['types'])),
            'options' => $data['type'] === 'choice' ? $data['options'] : [],
        ];
    }
}
