<?php

namespace App\Services\Forms;

use App\Models\ContactForm;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Field definitions of a contact form (what the owner configures) and validation of what a visitor sends. */
class ContactFormFields
{
    public const TYPES = ['text', 'email', 'tel', 'textarea', 'select'];

    /** what a field means for the CRM: contact name, WhatsApp number, e-mail, the message the AI answers; null = extra information */
    public const MAPS = ['name', 'phone', 'email', 'message'];

    public static function defaults(): array
    {
        return [
            ['key' => 'name', 'label' => __('Name'), 'type' => 'text', 'map' => 'name', 'required' => true, 'options' => [], 'placeholder' => ''],
            ['key' => 'phone', 'label' => __('WhatsApp'), 'type' => 'tel', 'map' => 'phone', 'required' => true, 'options' => [], 'placeholder' => '(00) 00000-0000'],
            ['key' => 'email', 'label' => __('Email'), 'type' => 'email', 'map' => 'email', 'required' => false, 'options' => [], 'placeholder' => ''],
            ['key' => 'message', 'label' => __('Message'), 'type' => 'textarea', 'map' => 'message', 'required' => false, 'options' => [], 'placeholder' => ''],
        ];
    }

    /**
     * Cleans the field rows typed in the builder.
     *
     * @throws ValidationException
     */
    public static function normalize(array $rows): array
    {
        $out = [];
        $used = [];
        foreach (array_values($rows) as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue; // empty row
            }
            $type = in_array($row['type'] ?? '', self::TYPES, true) ? $row['type'] : 'text';
            $map = in_array($row['map'] ?? '', self::MAPS, true) ? $row['map'] : null;
            if ($map && in_array($map, $used, true)) {
                $map = null; // each meaning only once
            }
            if ($map) {
                $used[] = $map;
                $type = ['phone' => 'tel', 'email' => 'email', 'message' => 'textarea'][$map] ?? $type;
            }

            $options = $type === 'select'
                ? array_values(array_filter(array_map(fn ($o) => Str::limit(trim($o), 80, ''), is_array($row['options'] ?? null) ? $row['options'] : preg_split('/\r\n|\r|\n/', (string) ($row['options'] ?? ''))), 'strlen'))
                : [];
            if ($type === 'select' && count($options) < 2) {
                throw ValidationException::withMessages(['fields' => __('A choice field needs at least two options.')]);
            }

            $key = $map ?: self::uniqueKey(Str::slug($label, '_') ?: 'field', array_column($out, 'key'));
            $out[] = [
                'key' => $key,
                'label' => Str::limit($label, 80, ''),
                'type' => $type,
                'map' => $map,
                'required' => !empty($row['required']) || $map === 'phone' && empty($row['optional']) && false,
                'options' => $options,
                'placeholder' => Str::limit(trim((string) ($row['placeholder'] ?? '')), 80, ''),
            ];
        }

        if (count($out) > (int) config('forms.max_fields', 20)) {
            throw ValidationException::withMessages(['fields' => __('A form can have at most :n fields.', ['n' => config('forms.max_fields', 20)])]);
        }
        if (!in_array('phone', $used, true) && !in_array('email', $used, true)) {
            throw ValidationException::withMessages(['fields' => __('Add a WhatsApp or an email field so the lead can be contacted.')]);
        }

        return $out;
    }

    private static function uniqueKey(string $key, array $taken): string
    {
        $base = $key = in_array($key, self::MAPS, true) ? $key . '_2' : $key;
        for ($i = 2; in_array($key, $taken, true); $i++) {
            $key = $base . '_' . $i;
        }

        return $key;
    }

    /**
     * Checks what a visitor sent.
     *
     * @return array<string,string> key => value ("phone" already normalized)
     * @throws ValidationException
     */
    public static function validateSubmission(ContactForm $form, array $input): array
    {
        $values = [];
        $errors = [];
        foreach ((array) $form->fields as $f) {
            $raw = $input[$f['key']] ?? '';
            $v = trim(is_scalar($raw) ? (string) $raw : '');
            $v = mb_substr($v, 0, $f['type'] === 'textarea' ? 5000 : 500);

            if ($v === '') {
                if (!empty($f['required'])) {
                    $errors[$f['key']] = __('Fill in :field.', ['field' => $f['label']]);
                }
                continue;
            }
            if ($f['type'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $errors[$f['key']] = __('Enter a valid email.');
                continue;
            }
            if ($f['type'] === 'tel') {
                $phone = self::normalizePhone($v);
                if (!$phone) {
                    $errors[$f['key']] = __('Enter a valid phone number with area code.');
                    continue;
                }
                $v = $phone;
            }
            if ($f['type'] === 'select' && !in_array($v, $f['options'] ?? [], true)) {
                $errors[$f['key']] = __('Choose one of the options.');
                continue;
            }
            $values[$f['key']] = $v;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /** Digits only, with the default country code when the number was typed without one. null = not a plausible number */
    public static function normalizePhone(string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', $raw);
        $d = preg_replace('/^00/', '', $d);
        $cc = (string) config('forms.default_country_code', '');
        if ($cc !== '' && !str_starts_with($d, $cc) && strlen($d) <= 11) {
            $d = ltrim($d, '0');
            $d = $cc . $d;
        }

        return strlen($d) >= 10 && strlen($d) <= 15 ? $d : null;
    }

    /** the value of the field that has this meaning (name / phone / email / message) */
    public static function mapped(ContactForm $form, array $values, string $map): ?string
    {
        foreach ((array) $form->fields as $f) {
            if (($f['map'] ?? null) === $map && ($values[$f['key']] ?? '') !== '') {
                return $values[$f['key']];
            }
        }

        return null;
    }
}
