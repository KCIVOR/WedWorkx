<?php

namespace App\Providers;

use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\WordingProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\SettingsService::class);

        // The AI backend behind AiWordingService. To use another provider, add a class that implements
        // WordingProvider and return it here for its AI_PROVIDER name.
        $this->app->bind(WordingProvider::class, fn () => match (config('ai.provider')) {
            default => new AnthropicProvider(
                config('ai.api_key'),
                config('ai.model'),
                config('ai.anthropic.base_url'),
                config('ai.anthropic.version'),
            ),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Settings saved on the admin page sit on top of .env (see SettingsService).
        $this->app->make(\App\Services\SettingsService::class)->apply();

        // Production is HTTPS only: every generated link (emails, HitPay redirect, assets) uses https.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Sign-ups, per client IP. Generous on purpose: a whole booth may share one Wi-Fi address.
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(config('campaign.register_limit'))->by($request->ip()));

        // The supplier chat with the Hermes agent. Each kind of request has its OWN counter (a plain throttle:N,M shares one
        // counter per visitor address across every route that uses it, so the chat page's polling used up the allowance for
        // starting a chat). Per chat where there is a chat id, so one busy chat never blocks another.
        RateLimiter::for('agent-start', fn (Request $request) => Limit::perMinute(60)->by('agent-start|'.$request->ip()));
        RateLimiter::for('agent-send', fn (Request $request) => Limit::perMinute(60)->by('agent-send|'.$request->ip().'|'.$request->route('id')));
        RateLimiter::for('agent-poll', fn (Request $request) => Limit::perMinute(120)->by('agent-poll|'.$request->ip().'|'.$request->route('id')));
        RateLimiter::for('agent-end', fn (Request $request) => Limit::perMinute(30)->by('agent-end|'.$request->ip().'|'.$request->route('id')));

        // Counted per client IP before the token is checked, so wrong-token guessing is limited too.
        RateLimiter::for('hermes', fn (Request $request) => Limit::perMinute(config('hermes.rate_limit'))->by($request->ip()));
    }
}
