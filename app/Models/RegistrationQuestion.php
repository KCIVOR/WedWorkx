<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One registration (Chatbot 1) question. The 7 core ones keep their field and can only be reworded. */
class RegistrationQuestion extends Model
{
    use SoftDeletes;

    /** Types an admin may add. */
    public const CREATABLE = ['text', 'number', 'choice'];

    protected $fillable = ['key', 'type', 'label', 'text', 'placeholder', 'options', 'required', 'sort_order', 'locked'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'locked' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Live questions, in order. */
    public static function ordered()
    {
        return static::query()->orderBy('sort_order')->orderBy('id')->get();
    }
}
