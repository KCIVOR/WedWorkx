<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Inertia\Inertia;

class AuditController extends Controller
{
    public function __invoke()
    {
        $page = AuditLog::with('user:id,name,email')->orderByDesc('id')->paginate(50);

        return Inertia::render('Admin/Audit', [
            'logs' => [
                'data' => $page->getCollection()->map(fn ($l) => [
                    'id' => $l->id, 'action' => $l->action, 'detail' => $l->detail, 'ip' => $l->ip,
                    'who' => $l->user?->email, 'at' => $l->created_at->toIso8601String(),
                ])->values(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
