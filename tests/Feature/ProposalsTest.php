<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AutomationNotice;
use App\Services\Pipeline\DealItems;
use App\Services\Pipeline\Proposals;
use App\Services\Pipeline\SalesPipeline;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Quotes built from deal items, answered on a public page. */
class ProposalsTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->owner = User::create(['name' => 'Acme Ltda', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $f = app(SalesPipeline::class);
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '5595999990000']);
        $this->deal = $f->open($f->defaultFor($this->owner), ['title' => 'Site novo', 'contact_id' => $c->id]);
        app(DealItems::class)->add($this->deal, ['name' => 'Site', 'unit_price' => 1500, 'quantity' => 2, 'discount_pct' => 10]);
    }

    private function proposal(): Proposal
    {
        return app(Proposals::class)->createFor($this->deal->fresh());
    }

    public function test_it_snapshots_the_items_and_numbers_per_account(): void
    {
        $a = $this->proposal();
        $b = $this->proposal();

        $this->assertSame([1, 2], [$a->number, $b->number]);
        $this->assertEquals(2700, $a->total);
        $this->assertSame('Site', $a->items[0]['name']);
        $this->assertSame(40, strlen($a->token));
        $this->assertNotSame($a->token, $b->token);

        app(DealItems::class)->add($this->deal, ['name' => 'Extra', 'unit_price' => 100]); // later changes do not touch the sent quote
        $this->assertEquals(2700, $a->fresh()->total);
        app(Proposals::class)->refresh($a);
        $this->assertEquals(2800, $a->fresh()->total);
    }

    public function test_needs_items(): void
    {
        $f = app(SalesPipeline::class);
        $empty = $f->open($f->defaultFor($this->owner), ['title' => 'Vazio']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(Proposals::class)->createFor($empty);
    }

    public function test_customer_opening_marks_viewed_once_and_owner_preview_does_not(): void
    {
        Notification::fake();
        $p = $this->proposal();

        $this->actingAs($this->owner)->get($p->url() . '?preview=1')->assertOk()->assertSee('Preview');
        $this->assertSame('draft', $p->fresh()->status);

        auth()->logout();
        $this->get($p->url())->assertOk()->assertSee('Acme Ltda')->assertSee('2.700,00')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertSame('viewed', $p->fresh()->status);
        $at = $p->fresh()->viewed_at;
        $this->get($p->url());
        $this->assertEquals($at, $p->fresh()->viewed_at);
        Notification::assertSentToTimes($this->owner, AutomationNotice::class, 1);
    }

    public function test_customer_accepts_and_it_is_final(): void
    {
        Notification::fake();
        $p = $this->proposal();

        $this->post(route('proposals.public.accept', $p->token), ['name' => 'An', 'agree' => '1'])->assertSessionHasErrors('name'); // too short
        $this->post(route('proposals.public.accept', $p->token), ['name' => 'Ana Souza'])->assertSessionHasErrors('agree');
        $this->post(route('proposals.public.accept', $p->token), ['name' => 'Ana Souza', 'agree' => '1'])->assertRedirect();

        $p = $p->fresh();
        $this->assertSame('accepted', $p->status);
        $this->assertSame('Ana Souza', $p->answered_by);
        $this->assertNotNull($p->answered_ip);
        $this->post(route('proposals.public.decline', $p->token), [])->assertStatus(409);  // cannot change the answer
        $this->post(route('proposals.public.accept', $p->token), ['name' => 'Outra Pessoa', 'agree' => '1'])->assertStatus(409);
        $this->get($p->url())->assertSee('Ana Souza');
        $this->actingAs($this->owner)->put(route('proposals.update', $p->id), ['title' => 'Hack'])->assertStatus(422);
        Notification::assertSentTo($this->owner, AutomationNotice::class);
    }

    public function test_decline_with_reason_and_expiry(): void
    {
        $p = $this->proposal();
        $this->post(route('proposals.public.decline', $p->token), ['reason' => 'Muito caro'])->assertRedirect();
        $this->assertSame('declined', $p->fresh()->status);
        $this->assertSame('Muito caro', $p->fresh()->decline_reason);

        $old = $this->proposal();
        $old->update(['valid_until' => now()->subDay()]);
        $this->post(route('proposals.public.accept', $old->token), ['name' => 'Ana Souza', 'agree' => '1'])->assertStatus(410);
        $this->get($old->url())->assertOk()->assertSee('expired');
        $this->assertSame('viewed', $old->fresh()->status);
    }

    public function test_token_is_the_only_access(): void
    {
        $this->get('/p/' . str_repeat('a', 40))->assertNotFound();
        $this->get('/p/short')->assertNotFound();
        $this->get('/p/' . str_repeat('A', 40))->assertNotFound();
    }

    public function test_acceptance_fires_automations(): void
    {
        $task = ['type' => 'create_task', 'title' => 'Faturar', 'due_hours' => 1, 'assignee' => 'owner'];
        Automation::create(['user_id' => $this->owner->id, 'name' => 'A', 'trigger' => 'proposal.accepted', 'conditions' => [], 'actions' => [$task]]);
        Automation::create(['user_id' => $this->owner->id, 'name' => 'B', 'trigger' => 'proposal.declined', 'conditions' => [], 'actions' => [['type' => 'create_task', 'title' => 'Ligar'] + $task]]);
        $p = $this->proposal();

        $this->post(route('proposals.public.accept', $p->token), ['name' => 'Ana Souza', 'agree' => '1']);

        $this->assertSame(['Faturar'], Task::pluck('title')->all());
    }

    public function test_owner_screens_and_isolation(): void
    {
        $this->actingAs($this->owner);

        $this->post(route('proposals.store', $this->deal->id))->assertRedirect();
        $p = Proposal::firstOrFail();
        $this->get(route('proposals.index'))->assertOk()->assertSee('Site novo');
        $this->get(route('proposals.show', $p->id))->assertOk()->assertSee($p->token)->assertSee('wa.me/5595999990000', false);

        $this->put(route('proposals.update', $p->id), ['title' => 'Novo título', 'intro' => 'Olá', 'terms' => 'Pagamento em 30 dias', 'valid_until' => now()->addDays(5)->toDateString()])->assertSessionHasNoErrors();
        $this->postJson(route('proposals.sent', $p->id))->assertOk();
        $this->assertSame('sent', $p->fresh()->status);
        $this->get($p->url())->assertSee('Pagamento em 30 dias');

        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->get(route('proposals.show', $p->id))->assertNotFound();
        $this->actingAs($other)->post(route('proposals.store', $this->deal->id))->assertNotFound();

        $this->actingAs($this->owner)->delete(route('proposals.destroy', $p->id));
        $this->assertSame(0, Proposal::count());
    }

    public function test_pipeline_page_offers_the_proposal_button(): void
    {
        $this->actingAs($this->owner)->get(route('pipeline.index'))->assertOk()->assertSee('dealProposalBtn', false);
    }
}
