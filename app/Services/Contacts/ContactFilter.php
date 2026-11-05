<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactField;
use Illuminate\Database\Eloquent\Builder;

/**
 * Conditions on contact data, the language of segments: [{field, op, value}].
 * field = score | consent_status | has_email | created | custom:<key>. Unknown fields / operators are dropped, never guessed.
 */
class ContactFilter
{
    public const OPS = [
        'text' => ['eq', 'neq', 'contains', 'filled', 'empty'],
        'number' => ['eq', 'neq', 'gte', 'lte', 'filled', 'empty'],
        'date' => ['eq', 'gte', 'lte', 'filled', 'empty'],
        'select' => ['eq', 'neq', 'filled', 'empty'],
        'boolean' => ['eq'],
    ];

    public function __construct(private CustomFields $fields)
    {
    }

    /** field => type for what the account can filter on */
    public function catalog(int $userId): array
    {
        $c = ['score' => 'number', 'consent_status' => 'select', 'has_email' => 'boolean', 'created' => 'date'];
        foreach ($this->fields->for($userId) as $f) {
            $c['custom:' . $f->key] = $f->type;
        }

        return $c;
    }

    /** @return array<int,array{field:string,op:string,value:?string}> */
    public function normalize(int $userId, ?array $conditions): array
    {
        $catalog = $this->catalog($userId);
        $out = [];
        foreach (array_slice((array) $conditions, 0, 10) as $c) {
            $field = (string) ($c['field'] ?? '');
            $op = (string) ($c['op'] ?? '');
            $value = isset($c['value']) && !is_array($c['value']) ? trim((string) $c['value']) : '';
            $type = $catalog[$field] ?? null;
            if (!$type || !in_array($op, self::OPS[$type], true)) {
                continue;
            }
            $needsValue = !in_array($op, ['filled', 'empty'], true);
            if ($needsValue && $value === '') {
                continue;
            }
            if ($type === 'number' && $needsValue && !is_numeric(str_replace(',', '.', $value))) {
                continue;
            }
            $out[] = ['field' => $field, 'op' => $op, 'value' => $needsValue ? $value : null];
        }

        return $out;
    }

    public function query(int $userId, array $conditions): Builder
    {
        return $this->apply(Contact::query()->where('contacts.user_id', $userId), $userId, $conditions);
    }

    public function apply(Builder $q, int $userId, array $conditions): Builder
    {
        $catalog = $this->catalog($userId);
        foreach ($this->normalize($userId, $conditions) as $c) {
            $type = $catalog[$c['field']];
            $v = $c['value'];
            $num = $type === 'number' && $v !== null ? (str_contains($v, ',') || str_contains($v, '.') ? (float) str_replace(',', '.', $v) : (int) $v) : null;

            match ($c['field']) {
                'score' => $this->column($q, 'contacts.score', $c['op'], $num),
                'consent_status' => $this->column($q, 'contacts.consent_status', $c['op'], $v),
                'has_email' => in_array(mb_strtolower((string) $v), ['1', 'true', 'yes', 'sim'], true)
                    ? $q->whereNotNull('contacts.email')->where('contacts.email', '!=', '')
                    : $q->where(fn ($w) => $w->whereNull('contacts.email')->orWhere('contacts.email', '')),
                'created' => $this->column($q, 'contacts.created_at', $c['op'], $v, true),
                default => $this->custom($q, substr($c['field'], 7), $type, $c['op'], $type === 'number' ? $num : $v),
            };
        }

        return $q;
    }

    private function column(Builder $q, string $col, string $op, mixed $v, bool $date = false): void
    {
        if ($date && in_array($op, ['eq', 'gte', 'lte'], true)) {
            $d = \Illuminate\Support\Carbon::parse($v);
            match ($op) {
                'eq' => $q->whereBetween($col, [$d->copy()->startOfDay(), $d->copy()->endOfDay()]),
                'gte' => $q->where($col, '>=', $d->startOfDay()),
                'lte' => $q->where($col, '<=', $d->endOfDay()),
            };

            return;
        }
        match ($op) {
            'eq' => $q->where($col, $v),
            'neq' => $q->where(fn ($w) => $w->where($col, '!=', $v)->orWhereNull($col)),
            'gte' => $q->where($col, '>=', $v),
            'lte' => $q->where($col, '<=', $v),
            'contains' => $q->where($col, 'like', '%' . $v . '%'),
            'filled' => $q->whereNotNull($col),
            'empty' => $q->whereNull($col),
        };
    }

    private function custom(Builder $q, string $key, string $type, string $op, mixed $v): void
    {
        $path = 'contacts.custom->' . $key; // $key is [a-z0-9_] by construction
        match ($op) {
            'eq' => $q->where($path, $type === 'boolean' ? (in_array(mb_strtolower((string) $v), ['1', 'true', 'yes', 'sim'], true) ? '1' : '0') : $v),
            'neq' => $q->where(fn ($w) => $w->where($path, '!=', $v)->orWhereNull($path)),
            'gte' => $q->where($path, '>=', $v),
            'lte' => $q->where($path, '<=', $v),
            'contains' => $q->where($path, 'like', '%' . $v . '%'),
            'filled' => $q->whereNotNull($path),
            'empty' => $q->whereNull($path),
        };
    }
}
