<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterSupplierRequest;
use App\Services\SlotService;
use App\Services\AgentChatService;
use Illuminate\Http\Request;
use App\Support\BusinessTypes;
use App\Support\RegistrationQuestions;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Register', [
            'businessTypes' => collect(BusinessTypes::ALL)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'questions' => RegistrationQuestions::for(),
            'ai' => ['enabled' => (bool) config('ai.enabled')],
            'agent' => [
                'enabled' => app(AgentChatService::class)->mode('registration') === 'hermes',
                'resume' => app(AgentChatService::class)->resumable('registration', browserId: $request->cookie(AgentChatService::REGISTRATION_COOKIE)) !== null,
            ],
        ]);
    }

    public function store(RegisterSupplierRequest $request, SlotService $slots): RedirectResponse
    {
        $data = $request->validated();
        $extras = RegistrationQuestions::extras($data);
        // Only the supplier's own fields go in as they are; the answers to admin-added questions are kept with their labels.
        $result = $slots->register(\Illuminate\Support\Arr::only($data, ['name', 'email', 'mobile', 'business_name', 'business_type', 'location', 'social_link'])
            + ['extra_answers' => $extras ?: null], autoInvite: false);
        $chat = app(AgentChatService::class)->resumable('registration', browserId: $request->cookie(AgentChatService::REGISTRATION_COOKIE));
        if ($chat) $chat->update(['status' => 'done', 'waiting_since' => null]);

        // Approval guardrail: finishing Bot 1 sends nothing. The Bot 2 link goes out only when an admin
        // picks the supplier as a winner (Admin\SupplierController::approve).

        // The chat deliberately does not reveal invited vs waitlisted (T2 scope item 6).
        return redirect()->route('home')->with('registration', [
            'outcome' => $result['outcome'] === SlotService::ALREADY_REGISTERED ? 'already_registered' : 'registered',
            'name' => $request->validated('name'),
        ])->withCookie(cookie()->forget(AgentChatService::REGISTRATION_COOKIE));
    }
}
