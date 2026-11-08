<?php

namespace App\Support;

use App\Models\Supplier;
use App\Models\WebsiteAnswer;
use App\Models\WebsiteQuestion;
use Illuminate\Validation\Rule;

/**
 * The website (Chatbot 2) questions for one supplier. They are rows in `website_questions`, edited in the admin
 * (Admin > Questions). This class is the single place that turns those rows into what the chat shows and what
 * the server checks, so the chat, the validation, the admin pages and Hermes always agree.
 */
final class WebsiteQuestions
{
    /** Where each special type keeps its answer. Every other type stores one answer under the question's key. */
    private const FIELDS = [
        'services' => ['services'],
        'areas' => ['service_areas', 'booking'],
        'media' => ['colors'],
    ];

    /**
     * The questions this supplier is asked, in order. `fields` are the keys inside the stored answers JSON.
     *
     * @return list<array<string,mixed>>
     */
    public static function for(string $businessType, string $businessName): array
    {
        return WebsiteQuestion::forType($businessType)
            ->map(fn (WebsiteQuestion $q) => self::shape($q, $businessName))
            ->all();
    }

    /** The questions saved with a supplier's answers when they confirmed, or today's questions for older answers. */
    public static function forAnswer(?WebsiteAnswer $answer, Supplier $supplier): array
    {
        return $answer?->question_snapshot ?: self::for($supplier->business_type, $supplier->business_name);
    }

    /** What is stored next to the answers, so later edits to a question never change what this supplier saw. */
    public static function snapshot(Supplier $supplier): array
    {
        return self::for($supplier->business_type, $supplier->business_name);
    }

    /**
     * Each question with its answer as plain text (null when skipped). Used by the admin page and the Hermes API.
     *
     * @return list<array{key:string,label:string,type:string,value:?string}>
     */
    public static function readable(array $questions, array $answers): array
    {
        $answers = WebsiteIntake::approvedAnswers($answers);
        return collect($questions)->map(function (array $q) use ($answers) {
            $parts = [];
            foreach (! empty($q['intake']) ? array_column(WebsiteIntake::activeFields($q, $answers), 'key') : $q['fields'] as $field) {
                $v = $answers[$field] ?? null;
                $descriptor = collect($q['intake']['fields'] ?? [])->firstWhere('key', $field);
                if (($descriptor['type'] ?? null) === 'choice' && is_string($v)) {
                    $v = collect($descriptor['options'] ?? [])->firstWhere('value', $v)['label'] ?? $v;
                }
                if ($q['type'] === 'choice' && is_string($v)) {
                    $v = collect($q['options'] ?? [])->firstWhere('value', $v)['label'] ?? $v;
                }
                if ($field === 'services' && is_array($v)) {
                    $v = collect($v)->map(fn ($s) => trim(($s['name'] ?? '').(! empty($s['price']) ? ' ('.$s['price'].')' : '').(! empty($s['details']) ? ': '.$s['details'] : '')))->implode(', ');
                } elseif (is_array($v)) {
                    $v = implode(', ', $v);
                }
                if ($v !== null && $v !== '') {
                    $parts[] = ($descriptor ? $descriptor['label'].': ' : '').(string) $v;
                }
            }

            return ['key' => $q['key'], 'label' => $q['label'], 'type' => $q['type'], 'value' => $parts ? implode(' · ', $parts) : null];
        })->values()->all();
    }

    /**
     * Cleans a flat answers payload: services and areas are tidied, plain answers are trimmed and an empty one becomes null.
     * With $only, services and areas are touched only if they were sent (for answers that arrive a few at a time).
     */
    public static function normalize(array $in, array $questions, bool $only = false): array
    {
        $clean = fn ($v) => is_string($v) ? trim($v) : $v;
        $out = [];
        $intakeQuestions = array_values(array_filter($questions, fn ($q) => ! empty($q['intake'])));
        $legacyQuestions = array_values(array_filter($questions, fn ($q) => empty($q['intake'])));
        foreach ($intakeQuestions as $q) $out = array_merge($out, WebsiteIntake::normalize($in, $q, $only));
        $questions = $legacyQuestions;

        if (collect($legacyQuestions)->contains('type', 'services') && (! $only || array_key_exists('services', $in))) {
            $out['services'] = collect($in['services'] ?? [])
                ->filter(fn ($s) => is_array($s) && trim((string) ($s['name'] ?? '')) !== '')
                ->map(fn ($s) => ['name' => trim($s['name']), 'price' => trim((string) ($s['price'] ?? ''))])
                ->values()->all();
        }
        if (collect($legacyQuestions)->contains('type', 'areas') && (! $only || array_key_exists('service_areas', $in))) {
            $out['service_areas'] = collect($in['service_areas'] ?? [])
                ->map($clean)->filter(fn ($a) => is_string($a) && $a !== '')->values()->all();
        }

        $plain = collect($questions)->pluck('fields')->flatten()->diff(['services', 'service_areas'])->unique();
        foreach ($plain as $k) {
            if (array_key_exists($k, $in)) {
                $v = $clean($in[$k]);
                $out[$k] = $v === '' ? null : $v;
            }
        }

        return $out;
    }

    /** Server-side rules for the flat answers payload, built from the same questions. */
    public static function rules(string $businessType, array $answers = [], ?Supplier $supplier = null, ?array $questions = null): array
    {
        $rules = [];

        foreach ($questions ?? self::for($businessType, '') as $q) {
            if (! empty($q['intake'])) {
                $rules = array_merge($rules, WebsiteIntake::rules($q, $answers, $supplier));
                continue;
            }
            $need = ($q['optional'] ?? !($q['required'] ?? false)) ? 'nullable' : 'required';

            switch ($q['type']) {
                case 'services':
                    $rules['services'] = ['nullable', 'array', 'max:20'];
                    $rules['services.*.name'] = ['required', 'string', 'max:120'];
                    $rules['services.*.price'] = ['nullable', 'string', 'max:40'];
                    break;
                case 'areas':
                    $rules['service_areas'] = ['nullable', 'array', 'max:20'];
                    $rules['service_areas.*'] = ['required', 'string', 'max:80'];
                    $rules['booking'] = ['nullable', 'string', 'max:200'];
                    break;
                case 'media':
                    $rules['colors'] = ['nullable', 'string', 'max:120'];
                    break;
                case 'choice':
                    $rules[$q['key']] = [$need, 'string', Rule::in(array_column($q['options'] ?? [], 'value'))];
                    break;
                case 'number':
                    $rules[$q['key']] = [$need, 'integer', 'min:1', 'max:100000'];
                    break;
                case 'text':
                    $rules[$q['key']] = [$need, 'string', 'max:200'];
                    break;
                default: // textarea
                    $rules[$q['key']] = array_merge(
                        [$need, 'string'],
                        $q['key'] === 'description' ? ['min:5'] : [],
                        ['max:'.($q['key'] === 'additional' ? 2000 : 1000)],
                    );
            }
        }

        return $rules;
    }

    private static function shape(WebsiteQuestion $q, string $businessName): array
    {
        $out = [
            'key' => $q->key,
            'label' => $q->label,
            'type' => $q->type,
            'fields' => self::FIELDS[$q->type] ?? [$q->key],
            'optional' => ! $q->required,
            'text' => str_replace('{business_name}', $businessName, $q->text),
        ];
        if ($q->placeholder !== null) {
            $out['placeholder'] = $q->placeholder;
        }
        if ($q->type === 'choice') {
            $out['options'] = $q->options ?? [];
        }

        if (is_array($q->intake_schema) && ! empty($q->intake_schema['fields'])) {
            $out['intake'] = $q->intake_schema;
            $out['fields'] = array_column($q->intake_schema['fields'], 'key');
        }

        return $out;
    }
}
