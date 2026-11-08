<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One website (Chatbot 2) question. Edited by admins; read by the chat, the checks, the admin pages and Hermes. */
class WebsiteQuestion extends Model
{
    use SoftDeletes;

    /** These stay: the site build and the chat depend on them. Their wording can still be edited. */
    public const LOCKED = ['description', 'services', 'areas', 'media', 'style'];

    /** Types an admin may create. services, areas and media exist once each. */
    public const CREATABLE = ['text', 'textarea', 'number', 'choice'];

    protected $fillable = ['key', 'type', 'label', 'text', 'placeholder', 'options', 'required', 'applies_to', 'sort_order', 'locked', 'intake_schema'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'applies_to' => 'array',
            'required' => 'boolean',
            'locked' => 'boolean',
            'sort_order' => 'integer',
            'intake_schema' => 'array',
        ];
    }

    /** True when this question is asked to suppliers of the given business type. */
    public function appliesTo(string $businessType): bool
    {
        return $this->applies_to === null || in_array($businessType, $this->applies_to, true);
    }

    /** Questions for one business type, in order. Soft-deleted rows are excluded. */
    public static function forType(string $businessType)
    {
        return static::query()->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (self $q) => $q->appliesTo($businessType))->values();
    }
}
