<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRegistrationQuestionRequest;
use App\Models\RegistrationQuestion;
use App\Services\RegistrationQuestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Admin > Questions > Registration chat: add, edit, delete, restore and reorder the registration (Chatbot 1) questions. */
class RegistrationQuestionController extends Controller
{
    public function __construct(private RegistrationQuestionService $questions)
    {
    }

    public function store(SaveRegistrationQuestionRequest $request): RedirectResponse
    {
        $this->questions->create($request->question());

        return back()->with('admin_flash', ['tone' => 'success', 'message' => 'Question added at the end of the registration chat.']);
    }

    public function update(RegistrationQuestion $registrationQuestion, SaveRegistrationQuestionRequest $request): RedirectResponse
    {
        $this->questions->update($registrationQuestion, $request->question());

        return back()->with('admin_flash', ['tone' => 'success', 'message' => 'Question saved.']);
    }

    public function destroy(RegistrationQuestion $registrationQuestion): RedirectResponse
    {
        $answered = $this->questions->delete($registrationQuestion);
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
