<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

/** The catalog of what the account sells. */
class ProductsController extends Controller
{
    private const LIMIT = 500;

    public function index(Request $request)
    {
        return view('products.index', ['products' => Product::where('user_id', $request->user()->id)->orderByDesc('active')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $uid = $request->user()->id;
        if (Product::where('user_id', $uid)->count() >= self::LIMIT) {
            return back()->withErrors(['name' => __('Limit of :n products reached.', ['n' => self::LIMIT])]);
        }
        Product::create(['user_id' => $uid, 'active' => true] + $this->data($request));

        return back()->with('success', __('Product saved.'));
    }

    public function update(Request $request, int $product)
    {
        $p = Product::where('user_id', $request->user()->id)->findOrFail($product);
        $p->update($this->data($request) + ['active' => $request->boolean('active')]);

        return back()->with('success', __('Product saved.'));
    }

    public function destroy(Request $request, int $product)
    {
        // deals that already sold it keep their own copy of name and price
        Product::where('user_id', $request->user()->id)->findOrFail($product)->delete();

        return back()->with('success', __('Product removed.'));
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'sku' => 'nullable|string|max:40',
            'unit' => 'nullable|string|max:20',
            'price' => 'required|numeric|min:0|max:9999999999',
        ]);
    }
}
