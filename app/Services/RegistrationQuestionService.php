<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\RegistrationQuestion;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules for editing the registration (Chatbot 1) questions, enforced on the server.
 * The 7 core questions map to the supplier's own fields, so they can only be reworded.
 */
class RegistrationQuestionService
{
    /** @param array{label:string,text:string,type:string,placeholder:?string,required:bool,options:array} $d */
    public function create(array $d): RegistrationQuestion
    {
        return DB::transaction(function () use ($d) {
            $all = $this->lockAll();

            if (! in_array($d['type'], RegistrationQuestion::CREATABLE, true)) {
                $this->fail('type', 'Pick short answer, number or choice.');
            }
            $this->assertCap($all);

            do {
                $key = 'q_'.bin2hex(random_bytes(4));
            } while (RegistrationQuestion::withTrashed()->where('key', $key)->exists());

            $q = RegistrationQuestion::create([
                'key' => $key,
                'type' => $d['type'],
                'label' => $d['label'],
                'text' => $d['text'],
                'placeholder' => $d['type'] === 'choice' ? null : ($d['placeholder'] ?: null),
                'options' => $d['type'] === 'choice' ? $this->buildOptions($d['options'], []) : null,
                'required' => $d['required'],
                'sort_order' => (int) ($all->max('sort_order') ?? 0) + 10,
                'locked' => false,
            ]);

            AuditLog::record('registration_question_created', ['key' => $q->key]);

            return $q;
        });
    }

    public function update(RegistrationQuestion $question, array $d): RegistrationQuestion
    {
        return DB::transaction(function () use ($question, $d) {
            $all = $this->lockAll();
            $q = $all->firstWhere('id', $question->id) ?? $question->fresh();
            $changed = [];

            if ($q->locked) {
                // Core questions: wording only. The type and whether an answer is needed stay as they are.
                if ($d['type'] !== $q->type || $d['required'] !== $q->required) {
                    $this->fail('question', 'This question is needed to register, so only its wording can be changed.');
                }
            } else {
                $used = $this->usedValues($q->key);
                if ($d['type'] !== $q->type) {
                    if ($used['count'] > 0) {
                        $this->fail('type', 'Suppliers have already answered this question, so its type cannot change.');
                    }
                    if (! in_array($d['type'], RegistrationQuestion::CREATABLE, true)) {
                        $this->fail('type', 'Pick short answer, number or choice.');
                    }
                    $q->type = $d['type'];
                    $changed[] = 'type';
                }

                $options = null;
                if ($d['type'] === 'choice') {
                    $options = $this->buildOptions($d['options'], $q->options ?? []);
                    $kept = array_column($options, 'value');
                    foreach ($used['values'] as $answered) {
                        if (! in_array($answered, $kept, true)) {
                            $this->fail('options', "The option \"{$answered}\" was chosen by a supplier, so it cannot be removed.");
                        }
                    }
                }
                if ($q->options !== $options) {
                    $q->options = $options;
                    $changed[] = 'options';
                }
                if ($d['required'] !== $q->required) {
                    $q->required = $d['required'];
                    $changed[] = 'required';
                }
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
                AuditLog::record('registration_question_updated', ['key' => $q->key, 'fields' => $changed]);
            }

            return $q;
        });
    }

    /** Soft delete. Returns how many suppliers had already answered it (their answers are kept). */
    public function delete(RegistrationQuestion $question): int
    {
        return DB::transaction(function () use ($question) {
            $this->lockAll();
            $q = $question->fresh();
            if ($q->locked) {
                $this->fail('question', 'This question is needed to register, so it cannot be deleted. You can edit its wording.');
            }
            $used = $this->usedValues($q->key)['count'];
            $q->delete();
            AuditLog::record('registration_question_deleted', ['key' => $q->key, 'answered' => $used]);

            return $used;
        });
    }

    public function restore(int $id): RegistrationQuestion
    {
        return DB::transaction(function () use ($id) {
            $all = $this->lockAll();
            $q = RegistrationQuestion::onlyTrashed()->findOrFail($id);
            $this->assertCap($all);

            $q->restore();
            $q->update(['sort_order' => (int) ($all->max('sort_order') ?? 0) + 10]);
            AuditLog::record('registration_question_restored', ['key' => $q->key]);

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
                RegistrationQuestion::whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
            }
            AuditLog::record('registration_questions_reordered', ['count' => count($ids)]);
        });
    }

    /**
     * How many suppliers answered this question, and which values they gave.
     *
     * @return array{count:int,values:list<string>}
     */
    public function usedValues(string $key): array
    {
        $count = 0;
        $values = [];
        foreach (Supplier::whereNotNull('extra_answers')->get(['extra_answers']) as $s) {
            foreach ($s->extra_answers ?? [] as $a) {
                if (($a['key'] ?? null) === $key) {
                    $count++;
                    $values[] = (string) ($a['value'] ?? '');
                }
            }
        }

        return ['count' => $count, 'values' => array_values(array_unique(array_filter($values, fn ($v) => $v !== '')))];
    }

    /** @return array<string,int> answered count for every extra question key */
    public function usedCounts(): array
    {
        $counts = [];
        foreach (Supplier::whereNotNull('extra_answers')->get(['extra_answers']) as $s) {
            foreach ($s->extra_answers ?? [] as $a) {
                $k = $a['key'] ?? null;
                if ($k !== null) {
                    $counts[$k] = ($counts[$k] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    private function lockAll()
    {
        return RegistrationQuestion::query()->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
    }

    private function assertCap($all): void
    {
        $max = (int) config('admin.max_registration_questions');
        if ($all->count() + 1 > $max) {
            $this->fail('question', "The registration chat can have at most {$max} questions. Delete one first.");
        }
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

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
