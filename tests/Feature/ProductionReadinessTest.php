<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function payload(int $n): array
    {
        return [
            'name' => "Supplier $n", 'email' => "supplier$n@example.com", 'mobile' => '0917'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'business_name' => "Business $n", 'business_type' => 'caterer', 'location' => 'Tagaytay City, Cavite',
        ];
    }

    public function test_registration_is_rate_limited_per_ip_and_the_limit_is_configurable(): void
    {
        $this->seed(CampaignSeeder::class);
        Mail::fake();
        config(['campaign.register_limit' => 3]);

        for ($i = 1; $i <= 3; $i++) {
            $this->post('/register', $this->payload($i))->assertRedirect('/');
        }
        $this->post('/register', $this->payload(4))->assertStatus(429);
        $this->assertDatabaseMissing('suppliers', ['email' => 'supplier4@example.com']);
    }

    public function test_the_default_registration_limit_fits_a_whole_booth_on_one_wifi(): void
    {
        $this->assertSame(30, config('campaign.register_limit'));
    }

    public function test_cors_is_closed_everywhere(): void
    {
        $origin = ['Origin' => 'https://evil.example'];

        foreach (['/', '/api/hermes/orders', '/webhooks/hitpay'] as $path) {
            $this->get($path, $origin)->assertHeaderMissing('Access-Control-Allow-Origin');
        }

        $this->call('OPTIONS', '/api/hermes/orders', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ])->assertHeaderMissing('Access-Control-Allow-Origin')->assertHeaderMissing('Access-Control-Allow-Methods');

        $this->assertSame([], config('cors.paths'));
        $this->assertSame([], config('cors.allowed_origins'));
    }

    public function test_production_forces_https_links(): void
    {
        $this->app['config']->set('app.env', 'production');
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue($this->app->isProduction());

        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', URL::to('/invite/abc'));
        $this->assertStringStartsWith('https://', route('invite.my-website', 'abc'));
    }

    public function test_the_design_proof_page_is_registered_outside_production_only(): void
    {
        // The only closure route of our own (the framework adds `up` and `storage/{path}`).
        $closures = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => $r->getAction('uses') instanceof \Closure)->map->uri()
            ->reject(fn ($uri) => in_array($uri, ['up', 'storage/{path}'], true))->values()->all();
        $this->assertSame(['design-check'], $closures); // present locally and in tests only

        $web = file_get_contents(base_path('routes/web.php'));
        $this->assertMatchesRegularExpression('/if \(! app\(\)->isProduction\(\)\) \{\s*Route::get\(\'\/design-check\'/', $web);
    }

    public function test_nothing_outside_config_calls_env_so_config_cache_is_safe(): void
    {
        $offenders = [];
        foreach (['app', 'routes', 'bootstrap', 'resources', 'database'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() === 'php' && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR)
                    && preg_match('/\benv\(/', file_get_contents($file->getPathname()))) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_pages_tell_search_engines_to_stay_away(): void
    {
        $this->get('/')->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        $robots = file_get_contents(public_path('robots.txt'));
        foreach (['/invite/', '/api/', '/webhooks/'] as $path) {
            $this->assertStringContainsString("Disallow: $path", $robots);
        }
    }

    public function test_the_production_env_template_is_safe_and_complete(): void
    {
        $env = [];
        foreach (file(base_path('.env.production.example'), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m)) {
                $env[$m[1]] = trim(preg_replace('/\s+#.*$/', '', $m[2]));
            }
        }

        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertStringStartsWith('https://', $env['APP_URL']);
        $this->assertSame('true', $env['SESSION_SECURE_COOKIE']);
        $this->assertSame('sync', $env['QUEUE_CONNECTION']);
        $this->assertSame('https://api.hit-pay.com/v1', $env['HITPAY_BASE_URL']);
        $this->assertSame('Asia/Manila', $env['APP_TIMEZONE']);
        $this->assertSame('false', $env['DEV_MODE']);

        // Every secret is a placeholder, never a real value.
        foreach (['APP_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD', 'HITPAY_API_KEY', 'HITPAY_SALT', 'HERMES_API_TOKEN', 'AI_API_KEY'] as $secret) {
            $this->assertContains($env[$secret], ['', 'CHANGE'], "$secret must be empty or CHANGE in the template");
        }

        // Every setting the app reads from .env is documented in the template.
        foreach (['HITPAY_API_KEY', 'HITPAY_SALT', 'HERMES_API_TOKEN', 'AI_WORDING_ENABLED', 'REGISTER_RATE_LIMIT', 'HERMES_RATE_LIMIT', 'MAIL_HOST', 'DB_DATABASE'] as $key) {
            $this->assertArrayHasKey($key, $env);
        }
    }

    public function test_htaccess_blocks_hidden_files_before_the_front_controller_and_forces_https(): void
    {
        $h = file_get_contents(public_path('.htaccess'));

        $front = strpos($h, 'RewriteRule ^ index.php [L]');
        foreach (['(^|/)\.(?!well-known/)', 'storage/.*\.(php', 'https://%{HTTP_HOST}%{REQUEST_URI}'] as $rule) {
            $pos = strpos($h, $rule);
            $this->assertNotFalse($pos, "missing rule: $rule");
            $this->assertLessThan($front, $pos, "$rule must come before the front controller rule");
        }
        $this->assertStringContainsString('X-Content-Type-Options', $h);
        $this->assertStringContainsString('Strict-Transport-Security', $h);
    }
}
