<?php

namespace App\Support;

use App\Models\RegistrationQuestion;
use Illuminate\Validation\Rule;

/**
 * The registration (Chatbot 1) questions. They are rows in `registration_questions`, edited in the admin
 * (Admin > Questions > Registration chat). The 7 core questions map to the supplier's own fields and can only be
 * reworded; extra questions an admin adds are saved on the supplier as `extra_answers`.
 * {name} in a text is the supplier's name once they have given it.
 */
final class RegistrationQuestions
{
    /** The questions in order, in the shape the chat page needs. */
    public static function for(): array
    {
        return RegistrationQuestion::ordered()->map(fn (RegistrationQuestion $q) => [
            'key' => $q->key,
            'label' => $q->label,
            'type' => $q->type,
            'core' => $q->type === 'core',
            'optional' => ! $q->required,
            'text' => $q->text,
            'placeholder' => $q->placeholder,
            'options' => $q->type === 'choice' ? ($q->options ?? []) : null,
        ])->all();
    }

    public static function keys(): array
    {
        return RegistrationQuestion::ordered()->pluck('key')->all();
    }

    public static function text(string $key, ?string $name = null): string
    {
        $text = RegistrationQuestion::where('key', $key)->value('text') ?? '';

        return str_replace('{name}', $name ?? 'there', $text);
    }

    /** Rules for the extra (admin-added) questions only. The core fields are checked in RegisterSupplierRequest. */
    public static function extraRules(): array
    {
        $rules = [];
        foreach (RegistrationQuestion::ordered()->where('type', '!=', 'core') as $q) {
            $need = $q->required ? 'required' : 'nullable';
            $rules[$q->key] = match ($q->type) {
                'choice' => [$need, 'string', Rule::in(array_column($q->options ?? [], 'value'))],
                'number' => [$need, 'integer', 'min:0', 'max:1000000'],
                default => [$need, 'string', 'max:200'],
            };
        }

        return $rules;
    }

    /**
     * The answers to the extra questions, ready to store on the supplier, in the order asked.
     * Each keeps its label, so later edits never change what was asked.
     *
     * @return list<array{key:string,label:string,type:string,value:string,answer:string}>
     */
    public static function extras(array $validated): array
    {
        $out = [];
        foreach (RegistrationQuestion::ordered()->where('type', '!=', 'core') as $q) {
            $value = $validated[$q->key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $value = (string) $value;
            $answer = $q->type === 'choice' ? (collect($q->options)->firstWhere('value', $value)['label'] ?? $value) : $value;
            $out[] = ['key' => $q->key, 'label' => $q->label, 'type' => $q->type, 'value' => $value, 'answer' => $answer];
        }

        return $out;
    }
}
