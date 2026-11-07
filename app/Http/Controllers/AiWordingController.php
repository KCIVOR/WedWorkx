<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\Ai\AiWordingService;
use App\Services\WebsiteDetailsService;
use App\Support\RegistrationQuestions;
use App\Support\WebsiteQuestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Friendlier wording for the chat questions. The browser sends only WHICH question (a key). The server looks
 * up the scripted text itself, so this can never be used as a free general-purpose AI endpoint.
 */
class AiWordingController extends Controller
{
    /** Chatbot 1: the supplier is not known yet, so the first name and business name come from the browser. */
    public function registration(Request $request, AiWordingService $ai): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', Rule::in(RegistrationQuestions::keys())],
            'first_name' => ['nullable', 'string', 'max:60'],
            'business_name' => ['nullable', 'string', 'max:100'],
        ]);

        $first = isset($data['first_name']) ? explode(' ', trim($data['first_name']))[0] : null;
        $scripted = RegistrationQuestions::text($data['key'], $first);

        return $this->respond($ai, $scripted, ['first_name' => $first, 'business_name' => $data['business_name'] ?? null], 'registration');
    }

    /** Chatbot 2: everything comes from the database for the token's supplier, nothing from the browser but the key. */
    public function website(string $token, Request $request, AiWordingService $ai, WebsiteDetailsService $details): JsonResponse
    {
        $supplier = Supplier::where('invite_token', $token)->firstOrFail();
        $details->authorizeEdit($supplier); // same rule as the chat itself: valid invite, details still open

        $questions = collect(WebsiteQuestions::for($supplier->business_type, $supplier->business_name))->keyBy('key');
        $data = $request->validate(['key' => ['required', 'string', Rule::in($questions->keys()->all())]]);

        return $this->respond(
            $ai,
            $questions[$data['key']]['text'],
            ['first_name' => explode(' ', trim($supplier->name))[0], 'business_name' => $supplier->business_name],
            'website:'.substr($token, 0, 16),
        );
    }

    private function respond(AiWordingService $ai, string $scripted, array $context, string $chatId): JsonResponse
    {
        $text = $ai->reword($scripted, $context, $chatId);

        return response()->json(['text' => $text, 'reworded' => $text !== $scripted]);
    }
}
