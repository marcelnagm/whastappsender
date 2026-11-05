<?php

namespace App\Services\Contacts;

use App\Models\ContactField;
use Illuminate\Support\Collection;

/** The account's own contact fields: which exist, and turning what a form / spreadsheet sends into clean stored values. */
class CustomFields
{
    /** @return Collection<int,ContactField> */
    public function for(int $userId): Collection
    {
        return ContactField::where('user_id', $userId)->orderBy('position')->orderBy('id')->get();
    }

    public static function slug(string $s): string
    {
        $s = \Illuminate\Support\Str::ascii(mb_strtolower(trim($s)));

        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '_', $s), '_'), 0, 40);
    }

    /**
     * Keeps only the account's fields, each coerced to its type; an empty or invalid value is dropped.
     * Numbers are stored as numbers, booleans as "1" / "0", dates as Y-m-d.
     *
     * @return array<string,mixed>
     */
    public function clean(int $userId, array $input): array
    {
        $out = [];
        foreach ($this->for($userId) as $f) {
            if (!array_key_exists($f->key, $input) || is_array($input[$f->key])) {
                continue;
            }
            $v = trim((string) $input[$f->key]);
            if ($v === '') {
                continue;
            }
            $value = match ($f->type) {
                'number' => is_numeric(str_replace(',', '.', $v)) ? $this->number(str_replace(',', '.', $v)) : null,
                'date' => $this->date($v),
                'boolean' => in_array(mb_strtolower($v), ['1', 'true', 'yes', 'sim', 'on', 's', 'y'], true) ? '1' : (in_array(mb_strtolower($v), ['0', 'false', 'no', 'nao', 'não', 'off', 'n'], true) ? '0' : null),
                'select' => in_array($v, (array) $f->options, true) ? $v : null,
                default => mb_substr($v, 0, 300),
            };
            if ($value !== null) {
                $out[$f->key] = $value;
            }
        }

        return $out;
    }

    private function number(string $v): int|float
    {
        return str_contains($v, '.') ? (float) $v : (int) $v;
    }

    private function date(string $v): ?string
    {
        try {
            return preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $v) ? \Illuminate\Support\Carbon::createFromFormat('d/m/Y', $v)->format('Y-m-d') : \Illuminate\Support\Carbon::parse($v)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Human text of a stored value (for the contact page). */
    public static function show(ContactField $f, mixed $v): string
    {
        if ($f->type === 'boolean') {
            return $v === '1' || $v === 1 || $v === true ? __('Yes') : __('No');
        }
        if ($f->type === 'date' && $v) {
            return \Illuminate\Support\Carbon::parse($v)->format('d/m/Y');
        }

        return (string) $v;
    }
}
