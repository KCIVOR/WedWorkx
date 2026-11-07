<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Services\AgentChatService;
use App\Services\Ai\WordingProvider;
use App\Services\SettingsService;
use App\Support\DevMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Throwable;

class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings, private AgentChatService $agentChats)
    {
    }

    public function show()
    {
        $campaign = Campaign::first();

        return Inertia::render('Admin/Settings', [
            'fields' => $this->settings->status(),
            'campaign' => $campaign ? [
                'price' => (float) $campaign->price,
                'invite_expiry_hours' => $campaign->invite_expiry_hours,
                'grace_minutes' => $campaign->grace_minutes,
                'slot_limit' => $campaign->slot_limit,
                'slots_taken' => min($campaign->slots_taken, $campaign->slot_limit),
                'editable' => $campaign->slots_taken === 0,
            ] : null,
            'agent' => [
                'online' => $this->agentChats->hermesOnline(),
                // Who is answering chats right now (the plugin or the bridge); empty while nobody is listening.
                'consumer' => $this->agentChats->hermesOnline() ? ($this->agentChats->leaseHolder()['name'] ?? null) : null,
                'seconds_ago' => $this->agentChats->lastSeen() ? now()->timestamp - $this->agentChats->lastSeen() : null,
                'window' => (int) config('agentchat.online_window'),
                'timeout' => (int) config('agentchat.reply_timeout'),
            ],
            'webhook_url' => route('webhooks.hitpay'),
            'dev_mode' => DevMode::enabled(),
            'app_env' => app()->environment(),
        ]);
    }

    /** Saves one group (email, hitpay, hermes, ai). A blank secret keeps what is saved; "clear" removes it. */
    public function update(Request $request, string $group)
    {
        abort_unless(in_array($group, SettingsService::GROUPS, true) && $group !== 'hermes', 404);

        $keys = collect(SettingsService::FIELDS)->filter(fn ($f) => $f[0] === $group)->keys();
        $rules = $keys->mapWithKeys(fn ($k) => [$k => SettingsService::rule($k)])->all();
        foreach ($keys->filter(fn ($k) => SettingsService::FIELDS[$k][3]) as $k) {
            $rules["clear_$k"] = ['nullable', 'boolean'];
        }
        $data = $request->validate($rules);

        $changed = [];
        foreach ($keys as $key) {
            $secret = SettingsService::FIELDS[$key][3];
            $value = $data[$key] ?? null;

            if ($secret) {
                if ($request->boolean("clear_$key")) {
                    $this->settings->put($key, null);
                    $changed[] = "$key (cleared)";
                } elseif (is_string($value) && trim($value) !== '') {
                    $this->settings->put($key, trim($value));
                    $changed[] = $key;
                }

                continue;
            }

            // Plain fields: a blank means "use the .env value again".
            if (SettingsService::FIELDS[$key][2] === 'bool') {
                $this->settings->put($key, $request->boolean($key) ? '1' : '0');
                $changed[] = $key;
            } elseif ($value === null || $value === '') {
                $this->settings->put($key, null);
            } else {
                $this->settings->put($key, (string) $value);
                $changed[] = $key;
            }
        }

        $this->settings->refresh();
        AuditLog::record('settings_saved', ['group' => $group, 'fields' => $changed]);

        return back()->with('admin_flash', ['tone' => 'success', 'message' => 'Saved.']);
    }

    /** Creates a fresh Hermes token and shows it once. The old one stops working at once. */
    public function hermesToken()
    {
        $this->settings->put('hermes_token', bin2hex(random_bytes(32)));
        $this->settings->refresh();
        $token = (string) config('hermes.token');

        AuditLog::record('hermes_token_generated');

        return back()->with('admin_token', $token)
            ->with('admin_flash', ['tone' => 'success', 'message' => 'New token created. Copy it now: it is not shown again.']);
    }

    public function clearHermesToken()
    {
        $this->settings->put('hermes_token', null);
        $this->settings->refresh();
        AuditLog::record('settings_saved', ['group' => 'hermes', 'fields' => ['hermes_token (cleared)']]);

        return back()->with('admin_flash', ['tone' => 'success', 'message' => 'The saved token was removed.']);
    }

    /** Price, invite hours and grace minutes. Only before anyone has registered: after that they would change the deal. */
    public function campaign(Request $request)
    {
        $campaign = Campaign::firstOrFail();

        if ($campaign->slots_taken > 0) {
            return back()->with('admin_flash', ['tone' => 'warning', 'message' => 'The campaign has started, so these values are locked.']);
        }

        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'invite_expiry_hours' => ['required', 'integer', 'between:1,168'],
            'grace_minutes' => ['required', 'integer', 'between:0,240'],
        ]);

        $campaign->update($data);
        AuditLog::record('campaign_saved', ['fields' => array_keys($data)]);

        return back()->with('admin_flash', ['tone' => 'success', 'message' => 'Campaign saved.']);
    }

    /** Safe connection checks. They report; they never change anything. */
    public function test(Request $request, string $what)
    {
        abort_unless(in_array($what, ['mail', 'hitpay', 'ai'], true), 404);

        $result = match ($what) {
            'mail' => $this->testMail($request),
            'hitpay' => $this->testHitPay(),
            'ai' => $this->testAi(),
        };

        AuditLog::record('settings_test', ['what' => $what, 'ok' => $result['ok']]);

        return back()->with('admin_test', ['what' => $what] + $result);
    }

    private function testMail(Request $request): array
    {
        $to = $request->user()->email;

        try {
            Mail::to($to)->send(new \App\Mail\AdminTestMail);
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => 'Could not send: '.Str::limit($this->clean($e->getMessage()), 220)];
        }

        return ['ok' => true, 'message' => "Sent to $to. Check the inbox (and spam)."];
    }

    private function testHitPay(): array
    {
        $key = config('hitpay.api_key');
        if (! $key) {
            return ['ok' => false, 'message' => 'No HitPay API key is set.'];
        }

        try {
            $response = Http::withHeaders(['X-BUSINESS-API-KEY' => $key, 'X-Requested-With' => 'XMLHttpRequest'])
                ->timeout(config('hitpay.timeout'))->get(config('hitpay.base_url').'/payment-requests', ['per_page' => 1]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach HitPay at '.config('hitpay.base_url').'.'];
        }

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'HitPay accepted the key.'];
        }

        return ['ok' => false, 'message' => in_array($response->status(), [401, 403], true)
            ? 'HitPay rejected the key. Check the key and the address (sandbox or live).'
            : 'HitPay answered with an unexpected error (HTTP '.$response->status().').'];
    }

    private function testAi(): array
    {
        if (! config('ai.api_key')) {
            return ['ok' => false, 'message' => 'No AI API key is set.'];
        }

        $reply = app(WordingProvider::class)->complete('Reply with the single word: ok', 'ping', 5, 8);

        return $reply
            ? ['ok' => true, 'message' => 'The AI provider answered.']
            : ['ok' => false, 'message' => 'The AI provider did not answer. Check the key and the model name.'];
    }

    /** Removes anything that looks like a credential from an error message before it is shown. */
    private function clean(string $message): string
    {
        return preg_replace('/(password|passwd|pwd|token|key)=\S+/i', '$1=***', $message);
    }
}
