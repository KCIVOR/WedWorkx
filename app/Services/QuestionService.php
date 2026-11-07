<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\WebsiteAnswer;
use App\Models\WebsiteQuestion;
use App\Support\BusinessTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules for editing the website questions. Everything is enforced here, on the server, so a hidden
 * button in the page is never the only protection.
 */
class QuestionService
{
    /** @param array{label:string,text:string,type:string,placeholder:?string,required:bool,applies_to:?array,options:array} $d */
    public function create(array $d): WebsiteQuestion
    {
        return DB::transaction(function () use ($d) {
            $all = $this->lockAll();

            if (! in_array($d['type'], WebsiteQuestion::CREATABLE, true)) {
                $this->fail('type', 'Pick short text, long text, number or choice.');
            }
            $this->assertCap($all, $d['applies_to'], null);

            do {
                $key = 'q_'.bin2hex(random_bytes(4));
            } while (WebsiteQuestion::withTrashed()->where('key', $key)->exists());

            $options = $d['type'] === 'choice' ? $this->buildOptions($d['options'], []) : null;

            $q = WebsiteQuestion::create([
                'key' => $key,
                'type' => $d['type'],
                'label' => $d['label'],
                'text' => $d['text'],
                'placeholder' => $d['type'] === 'choice' ? null : ($d['placeholder'] ?: null),
                'options' => $options,
                'required' => $d['required'],
                'applies_to' => $d['applies_to'],
                'sort_order' => (int) ($all->max('sort_order') ?? 0) + 10,
                'locked' => false,
            ]);

            AuditLog::record('question_created', ['key' => $q->key]);

            return $q;
        });
    }

    public function update(WebsiteQuestion $question, array $d): WebsiteQuestion
    {
        return DB::transaction(function () use ($question, $d) {
            $all = $this->lockAll();
            $q = $all->firstWhere('id', $question->id) ?? $question->fresh();
            $changed = [];

            if ($q->locked) {
                $this->assertLockedUnchanged($q, $d);
                $options = $q->options;
                if ($q->type === 'choice') {
                    $options = $this->relabel($q->options, $d['options']); // only the wording of options can change
                }
            } else {
                $used = $this->usedValues($q->key);

                if ($d['type'] !== $q->type && $used['count'] > 0) {
                    $this->fail('type', 'Suppliers have already answered this question, so its type cannot change.');
                }
                if ($d['type'] !== $q->type && ! in_array($d['type'], WebsiteQuestion::CREATABLE, true)) {
                    $this->fail('type', 'Pick short text, long text, number or choice.');
                }

                $options = null;
                if ($d['type'] === 'choice') {
                    $options = $this->buildOptions($d['options'], $q->type === 'choice' ? ($q->options ?? []) : []);
                    $kept = array_column($options, 'value');
                    foreach ($used['values'] as $answered) {
                        if (! in_array($answered, $kept, true)) {
                            $this->fail('options', "The option \"{$answered}\" was chosen by a supplier, so it cannot be removed.");
                        }
                    }
                }

                if (! $this->sameScope($d['applies_to'], $q->applies_to)) {
                    $this->assertNoClash($all, $q, $d['applies_to']);
                    $this->assertCap($all, $d['applies_to'], $q->id);
                    $q->applies_to = $d['applies_to'];
                    $changed[] = 'applies_to';
                }
                if ($d['required'] !== $q->required) {
                    $q->required = $d['required'];
                    $changed[] = 'required';
                }
                if ($d['type'] !== $q->type) {
                    $q->type = $d['type'];
                    $changed[] = 'type';
                }
            }

            if ($q->options !== $options) {
                $q->options = $options;
                $changed[] = 'options';
            }
            foreach (['label', 'text'] as $f) {
                if ($d[$f] !== $q->{$f}) {
                    $q->{$f} = $d[$f];
                    $changed[] = $f;
                }
            }
            $placeholder = $q->type === 'choice' ? null : ($d['placeholder'] ?: null);
            if ($placeholder !== $q->placeholder) {
                $q->placeholder = $placeholder;
                $changed[] = 'placeholder';
            }

            $q->save();
            if ($changed) {
                AuditLog::record('question_updated', ['key' => $q->key, 'fields' => $changed]);
            }

            return $q;
        });
    }

    /** Soft delete. Returns how many suppliers had already answered it (their answers are kept). */
    public function delete(WebsiteQuestion $question): int
    {
        return DB::transaction(function () use ($question) {
            $this->lockAll();
            $q = $question->fresh();
            if ($q->locked) {
                $this->fail('question', 'This question is needed to build the website, so it cannot be deleted. You can edit its wording.');
            }
            $used = $this->usedValues($q->key)['count'];
            $q->delete();
            AuditLog::record('question_deleted', ['key' => $q->key, 'answered' => $used]);

            return $used;
        });
    }

    public function restore(int $id): WebsiteQuestion
    {
        return DB::transaction(function () use ($id) {
            $all = $this->lockAll();
            $q = WebsiteQuestion::onlyTrashed()->findOrFail($id);

            $this->assertNoClash($all, $q, $q->applies_to);
            $this->assertCap($all, $q->applies_to, null);

            $q->restore();
            $q->update(['sort_order' => (int) ($all->max('sort_order') ?? 0) + 10]);
            AuditLog::record('question_restored', ['key' => $q->key]);

            return $q;
        });
    }

    /** @param list<int> $ids every live question id, once each, in the new order */
    public function reorder(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            $all = $this->lockAll();
            $current = $all->pluck('id')->sort()->values()->all();
            $given = collect($ids)->map(fn ($i) => (int) $i)->sort()->values()->all();

            if ($current !== $given || count(array_unique($ids)) !== count($ids)) {
                $this->fail('order', 'The list of questions changed. Reload the page and try again.');
            }

            foreach (array_values($ids) as $i => $id) {
                WebsiteQuestion::whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
            }
            AuditLog::record('questions_reordered', ['count' => count($ids)]);
        });
    }

    /**
     * For each question key: how many suppliers answered it, and which choice values they gave.
     * Answers are counted in PHP: there can be at most one row per winner slot, so this stays small.
     *
     * @return array{count:int,values:list<string>}
     */
    public function usedValues(string $key): array
    {
        $count = 0;
        $values = [];
        foreach (WebsiteAnswer::whereNotNull('submitted_at')->get(['answers']) as $row) {
            if (array_key_exists($key, $row->answers ?? [])) {
                $count++;
                if (is_string($row->answers[$key]) && $row->answers[$key] !== '') {
                    $values[] = $row->answers[$key];
                }
            }
        }

        return ['count' => $count, 'values' => array_values(array_unique($values))];
    }

    /** @return array<string,int> answered count for every question key */
    public function usedCounts(): array
    {
        $counts = [];
        foreach (WebsiteAnswer::whereNotNull('submitted_at')->get(['answers']) as $row) {
            foreach (array_keys($row->answers ?? []) as $k) {
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
        }

        return $counts;
    }

    // ---------------------------------------------------------------- rules

    /** Locks every live question row, so two admins editing at once cannot break the clash and cap rules. */
    private function lockAll()
    {
        return WebsiteQuestion::query()->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
    }

    private function assertLockedUnchanged(WebsiteQuestion $q, array $d): void
    {
        $same = $d['type'] === $q->type && $d['required'] === $q->required && $this->sameScope($d['applies_to'], $q->applies_to);
        if ($same && $q->type === 'choice') {
            $same = collect($d['options'])->pluck('value')->filter()->values()->all() === array_column($q->options ?? [], 'value')
                && count($d['options']) === count($q->options ?? []);
        }
        if (! $same) {
            $this->fail('question', 'This question is needed to build the website, so only its wording can be changed.');
        }
    }

    private function sameScope(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        sort($a);
        sort($b);

        return $a === $b;
    }

    /** @param list<array{value:?string,label:string}> $incoming */
    private function relabel(array $current, array $incoming): array
    {
        $labels = collect($incoming)->pluck('label', 'value');

        return array_map(fn ($o) => ['value' => $o['value'], 'label' => $labels[$o['value']] ?? $o['label']], $current);
    }

    /**
     * Existing options keep their value when their label is edited. A new option's value is its label.
     *
     * @param list<array{value:?string,label:string}> $incoming
     * @param list<array{value:string,label:string}> $current
     */
    private function buildOptions(array $incoming, array $current): array
    {
        $known = array_column($current, 'value');
        $out = [];
        foreach ($incoming as $o) {
            $value = $o['value'] !== null && in_array($o['value'], $known, true) ? $o['value'] : $o['label'];
            if (in_array($value, array_column($out, 'value'), true)) {
                $this->fail('options', 'Each option must be different.');
            }
            $out[] = ['value' => $value, 'label' => $o['label']];
        }

        return $out;
    }

    /** Two live questions that can both apply to one business type may not share a key (they would share an answer). */
    private function assertNoClash($all, WebsiteQuestion $q, ?array $appliesTo): void
    {
        foreach ($all as $other) {
            if ($other->id === $q->id || $other->key !== $q->key) {
                continue;
            }
            $overlap = $appliesTo === null || $other->applies_to === null || array_intersect($appliesTo, $other->applies_to);
            if ($overlap) {
                $this->fail('applies_to', "That would clash with the question \"{$other->label}\", which stores its answer in the same place.");
            }
        }
    }

    /** No business type may be asked more than the allowed number of questions. */
    private function assertCap($all, ?array $appliesTo, ?int $ignoreId): void
    {
        $max = (int) config('admin.max_questions');
        foreach ($appliesTo ?? BusinessTypes::keys() as $type) {
            $count = $all->filter(fn ($o) => $o->id !== $ignoreId && $o->appliesTo($type))->count();
            if ($count + 1 > $max) {
                $label = BusinessTypes::ALL[$type] ?? $type;
                $this->fail('question', "{$label} would have more than {$max} questions. Delete or limit one first.");
            }
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
