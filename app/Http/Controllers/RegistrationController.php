<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterSupplierRequest;
use App\Services\EmailService;
use App\Services\SlotService;
use App\Support\BusinessTypes;
use App\Support\RegistrationQuestions;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Register', [
            'businessTypes' => collect(BusinessTypes::ALL)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'questions' => RegistrationQuestions::for(),
            'ai' => ['enabled' => (bool) config('ai.enabled')],
            'agent' => ['enabled' => app(\App\Services\AgentChatService::class)->mode('registration') === 'hermes'],
        ]);
    }

    public function store(RegisterSupplierRequest $request, SlotService $slots, EmailService $emails): RedirectResponse
    {
        $data = $request->validated();
        $extras = RegistrationQuestions::extras($data);
        // Only the supplier's own fields go in as they are; the answers to admin-added questions are kept with their labels.
        $result = $slots->register(\Illuminate\Support\Arr::only($data, ['name', 'email', 'mobile', 'business_name', 'business_type', 'location', 'social_link'])
            + ['extra_answers' => $extras ?: null]);

        // After the slot transaction has committed. A mail failure is logged and never breaks registration.
        if ($result['supplier']) {
            $emails->sendRegistrationOutcome($result['supplier']);
        }

        // The chat deliberately does not reveal invited vs waitlisted (T2 scope item 6).
        return redirect()->route('home')->with('registration', [
            'outcome' => $result['outcome'] === SlotService::ALREADY_REGISTERED ? 'already_registered' : 'registered',
            'name' => $request->validated('name'),
        ]);
    }
}
