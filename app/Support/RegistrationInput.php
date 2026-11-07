<?php

namespace App\Support;

use App\Models\RegistrationQuestion;
use Illuminate\Validation\Rule;

/**
 * How registration answers are cleaned and checked. One copy, used by the sign-up form request and by the
 * answers the Hermes agent sends, so both always get the same verdict.
 */
final class RegistrationInput
{
    private const SUPPLIER_FIELDS = ['name', 'email', 'mobile', 'business_name', 'business_type', 'location', 'social_link'];

    /** The registration fields that fill the supplier's own record. */
    public static function supplierFields(): array
    {
        return self::SUPPLIER_FIELDS;
    }

    /**
     * Cleans the answers. With $all, every core field is produced (a missing one becomes empty and fails "required");
     * otherwise only the keys that were sent are cleaned.
     */
    public static function normalize(array $in, bool $all = false): array
    {
        $has = fn (string $k) => $all || array_key_exists($k, $in);
        $str = fn (string $k) => trim((string) ($in[$k] ?? ''));
        $out = [];

        foreach (['name', 'business_name', 'location'] as $k) {
            if ($has($k)) {
                $out[$k] = $str($k);
            }
        }
        if ($has('email')) {
            $out['email'] = mb_strtolower($str('email'));
        }
        if ($has('mobile')) {
            $out['mobile'] = PhoneNumber::normalize($in['mobile'] ?? null) ?? $str('mobile');
        }
        if ($has('social_link')) {
            $social = $str('social_link');
            if ($social !== '' && ! preg_match('#^[a-z][a-z0-9+.\-]*://#i', $social)) {
                $social = 'https://'.$social; // "facebook.com/mybiz" is fine
            }
            $out['social_link'] = $social === '' ? null : $social;
        }

        // Answers to the admin-added questions: trimmed, and an empty one counts as skipped.
        foreach (array_keys(RegistrationQuestions::extraRules()) as $key) {
            if (array_key_exists($key, $in)) {
                $v = is_string($in[$key]) ? trim($in[$key]) : $in[$key];
                $out[$key] = $v === '' ? null : $v;
            }
        }

        return $out;
    }

    public static function rules(): array
    {
        return RegistrationQuestions::extraRules() + [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'mobile' => ['required', 'string', 'regex:/^\+639\d{9}$/'],
            'business_name' => ['required', 'string', 'min:2', 'max:150'],
            'business_type' => ['required', 'string', Rule::in(BusinessTypes::keys())],
            'location' => ['required', 'string', 'min:2', 'max:150'],
            'social_link' => ['nullable', 'string', 'max:500', 'url:http,https'],
        ];
    }

    public static function messages(): array
    {
        $extra = [];
        foreach (RegistrationQuestion::ordered()->where('type', '!=', 'core') as $q) {
            $extra[$q->key.'.required'] = $q->type === 'choice' ? 'Please choose an option.' : 'Please answer this question.';
            $extra[$q->key.'.in'] = 'Please choose one of the listed options.';
            $extra[$q->key.'.integer'] = 'Please enter a whole number.';
        }

        return $extra + [
            'name.min' => 'Please enter your name.',
            'name.required' => 'Please enter your name.',
            'email.required' => 'That email looks incomplete — please double check.',
            'email.email' => 'That email looks incomplete — please double check.',
            'mobile.required' => 'Please enter a valid mobile number.',
            'mobile.regex' => 'Please enter a valid mobile number, like 09171234567 or +639171234567.',
            'business_name.required' => 'Please enter your business name.',
            'business_name.min' => 'Please enter your business name.',
            'business_type.required' => 'Please choose an option.',
            'business_type.in' => 'Please choose one of the listed options.',
            'location.required' => 'Please enter your city or province.',
            'location.min' => 'Please enter your city or province.',
            'social_link.url' => 'That link does not look right — please check it or skip this question.',
        ];
    }
}
