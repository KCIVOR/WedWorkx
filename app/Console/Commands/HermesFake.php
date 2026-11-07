<?php

namespace App\Console\Commands;

use App\Support\DevMode;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * A stand-in for the Hermes agent, for testing the chat in dev mode. It talks to the real /api/hermes endpoints with
 * the real token and asks the fixed questions one by one. It is not smart: the real Hermes is meant to be conversational.
 */
class HermesFake extends Command
{
    protected $signature = 'hermes:fake {--once : Answer the waiting chats once and stop} {--interval=2 : Seconds between checks}';

    protected $description = 'Dev mode only: a simple stand-in for the Hermes agent that runs supplier chats';

    public function handle(): int
    {
        if (! DevMode::enabled()) {
            $this->error('This stand-in only runs in dev mode (DEV_MODE=true, never in production).');

            return self::FAILURE;
        }
        if (! config('hermes.token')) {
            $this->error('No Hermes token yet. Create one in Admin > Settings > Hermes agents.');

            return self::FAILURE;
        }

        $http = Http::withToken((string) config('hermes.token'))->acceptJson()->timeout(10)
            ->baseUrl(rtrim((string) config('app.url'), '/').'/api/hermes');

        $this->info('Stand-in Hermes is listening for chats. Press Ctrl+C to stop.');

        do {
            $waiting = $http->get('chats');
            if (! $waiting->successful()) {
                $this->error('The system answered '.$waiting->status().'. Check the token and APP_URL.');

                return self::FAILURE;
            }
            foreach ($waiting->json('data') as $row) {
                $this->answer($http, $row['chat_id']);
            }
            if (! $this->option('once')) {
                sleep(max(1, (int) $this->option('interval')));
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function answer(PendingRequest $http, string $id): void
    {
        $chat = $http->get("chats/$id")->json('data');
        if (! $chat || ! $chat['waiting_for_reply']) {
            return;
        }

        $questions = $chat['questions'];
        $collected = (array) $chat['collected'];
        $last = collect($chat['transcript'])->last();
        $pending = function () use ($questions, &$collected) {
            return collect($questions)->first(fn ($q) => ! array_key_exists($q['fields'][0], $collected));
        };

        // The first call greets and asks the first question.
        if (! $last) {
            $this->say($http, $id, 'Hi there! I will ask you a few quick questions. '.$questions[0]['text'], $questions[0]);
            $this->line("[$id] greeted");

            return;
        }

        $q = $pending();
        if ($q && $last['role'] === 'supplier') {
            [$payload, $problem] = $this->toAnswer($q, trim($last['text']));
            if ($problem) {
                $this->say($http, $id, $problem, $q);

                return;
            }

            $result = $http->post("chats/$id/answers", ['answers' => $payload])->json();
            if (! ($result['ok'] ?? false)) {
                $msg = collect((array) ($result['errors'] ?? []))->first() ?? 'Sorry, could you say that again?';
                $this->say($http, $id, $msg.' '.$q['text'], $q);
                $this->line("[$id] refused: $msg");

                return;
            }
            $collected += $payload;
        }

        $next = $pending();
        if ($next) {
            $this->say($http, $id, $next['text'], $next);
            $this->line("[$id] asked {$next['key']}");

            return;
        }

        $ready = $http->post("chats/$id/ready");
        if ($ready->successful()) {
            $http->post("chats/$id/messages", ['text' => 'Thank you! That is everything. Please check your details and confirm.']);
            $this->line("[$id] ready for the supplier's review");
        } else {
            $this->warn("[$id] not ready: ".json_encode($ready->json('missing')));
        }
    }

    private function say(PendingRequest $http, string $id, string $text, array $question): void
    {
        $body = ['text' => $text];
        if ($question['type'] === 'media') {
            $body['widget'] = 'upload'; // the supplier uses the upload buttons
        }
        $http->post("chats/$id/messages", $body);
    }

    /** @return array{0:array<string,mixed>,1:?string} the answers to send, or a problem to tell the supplier */
    private function toAnswer(array $q, string $text): array
    {
        $skip = ! $q['required'] && in_array(mb_strtolower($text), ['skip', 'none', 'no', '-'], true);
        $f = $q['fields'];

        return match ($q['type']) {
            'choice', 'business_type' => $this->pickOption($q, $text),
            'services' => [['services' => $skip ? [] : [['name' => $text, 'price' => '']]], null],
            'areas' => [['service_areas' => $skip ? [] : array_map('trim', explode(',', $text)), 'booking' => $skip ? '' : 'Messenger'], null],
            'media' => [['colors' => $skip ? '' : mb_substr($text, 0, 120)], null],
            default => [[$f[0] => $skip ? '' : $text], null],
        };
    }

    private function pickOption(array $q, string $text): array
    {
        $hit = collect($q['options'])->first(fn ($o) => mb_strtolower($o['label']) === mb_strtolower($text) || mb_strtolower($o['value']) === mb_strtolower($text))
            ?? collect($q['options'])->first(fn ($o) => str_contains(mb_strtolower($o['label']), mb_strtolower($text)) && $text !== '');

        return $hit
            ? [[$q['fields'][0] => $hit['value']], null]
            : [[], 'Please choose one of: '.collect($q['options'])->pluck('label')->implode(' / ').'.'];
    }
}
