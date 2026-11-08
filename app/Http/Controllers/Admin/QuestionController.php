<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveQuestionRequest;
use App\Models\RegistrationQuestion;
use App\Models\WebsiteQuestion;
use App\Services\QuestionService;
use App\Services\RegistrationQuestionService;
use App\Support\BusinessTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Admin > Questions: add, edit, delete, restore and reorder the website (Chatbot 2) questions. */
class QuestionController extends Controller
{
    public function __construct(private QuestionService $questions, private RegistrationQuestionService $registration)
    {
    }

    public function index(): Response
    {
        $used = $this->questions->usedCounts();
        $regUsed = $this->registration->usedCounts();

        return Inertia::render('Admin/Questions', [
            'registration' => [
                'questions' => RegistrationQuestion::ordered()->map(fn (RegistrationQuestion $q) => [
                    'id' => $q->id, 'key' => $q->key, 'type' => $q->type, 'label' => $q->label, 'text' => $q->text,
                    'placeholder' => $q->placeholder, 'options' => $q->options, 'required' => $q->required,
                    'locked' => $q->locked, 'used_count' => $regUsed[$q->key] ?? 0,
                ])->values(),
                'deleted' => RegistrationQuestion::onlyTrashed()->orderByDesc('deleted_at')->get(['id', 'key', 'label', 'type'])->values(),
                'creatable' => RegistrationQuestion::CREATABLE,
                'max' => (int) config('admin.max_registration_questions'),
            ],
            'questions' => WebsiteQuestion::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (WebsiteQuestion $q) => [
                'id' => $q->id,
                'key' => $q->key,
                'type' => $q->type,
                'label' => $q->label,
                'text' => $q->text,
                'placeholder' => $q->placeholder,
                'options' => $q->options,
                'required' => $q->required,
                'applies_to' => $q->applies_to,
                'locked' => $q->locked,
                'intake_schema' => $q->intake_schema,
                'used_count' => $used[$q->key] ?? 0,
            ])->values(),
            'deleted' => WebsiteQuestion::onlyTrashed()->orderByDesc('deleted_at')->get(['id', 'key', 'label', 'type'])->values(),
            'types' => BusinessTypes::ALL,
            'creatable' => WebsiteQuestion::CREATABLE,
            'max' => (int) config('admin.max_questions'),
        ]);
    }

    public function store(SaveQuestionRequest $request): RedirectResponse
    {
        $this->questions->create($request->question());

        return back()->with('admin_flash', ['tone' => 'success', 'message' => 'Question added at the end of the list.']);
    }

    public function update(WebsiteQuestion $question, SaveQuestionRequest $request): RedirectResponse
    {
        $this->questions->update($question, $request->question());

        return back()->with('admin_flash', ['tone' => 'success', 'message' => 'Question saved.']);
    }

    public function destroy(WebsiteQuestion $question): RedirectResponse
    {
        $answered = $this->questions->delete($question);
        $note = $answered > 0
            ? " {$answered} ".($answered === 1 ? 'supplier' : 'suppliers').' had already answered it; their answers are kept.'
            : '';

        return back()->with('admin_flash', ['tone' => 'success', 'message' => 'Question deleted. You can restore it below.'.$note]);
    }

    public function restore(int $id): RedirectResponse
    {
        $this->questions->restore($id);

        return back()->with('admin_flash', ['tone' => 'success', 'message' => 'Question restored at the end of the list.']);
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']]);
        $this->questions->reorder($data['order']);

        return back()->with('admin_flash', ['tone' => 'success', 'message' => 'Order saved.']);
    }
}
