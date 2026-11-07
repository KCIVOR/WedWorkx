<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\HermesOrderService;
use App\Support\WebsiteQuestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class HermesOrderController extends Controller
{
    public function __construct(private HermesOrderService $orders)
    {
    }

    /** GET /api/hermes/orders?status=paid */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(config('hermes.visible_statuses'))],
        ]);
        $status = $data['status'] ?? 'paid'; // default: orders waiting to be built

        $orders = $this->orders->visible()
            ->whereHas('supplier', fn ($q) => $q->where('status', $status))
            ->with('supplier')
            ->orderBy('paid_at')->orderBy('id')
            ->get();

        return response()->json(['data' => $orders->map(fn (Order $o) => $this->summary($o))->values()]);
    }

    /** GET /api/hermes/orders/{reference} */
    public function show(string $reference): JsonResponse
    {
        $order = $this->orders->find($reference);
        $order->load('supplier.websiteAnswer', 'supplier.uploads');
        $supplier = $order->supplier;
        $disk = Storage::disk(config('uploads.disk'));

        return response()->json(['data' => $this->summary($order) + [
            'current_stage' => $order->current_stage,
            'website_url' => $order->website_url,
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'supplier' => [
                'name' => $supplier->name,
                'email' => $supplier->email,
                'mobile' => $supplier->mobile,
                'business_name' => $supplier->business_name,
                'business_type' => $supplier->business_type,
                'city' => $supplier->city,
                'province' => $supplier->province,
                'social_link' => $supplier->social_link,
            ],
            'answers' => $supplier->websiteAnswer?->answers ?? (object) [],
            // Answers to the extra registration questions an admin added, with the wording that was asked.
            'registration_answers' => collect($supplier->extra_answers ?? [])->map(fn ($a) => ['key' => $a['key'], 'label' => $a['label'], 'answer' => $a['answer']])->values(),
            // The same answers with the question each one belongs to, in the order the supplier saw them.
            'questions' => collect(WebsiteQuestions::readable(
                WebsiteQuestions::forAnswer($supplier->websiteAnswer, $supplier),
                $supplier->websiteAnswer?->answers ?? [],
            ))->map(fn ($q) => ['key' => $q['key'], 'label' => $q['label'], 'type' => $q['type'], 'answer' => $q['value']])->values(),
            'uploads' => $supplier->uploads->map(fn ($u) => [
                'kind' => $u->kind,
                'name' => $u->original_name,
                'mime' => $u->mime,
                'size_kb' => $u->size_kb,
                'url' => $disk->url($u->path),
            ])->values(),
        ]]);
    }

    /** POST /api/hermes/orders/{reference}/stage  { "stage": 1-6, "note": "optional" } */
    public function stage(string $reference, Request $request): JsonResponse
    {
        $data = $request->validate([
            'stage' => ['required', 'integer', 'between:1,'.HermesOrderService::LAST_STAGE],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = $this->orders->setStage($reference, (int) $data['stage'], $data['note'] ?? null);

        return response()->json(['data' => $this->summary($order) + ['current_stage' => $order->current_stage]]);
    }

    /** POST /api/hermes/orders/{reference}/deliver  { "website_url": "https://..." } */
    public function deliver(string $reference, Request $request): JsonResponse
    {
        $data = $request->validate([
            'website_url' => ['required', 'string', 'max:500', 'url:http,https'],
        ]);

        $order = $this->orders->deliver($reference, $data['website_url']);

        return response()->json(['data' => $this->summary($order) + [
            'current_stage' => $order->current_stage,
            'website_url' => $order->website_url,
            'delivered_at' => $order->delivered_at->toIso8601String(),
        ]]);
    }

    private function summary(Order $o): array
    {
        return [
            'reference' => $o->reference,
            'business_name' => $o->supplier->business_name,
            'business_type' => $o->supplier->business_type,
            'status' => $o->supplier->status->value,
            'paid_at' => $o->paid_at->toIso8601String(),
        ];
    }
}
