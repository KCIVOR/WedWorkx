<?php

namespace App\Support;

use App\Models\Supplier;
use Illuminate\Validation\Rule;

final class WebsiteIntake
{
    private static function publicPhoneRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            if (! is_string($value) || PhoneNumber::normalizePublic($value) === null) {
                $fail('Please provide the complete phone number, including country or area code. Masked or incomplete numbers cannot be used.');
            }
        };
    }

    public static function fields(array $question): array
    {
        return $question['intake']['fields'] ?? [];
    }

    public static function activeFields(array $question, array $answers): array
    {
        $fields = self::fields($question);
        $byKey = array_column($fields, null, 'key');
        $visible = function (array $field, array $seen = []) use (&$visible, $byKey, $answers): bool {
            $when = $field['when'] ?? null;
            if (! $when) return true;
            $key = $when['field'];
            if (in_array($key, $seen, true)) return false;
            if (isset($byKey[$key]) && ! $visible($byKey[$key], [...$seen, $key])) return false;
            return in_array($answers[$key] ?? null, $when['in'] ?? [], true);
        };
        return array_values(array_filter($fields, fn ($f) => $visible($f)));
    }

    public static function rules(array $question, array $answers, ?Supplier $supplier = null): array
    {
        $active = array_column(self::activeFields($question, $answers), 'key');
        $rules = [];
        foreach (self::fields($question) as $field) {
            $key = $field['key'];
            if (! in_array($key, $active, true)) {
                $rules[$key] = $key === 'public_phone' ? ['nullable', 'string', self::publicPhoneRule()] : ['nullable'];
                continue;
            }
            $need = ($field['required'] ?? false) ? 'required' : 'present';
            $max = $field['max'] ?? 1000;
            switch ($field['type']) {
                case 'choice': $rules[$key] = [$need, 'string', Rule::in(array_column($field['options'] ?? [], 'value'))]; break;
                case 'services':
                    $rules[$key] = [$need, 'array', 'max:20'];
                    $rules[$key.'.*.name'] = ['required', 'string', 'max:120'];
                    $rules[$key.'.*.price'] = ['nullable', 'string', 'max:40'];
                    $rules[$key.'.*.details'] = ['nullable', 'string', 'max:2000']; break;
                case 'areas':
                    $rules[$key] = [$need, 'array', 'max:20'];
                    $rules[$key.'.*'] = ['required', 'string', 'max:80']; break;
                case 'uploads':
                    $rules[$key] = [$need, 'array', 'max:'.config('uploads.max_photos', 10)];
                    if (($answers['asset_plan'] ?? null) === 'now') {
                        $hasLogo = $supplier && $supplier->uploads()->where('kind', 'logo')->exists();
                        $rules[$key] = ['present', 'nullable', Rule::requiredIf(! $hasLogo), 'array', 'max:'.config('uploads.max_photos', 10)];
                    }
                    $rules[$key.'.*'] = ['required', 'integer', 'distinct', Rule::exists('uploads', 'id')->where(fn ($q) => $q->where('supplier_id', $supplier?->id ?? 0)->where('kind', 'photo'))]; break;
                default:
                    $rules[$key] = [$need, 'string', 'max:'.$max];
                    if ($field['type'] === 'email') $rules[$key][] = 'email:rfc';
                    if ($field['type'] === 'url') $rules[$key][] = 'url:http,https';
                    if ($key === 'description') $rules[$key][] = 'min:5';
                    if ($key === 'public_phone') $rules[$key][] = self::publicPhoneRule();
            }
        }
        foreach ($rules as $key => &$rule) {
            if (in_array('present', $rule, true)) $rule[] = 'nullable';
        }
        unset($rule);
        return $rules;
    }

    public static function normalize(array $input, array $question, bool $only): array
    {
        $out = [];
        $active = array_column(self::activeFields($question, $input), 'key');
        foreach (self::fields($question) as $field) {
            $key = $field['key'];
            $list = in_array($field['type'], ['services', 'areas', 'uploads'], true);
            if (! $only && ! in_array($key, $active, true)) { $out[$key] = $list ? [] : null; continue; }
            if (! array_key_exists($key, $input)) continue;
            $value = $input[$key];
            if (is_string($value)) $value = trim($value);
            if ($value === '') $value = null;
            if ($key === 'public_phone' && is_string($value)) $value = PhoneNumber::normalizePublic($value) ?? $value;
            if (is_array($value) && $field['type'] === 'areas') $value = array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $value), fn ($v) => $v !== ''));
            if (is_array($value) && $field['type'] === 'services') {
                $value = collect($value)
                    ->filter(fn ($s) => is_array($s) && is_string($s['name'] ?? null) && trim($s['name']) !== '')
                    ->map(fn ($s) => ['name' => trim($s['name']), 'price' => is_string($s['price'] ?? null) ? trim($s['price']) : ($s['price'] ?? '')] + (array_key_exists('details', $s) ? ['details' => is_string($s['details']) ? trim($s['details']) : $s['details']] : []))
                    ->values()->all();
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /** Remove public contact values when their publication has not been approved. */
    public static function approvedAnswers(array $answers): array
    {
        if (array_key_exists('public_contact_mode', $answers) && ($answers['public_contact_permission'] ?? null) !== 'approved') {
            foreach (['public_email', 'public_phone', 'public_socials', 'public_address'] as $key) $answers[$key] = null;
        }
        return $answers;
    }
}
