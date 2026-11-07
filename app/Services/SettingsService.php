<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Settings saved from the admin page. They sit on top of .env: a saved value wins, no saved value
 * means the .env value (already in config) is used. Secrets are encrypted with APP_KEY and are
 * never sent back to the browser.
 */
class SettingsService
{
    /**
     * key => [group, config path, type, secret?, label]
     * type: string | int | bool | url | email | select:<a>|<b>
     */
    public const FIELDS = [
        'mail_host' => ['email', 'mail.mailers.smtp.host', 'string', false, 'SMTP host'],
        'mail_port' => ['email', 'mail.mailers.smtp.port', 'int', false, 'SMTP port'],
        'mail_encryption' => ['email', 'mail.mailers.smtp.scheme', 'select:ssl|tls', false, 'Connection'],
        'mail_username' => ['email', 'mail.mailers.smtp.username', 'string', false, 'SMTP username'],
        'mail_password' => ['email', 'mail.mailers.smtp.password', 'string', true, 'SMTP password'],
        'mail_from_address' => ['email', 'mail.from.address', 'email', false, 'From address'],
        'mail_from_name' => ['email', 'mail.from.name', 'string', false, 'From name'],
        'hitpay_api_key' => ['hitpay', 'hitpay.api_key', 'string', true, 'HitPay API key'],
        'hitpay_salt' => ['hitpay', 'hitpay.salt', 'string', true, 'HitPay webhook salt'],
        'hitpay_base_url' => ['hitpay', 'hitpay.base_url', 'url', false, 'HitPay API address'],
        'hermes_token' => ['hermes', 'hermes.token', 'string', true, 'Hermes API token'],
        'ai_enabled' => ['ai', 'ai.enabled', 'bool', false, 'AI wording'],
        'ai_api_key' => ['ai', 'ai.api_key', 'string', true, 'AI API key'],
        'ai_model' => ['ai', 'ai.model', 'string', false, 'AI model'],
        'chat_mode_registration' => ['chat', 'agentchat.mode.registration', 'select:system|hermes', false, 'Registration chat is asked by'],
        'chat_mode_website' => ['chat', 'agentchat.mode.website', 'select:system|hermes', false, 'Website chat is asked by'],
    ];

    public const GROUPS = ['email', 'hitpay', 'hermes', 'ai', 'chat'];

    /** The config value each saved setting replaced (the .env one), so removing a saved value can put it back. */
    private array $original = [];

    /** Applies every saved value to config. Never throws: a missing table or unreadable value is skipped. */
    public function apply(): void
    {
        try {
            $rows = DB::table('settings')->pluck('value', 'key');
        } catch (Throwable) {
            return; // no database yet, or the table is not migrated yet: .env values stay in charge
        }

        foreach (self::FIELDS as $key => [$group, $path, $type, $secret]) {
            if (! $rows->has($key) || $rows[$key] === null) {
                continue;
            }

            $value = $secret ? $this->decrypt($rows[$key]) : $rows[$key];
            if ($value === null) {
                continue;
            }

            if (! array_key_exists($path, $this->original)) {
                $this->original[$path] = config($path);
            }
            Config::set($path, $this->cast($type, $value));
        }

        if ($rows->has('mail_host') && $rows['mail_host'] !== null) {
            Config::set('mail.default', 'smtp'); // an SMTP host saved here means "send through it"
        }
    }

    /** Saves (or, with null, removes) one value. */
    public function put(string $key, ?string $value): void
    {
        [, $path, , $secret] = self::FIELDS[$key];

        if ($value === null) {
            DB::table('settings')->where('key', $key)->delete();
            if (array_key_exists($path, $this->original)) { // put the .env value back for the rest of this request
                Config::set($path, $this->original[$path]);
                unset($this->original[$path]);
            }

            return;
        }

        DB::table('settings')->updateOrInsert(['key' => $key], [
            'value' => $secret ? Crypt::encryptString($value) : $value,
            'is_secret' => $secret,
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** Re-applies settings after a save so the current request (and a test email) already use them. */
    public function refresh(): void
    {
        $this->apply();
        Mail::forgetMailers();
    }

    /**
     * What the page shows. Non-secret fields carry their effective value; secret fields only say whether
     * one exists and where it comes from.
     *
     * @return array<string, array{value:mixed,secret:bool,source:string,label:string}>
     */
    public function status(): array
    {
        $saved = DB::table('settings')->pluck('value', 'key');
        $out = [];

        foreach (self::FIELDS as $key => [$group, $path, $type, $secret, $label]) {
            $hasSaved = $saved->has($key) && $saved[$key] !== null && ($secret ? $this->decrypt($saved[$key]) !== null : true);
            $effective = config($path);

            $present = $effective !== null && $effective !== '' && $effective !== false;
            $source = $hasSaved ? 'admin' : ($present ? 'env' : 'unset');

            if ($key === 'mail_encryption') {
                $effective = $effective === 'smtps' ? 'ssl' : 'tls';
            }

            $out[$key] = [
                'value' => $secret ? null : (is_bool($effective) ? $effective : ($effective === null ? '' : (string) $effective)),
                'secret' => $secret,
                'source' => $source,
                'label' => $label,
            ];
        }

        return $out;
    }

    /** Laravel validation rules for one field. */
    public static function rule(string $key): array
    {
        $type = self::FIELDS[$key][2];

        return match (true) {
            $type === 'int' => ['nullable', 'integer', 'between:1,65535'],
            $type === 'bool' => ['nullable', 'boolean'],
            $type === 'url' => ['nullable', 'url:https', 'max:200'],
            $type === 'email' => ['nullable', 'email', 'max:160'],
            str_starts_with($type, 'select:') => ['nullable', 'in:'.str_replace('|', ',', substr($type, 7))],
            default => ['nullable', 'string', 'max:'.(self::FIELDS[$key][3] ? 500 : 160)],
        };
    }

    private function cast(string $type, string $value): mixed
    {
        return match (true) {
            $type === 'int' => (int) $value,
            $type === 'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            $type === 'select:ssl|tls' => $value === 'ssl' ? 'smtps' : null,
            default => $value,
        };
    }

    private function decrypt(string $stored): ?string
    {
        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null; // APP_KEY changed since it was saved: treated as not set, re-enter it
        }
    }
}
