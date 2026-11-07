<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Pipeline\DealItems;
use App\Services\Pipeline\SalesPipeline;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Product catalog and the items of a deal. */
class ProductsAndDealItemsTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $f = app(SalesPipeline::class);
        $this->deal = $f->open($f->defaultFor($this->owner), ['title' => 'Lead', 'value' => 999]);
    }

    private function product(string $name = 'Site', float $price = 1500): Product
    {
        return Product::create(['user_id' => $this->owner->id, 'name' => $name, 'price' => $price, 'active' => true]);
    }

    public function test_adding_items_sets_the_deal_value_to_their_total(): void
    {
        $svc = app(DealItems::class);
        $svc->add($this->deal, ['product_id' => $this->product('Site', 1500)->id, 'quantity' => 2, 'discount_pct' => 10]); // 2700
        $svc->add($this->deal, ['name' => 'Consultoria', 'unit_price' => 300.5, 'quantity' => 1]);                          // 300.50

        $this->assertEquals(3000.5, $this->deal->fresh()->value);
        $this->assertEquals(3000.5, $svc->total($this->deal));
    }

    public function test_item_keeps_a_snapshot_and_catalog_price_can_be_overridden(): void
    {
        $p = $this->product('Site', 1500);
        $a = app(DealItems::class)->add($this->deal, ['product_id' => $p->id]);
        $b = app(DealItems::class)->add($this->deal, ['product_id' => $p->id, 'unit_price' => 1000]);

        $p->update(['price' => 9999, 'name' => 'Outro']);

        $this->assertSame('Site', $a->fresh()->name);
        $this->assertEquals(1500, $a->fresh()->unit_price);
        $this->assertEquals(1000, $b->fresh()->unit_price);
        $p->delete();
        $this->assertSame(2, DealItem::count()); // deleting a product never removes sold items
    }

    public function test_update_remove_and_last_removal_keeps_the_value(): void
    {
        $svc = app(DealItems::class);
        $i = $svc->add($this->deal, ['name' => 'A', 'unit_price' => 100, 'quantity' => 1]);

        $svc->update($i, ['quantity' => 3, 'discount_pct' => 50]);
        $this->assertEquals(150, $this->deal->fresh()->value);

        $svc->remove($i->fresh());
        $this->assertEquals(150, $this->deal->fresh()->value); // no items left: the value is not wiped
    }

    public function test_values_are_clamped_and_requires_a_name_or_product(): void
    {
        $svc = app(DealItems::class);
        $i = $svc->add($this->deal, ['name' => 'X', 'unit_price' => -5, 'quantity' => 0, 'discount_pct' => 500]);

        $this->assertEquals(0, $i->fresh()->unit_price);
        $this->assertEquals(0.01, $i->fresh()->quantity);
        $this->assertEquals(100, $i->fresh()->discount_pct);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $svc->add($this->deal, ['unit_price' => 10]);
    }

    public function test_another_accounts_product_is_not_usable(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $theirs = Product::create(['user_id' => $other->id, 'name' => 'Secreto', 'price' => 10, 'active' => true]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class); // unknown product and no name
        app(DealItems::class)->add($this->deal, ['product_id' => $theirs->id]);
    }

    public function test_items_endpoints(): void
    {
        $this->actingAs($this->owner);
        $p = $this->product();

        $this->postJson(route('pipeline.deals.items.store', $this->deal->id), ['product_id' => $p->id, 'quantity' => 2])
            ->assertOk()->assertJsonPath('total', 3000)->assertJsonPath('value', 3000)->assertJsonPath('items.0.name', 'Site');
        $id = DealItem::first()->id;
        $this->putJson(route('pipeline.deals.items.update', [$this->deal->id, $id]), ['quantity' => 1])->assertJsonPath('total', 1500);
        $this->getJson(route('pipeline.deals.items.index', $this->deal->id))->assertOk()->assertJsonPath('products.0.name', 'Site');
        $this->postJson(route('pipeline.deals.items.store', $this->deal->id), ['unit_price' => 5])->assertStatus(422);
        $this->deleteJson(route('pipeline.deals.items.destroy', [$this->deal->id, $id]))->assertOk()->assertJsonPath('items', []);
    }

    public function test_editing_a_deal_with_items_cannot_override_the_value(): void
    {
        $this->actingAs($this->owner);
        app(DealItems::class)->add($this->deal, ['name' => 'A', 'unit_price' => 200]);

        $this->put(route('pipeline.deals.update', $this->deal->id), ['title' => 'Novo', 'value' => 1])->assertRedirect();

        $this->assertEquals(200, $this->deal->fresh()->value);
        $this->assertSame('Novo', $this->deal->fresh()->title);
    }

    public function test_other_accounts_deals_are_not_reachable(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $f = app(SalesPipeline::class);
        $d = $f->open($f->defaultFor($other), ['title' => 'X']);

        $this->actingAs($this->owner)->getJson(route('pipeline.deals.items.index', $d->id))->assertNotFound();
    }

    public function test_catalog_page_crud(): void
    {
        $this->actingAs($this->owner);

        $this->post(route('pipeline.products.store'), ['name' => 'Plano', 'price' => '49,90'])->assertSessionHasErrors('price');
        $this->post(route('pipeline.products.store'), ['name' => 'Plano', 'price' => '49.90', 'sku' => 'P1', 'unit' => 'mês'])->assertSessionHasNoErrors();
        $p = Product::firstOrFail();
        $this->get(route('pipeline.products.index'))->assertOk()->assertSee('Plano');

        $this->put(route('pipeline.products.update', $p->id), ['name' => 'Plano Pro', 'price' => 99])->assertSessionHasNoErrors();
        $this->assertSame('Plano Pro', $p->fresh()->name);
        $this->assertFalse($p->fresh()->active); // unchecked box = inactive
        $this->getJson(route('pipeline.deals.items.index', $this->deal->id))->assertJsonPath('products', []); // inactive not offered

        $this->delete(route('pipeline.products.destroy', $p->id));
        $this->assertSame(0, Product::count());
    }

    public function test_pipeline_page_still_renders_with_the_items_window(): void
    {
        $this->actingAs($this->owner)->get(route('pipeline.index'))->assertOk()->assertSee('itemsBox', false);
    }
}
