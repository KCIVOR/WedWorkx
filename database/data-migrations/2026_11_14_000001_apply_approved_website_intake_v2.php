<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $catalog = json_decode(file_get_contents(database_path('data/website-intake-v2.json')), true, 512, JSON_THROW_ON_ERROR);
        if (count($catalog) !== 18) throw new RuntimeException('The approved eighteen-step common intake catalog is incomplete.');
        DB::transaction(function () use ($catalog) {
            foreach (DB::table('website_answers')->whereNotNull('submitted_at')->get(['question_snapshot']) as $answer) {
                $snapshot = json_decode($answer->question_snapshot ?? 'null', true);
                if (!is_array($snapshot) || $snapshot === []) throw new RuntimeException('Submitted answers need usable saved question snapshots before this upgrade.');
            }
            $all = DB::table('website_questions')->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
            $managed = [];
            foreach ($catalog as $definition) {
                $key = $definition['key'];
                $aliases = [$definition['label']];
                if ($key === 'description') $aliases[] = 'Business Description';
                if ($key === 'additional') $aliases[] = 'Additional Info';
                if ($key === 'media') $aliases[] = 'Photos, Logo & Colors';
                $aliases = array_map(fn ($s) => mb_strtolower(trim($s)), $aliases);
                $matches = $all->filter(fn ($q) => $q->key === $key || ($q->applies_to === null && in_array(mb_strtolower(trim($q->label)), $aliases, true)));
                if ($matches->count() > 1) throw new RuntimeException('Ambiguous question: '.$definition['label']);
                $existing = $matches->first();
                $expectedType = $definition['type'] ?? match ($key) { 'services' => 'services', 'areas' => 'areas', 'media' => 'media', 'style' => 'choice', default => 'textarea' };
                if ($existing && ($existing->deleted_at !== null || $existing->applies_to !== null || $existing->type !== $expectedType)) {
                    throw new RuntimeException('Incompatible or deleted question: '.$definition['label']);
                }
                if (!$existing && in_array($key, ['description','services','areas','media','style','additional'], true)) {
                    throw new RuntimeException('Missing existing core question: '.$key);
                }
                $schema = $definition['intake_schema'];
                $actualKey = $existing?->key ?? $key;
                foreach ($schema['fields'] as &$field) {
                    if ($field['key'] === $key) $field['key'] = $actualKey;
                    if (($field['when']['field'] ?? null) === $key) $field['when']['field'] = $actualKey;
                }
                unset($field);
                $values = [
                    'label' => $definition['label'], 'text' => $definition['text'], 'placeholder' => $definition['placeholder'],
                    'required' => $definition['required'], 'locked' => true, 'intake_schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
                if ($existing) {
                    $changed = collect($values)->contains(function ($v, $k) use ($existing) {
                        if ($k === 'required' || $k === 'locked') return (bool)$existing->{$k} !== (bool)$v;
                        if ($k === 'intake_schema') return json_decode($existing->{$k} ?? 'null', true) !== json_decode($v, true);
                        return $existing->{$k} !== $v;
                    });
                    $id = $existing->id;
                    if ($changed) DB::table('website_questions')->where('id', $id)->update($values + ['updated_at' => now()]);
                } else {
                    $id = DB::table('website_questions')->insertGetId($values + ['key' => $key, 'type' => $expectedType, 'options' => null, 'applies_to' => null, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
                }
                $managed[$definition['step']] = $id;
            }
            $extras = $all->whereNull('deleted_at')->whereNotIn('id', array_values($managed))->pluck('id')->all();
            $supplierFields = $all->whereNull('deleted_at')->whereIn('key', ['extra1','extra2'])->pluck('id')->all();
            $custom = array_values(array_diff($extras, $supplierFields));
            $order = [];
            foreach ($managed as $step => $id) {
                if ($step === 20) $order = array_merge($order, $custom);
                $order[] = $id;
                if ($step === 6) $order = array_merge($order, $supplierFields);
            }
            foreach (array_values(array_unique($order)) as $i => $id) {
                DB::table('website_questions')->where('id', $id)->where('sort_order', '!=', ($i + 1) * 10)->update(['sort_order' => ($i + 1) * 10, 'updated_at' => now()]);
            }
            $live = DB::table('website_questions')->whereNull('deleted_at')->get();
            foreach (['venue','coordinator','photographer','caterer','florist','makeup','attire','entertainment','cake','invitations','transportation','other'] as $type) {
                $questions = $live->filter(fn ($q) => $q->applies_to === null || in_array($type, json_decode($q->applies_to, true), true));
                if ($questions->count() > 20) throw new RuntimeException('The approved intake exceeds the twenty-step cap for '.$type.'. Review extra custom questions before retrying.');
                $seen = [];
                foreach ($questions as $q) {
                    $schema = json_decode($q->intake_schema ?? 'null', true);
                    $fields = $schema ? array_column($schema['fields'], 'key') : [$q->key];
                    foreach ($fields as $field) {
                        if (isset($seen[$field])) throw new RuntimeException('Conflicting answer field '.$field.' for '.$type);
                        $seen[$field] = true;
                    }
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Do not remove approved intake fields after clients may have answered them. Review definitions and newer answers before restoring a backup.');
    }
};
