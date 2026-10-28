<?php

namespace App\Services\AI;

use App\Models\AiProfile;
use Carbon\Carbon;

/** Is the agent allowed to answer right now? Days/hours/timezone are per agent (= per instance, or all instances). */
class AiBusinessHours
{
    public static function isOpen(?AiProfile $profile = null, ?Carbon $now = null): bool
    {
        $c = config('ai.business_hours');
        $tz = ($profile?->timezone) ?: $c['timezone'];
        try {
            $now = ($now ?? Carbon::now())->copy()->setTimezone($tz);
        } catch (\Throwable $e) {
            $now = ($now ?? Carbon::now())->copy()->setTimezone($c['timezone']);
        }

        $days = self::days($profile?->business_days);
        $start = self::minutes($profile?->business_start, ((int) $c['start']) * 60);
        $end = self::minutes($profile?->business_end, ((int) $c['end']) * 60);
        $cur = $now->hour * 60 + $now->minute;

        if ($start === $end) {
            return in_array($now->dayOfWeekIso, $days, true); // whole day
        }
        if ($start < $end) {
            return in_array($now->dayOfWeekIso, $days, true) && $cur >= $start && $cur < $end;
        }

        // overnight window (e.g. 18:00-02:00): the after-midnight part belongs to the previous day's shift
        return ($cur >= $start && in_array($now->dayOfWeekIso, $days, true))
            || ($cur < $end && in_array($now->copy()->subDay()->dayOfWeekIso, $days, true));
    }

    /** @return int[] */
    public static function days(?string $csv): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $csv)), fn ($d) => $d >= 1 && $d <= 7)));

        return $days ?: [1, 2, 3, 4, 5];
    }

    private static function minutes(?string $hhmm, int $default): int
    {
        if (!$hhmm || !preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            return $default;
        }

        return min(23, (int) $m[1]) * 60 + min(59, (int) $m[2]);
    }
}
