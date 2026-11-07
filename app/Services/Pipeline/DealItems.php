<?php

namespace App\Services\Pipeline;

use App\Models\Deal;
use App\Models\DealItem;
use App\Models\Product;

/** What a deal sells. While a deal has items its value is their total; with no items the typed value stays. */
class DealItems
{
    public const MAX_ITEMS = 50;

    /** @param array{product_id?:?int,name?:?string,unit_price?:mixed,quantity?:mixed,discount_pct?:mixed} $in */
    public function add(Deal $deal, array $in): DealItem
    {
        abort_if($deal->items()->count() >= self::MAX_ITEMS, 422, __('Limit of :n items reached.', ['n' => self::MAX_ITEMS]));

        $product = !empty($in['product_id']) ? Product::where('user_id', $deal->user_id)->find((int) $in['product_id']) : null;
        $name = trim((string) ($in['name'] ?? '')) ?: $product?->name;
        abort_if($name === null || $name === '', 422, __('Choose a product or type a name.'));

        $item = new DealItem($this->clean($in) + [
            'name' => mb_substr($name, 0, 120),
            'unit_price' => $in['unit_price'] ?? $product?->price ?? 0, // the catalog price unless the seller typed another
        ]);
        $item->user_id = $deal->user_id;
        $item->deal_id = $deal->id;
        $item->product_id = $product?->id;
        $item->unit_price = max(0, (float) $item->unit_price);
        $item->save();
        $this->recalc($deal);

        return $item;
    }

    public function update(DealItem $item, array $in): DealItem
    {
        $item->fill($this->clean($in));
        if (isset($in['unit_price'])) {
            $item->unit_price = max(0, min(9999999999, (float) $in['unit_price']));
        }
        if (isset($in['name']) && trim((string) $in['name']) !== '') {
            $item->name = mb_substr(trim($in['name']), 0, 120);
        }
        $item->save();
        $this->recalc(Deal::findOrFail($item->deal_id));

        return $item;
    }

    public function remove(DealItem $item): void
    {
        $deal = Deal::findOrFail($item->deal_id);
        $item->delete();
        $this->recalc($deal);
    }

    public function total(Deal $deal): float
    {
        return round((float) $deal->items()->get()->sum(fn (DealItem $i) => $i->total()), 2);
    }

    /** Value of the deal follows its items; when the last item goes the value stays as it was. */
    public function recalc(Deal $deal): void
    {
        if ($deal->items()->exists()) {
            $deal->forceFill(['value' => min(9999999999, $this->total($deal))])->save();
        }
    }

    private function clean(array $in): array
    {
        $out = [];
        if (isset($in['quantity'])) {
            $out['quantity'] = max(0.01, min(999999, (float) $in['quantity']));
        }
        if (isset($in['discount_pct'])) {
            $out['discount_pct'] = max(0, min(100, (float) $in['discount_pct']));
        }

        return $out;
    }
}
