<?php

use App\Models\Supplier;
use App\Models\WebsiteAnswer;
use App\Support\WebsiteQuestions;
use Illuminate\Database\Migrations\Migration;

/**
 * Answers confirmed before the snapshot existed get one now, made from the questions as they are at this moment
 * (on a first deploy: the original questions). From here on, a question added later is not shown on their page.
 */
return new class extends Migration
{
    public function up(): void
    {
        WebsiteAnswer::whereNotNull('submitted_at')->whereNull('question_snapshot')->get()->each(function (WebsiteAnswer $a) {
            $supplier = Supplier::find($a->supplier_id);
            if ($supplier) {
                $a->forceFill(['question_snapshot' => WebsiteQuestions::snapshot($supplier)])->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: an extra saved copy does no harm.
    }
};
