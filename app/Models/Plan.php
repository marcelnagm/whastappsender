<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'slug', 'name', 'sort', 'price_cents', 'yearly_price_cents', 'currency', 'trial_days',
        'features', 'limits', 'is_active', 'gateway_ids',
    ];

    protected $casts = [
        'features' => 'array',
        'limits' => 'array',
        'gateway_ids' => 'array',
        'is_active' => 'boolean',
    ];

    public const FREE = 'free';

    public function isFree(): bool
    {
        return $this->price_cents <= 0;
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) (($this->features ?? [])[$feature] ?? false);
    }

    /** @return int|null null = unlimited */
    public function limit(string $key): ?int
    {
        $limits = $this->limits ?? [];

        if (!array_key_exists($key, $limits)) {
            return 0; // unknown limits are closed, not open
        }

        return $limits[$key] === null ? null : (int) $limits[$key];
    }

    public function priceFor(string $cycle): int
    {
        if ($cycle === 'yearly') {
            return (int) ($this->yearly_price_cents ?? $this->price_cents * (int) config('billing.yearly_months_charged', 10));
        }

        return (int) $this->price_cents;
    }

    public function supportsYearly(): bool
    {
        return !$this->isFree();
    }

    /** @return array<string,string> feature key => label (shown on pricing and billing screens) */
    public static function featureLabels(): array
    {
        return [
            'chat' => __('WhatsApp Chat (CRM)'),
            'ai_auto_reply' => __('AI auto-reply'),
            'welcome_campaign' => __('Welcome campaign (AI first contact)'),
            'warmup' => __('Chip warmup'),
            'api_access' => __('REST API'),
            'mcp_access' => __('MCP / AI agent tools'),
            'priority_support' => __('Priority support'),
        ];
    }

    /** @return array<string,string> limit key => label */
    public static function limitLabels(): array
    {
        return [
            'instances' => __('Connected numbers'),
            'contacts' => __('Contacts'),
            'messages_per_month' => __('Messages per month'),
            'ai_replies_per_month' => __('AI replies per month'),
        ];
    }

    public static function free(): self
    {
        return static::where('slug', self::FREE)->first() ?? static::syncFromConfig()->firstWhere('slug', self::FREE);
    }

    /**
     * Creates the plans of config/billing.php that are missing (or overwrites all of them with $force).
     *
     * @return \Illuminate\Support\Collection<int,Plan>
     */
    public static function syncFromConfig(bool $force = false)
    {
        foreach ((array) config('billing.plans', []) as $slug => $def) {
            $attributes = [
                'name' => $def['name'],
                'sort' => $def['sort'] ?? 0,
                'price_cents' => $def['price_cents'] ?? 0,
                'yearly_price_cents' => $def['yearly_price_cents'] ?? null,
                'currency' => config('billing.currency', 'BRL'),
                'trial_days' => $def['trial_days'] ?? (int) config('billing.trial_days', 0),
                'features' => $def['features'] ?? [],
                'limits' => $def['limits'] ?? [],
            ];

            $plan = static::where('slug', $slug)->first();
            if (!$plan) {
                static::create(['slug' => $slug, 'is_active' => true] + $attributes);
            } elseif ($force) {
                $plan->update($attributes);
            }
        }

        return static::orderBy('sort')->get();
    }
}
