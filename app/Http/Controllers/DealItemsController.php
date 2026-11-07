<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealItem;
use App\Models\Product;
use App\Services\Pipeline\DealItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Items of a deal (JSON, used by the deal window of the pipeline). */
class DealItemsController extends Controller
{
    public function __construct(private DealItems $items)
    {
    }

    public function index(Request $request, int $deal): JsonResponse
    {
        return $this->payload($this->deal($request, $deal), $request);
    }

    public function store(Request $request, int $deal): JsonResponse
    {
        $d = $this->deal($request, $deal);
        $data = $request->validate([
            'product_id' => 'nullable|integer', 'name' => 'nullable|string|max:120', 'unit_price' => 'nullable|numeric|min:0|max:9999999999',
            'quantity' => 'nullable|numeric|min:0.01|max:999999', 'discount_pct' => 'nullable|numeric|min:0|max:100',
        ]);
        $this->items->add($d, array_filter($data, fn ($v) => $v !== null && $v !== ''));

        return $this->payload($d->fresh(), $request);
    }

    public function update(Request $request, int $deal, int $item): JsonResponse
    {
        $d = $this->deal($request, $deal);
        $i = DealItem::where('deal_id', $d->id)->where('user_id', $d->user_id)->findOrFail($item);
        $data = $request->validate([
            'name' => 'nullable|string|max:120', 'unit_price' => 'nullable|numeric|min:0|max:9999999999',
            'quantity' => 'nullable|numeric|min:0.01|max:999999', 'discount_pct' => 'nullable|numeric|min:0|max:100',
        ]);
        $this->items->update($i, array_filter($data, fn ($v) => $v !== null && $v !== ''));

        return $this->payload($d->fresh(), $request);
    }

    public function destroy(Request $request, int $deal, int $item): JsonResponse
    {
        $d = $this->deal($request, $deal);
        $this->items->remove(DealItem::where('deal_id', $d->id)->where('user_id', $d->user_id)->findOrFail($item));

        return $this->payload($d->fresh(), $request);
    }

    private function deal(Request $request, int $id): Deal
    {
        return Deal::where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function payload(Deal $deal, Request $request): JsonResponse
    {
        return response()->json([
            'items' => $deal->items->map(fn (DealItem $i) => ['id' => $i->id, 'name' => $i->name, 'unit_price' => (float) $i->unit_price, 'quantity' => (float) $i->quantity,
                'discount_pct' => (float) $i->discount_pct, 'total' => $i->total()])->values(),
            'total' => $this->items->total($deal),
            'value' => (float) $deal->value,
            'products' => Product::where('user_id', $request->user()->id)->where('active', true)->orderBy('name')->get(['id', 'name', 'price', 'unit']),
        ]);
    }
}
