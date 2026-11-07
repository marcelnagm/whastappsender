<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\ContactForm;
use App\Models\ContactFormSubmission;
use App\Models\Deal;
use App\Models\Instance;
use App\Models\User;
use App\Services\Pipeline\LeadDeals;
use App\Services\Pipeline\SalesPipeline;
use App\Services\Reports\SourceReport;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Where leads come from: capture and the report. */
class LeadSourcesTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private SalesPipeline $funnel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->funnel = app(SalesPipeline::class);
    }

    private function deal(string $title, ?string $origin, ?string $stage = null, ?string $value = null): Deal
    {
        $p = $this->funnel->defaultFor($this->owner);
        $st = $stage ? \App\Models\PipelineStage::where('pipeline_id', $p->id)->where('name', $stage)->first() : null;
        $d = $this->funnel->open($p, ['title' => $title, 'origin' => $origin, 'value' => $value ?? 0]);

        return $st ? $this->funnel->move($d, $st) : $d;
    }

    public function test_origin_label_comes_from_utm_or_form_name(): void
    {
        $this->assertSame('google / cpc / black-friday', LeadDeals::originFromForm('Contato', ['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'black-friday', 'page' => '/lp']));
        $this->assertSame('instagram', LeadDeals::originFromForm('Contato', ['utm_source' => 'instagram']));
        $this->assertSame('Form: Contato', LeadDeals::originFromForm('Contato', ['page' => '/lp']));
        $this->assertSame(80, mb_strlen(LeadDeals::originFromForm('x', ['utm_source' => str_repeat('a', 200)])));
    }

    public function test_conversation_deal_origin_is_the_channel(): void
    {
        $inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected', 'channel' => 'instagram']);
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '1']);
        $chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'contact_id' => $c->id, 'remote_jid' => 'x']);

        $d = app(LeadDeals::class)->manual($chat, $this->owner);

        $this->assertSame('Instagram', $d->origin);
    }

    public function test_form_deal_gets_the_utm_origin(): void
    {
        $this->owner->forceFill(['deal_auto_form' => true])->save();
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '1']);

        $d = app(LeadDeals::class)->fromForm($this->owner, $c, null, 'Form deal', LeadDeals::originFromForm('Contato', ['utm_source' => 'google', 'utm_medium' => 'cpc']));

        $this->assertSame('google / cpc', $d->origin);
    }

    public function test_public_form_stores_only_known_utm_keys(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $form = ContactForm::create(['user_id' => $this->owner->id, 'name' => 'Contato', 'fields' => \App\Services\Forms\ContactFormFields::normalize(\App\Services\Forms\ContactFormFields::defaults())]);

        $this->postJson('/api/public/forms/' . $form->public_key, ['name' => 'Ana', 'phone' => '95999990000', '_utm' => ['utm_source' => 'google', 'utm_campaign' => '<b>bf</b>', 'evil' => 'x', 'page' => '/lp']])->assertOk();

        $this->assertSame(['utm_source' => 'google', 'utm_campaign' => 'bf', 'page' => '/lp'], ContactFormSubmission::first()->utm);
    }

    public function test_report_groups_by_origin_with_rates_and_value(): void
    {
        $this->deal('a', 'Google', 'Won', '1000');
        $this->deal('b', 'Google', 'Won', '500');
        $this->deal('c', 'Google', 'Lost');
        $this->deal('d', 'Google');
        $this->deal('e', 'Instagram', 'Won', '200');
        $this->deal('f', null);
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $p = $this->funnel->defaultFor($other);
        $this->funnel->open($p, ['title' => 'x', 'origin' => 'Google']);

        $r = app(SourceReport::class)->build($this->owner, now()->subDay(), now()->addDay());

        $g = collect($r['rows'])->firstWhere('origin', 'Google');
        $this->assertSame([4, 1, 2, 1, 66.7], [$g['created'], $g['open'], $g['won'], $g['lost'], $g['win_rate']]);
        $this->assertEquals(1500, $g['won_value']);
        $this->assertSame('Google', $r['rows'][0]['origin']);                      // best revenue first
        $this->assertNull(collect($r['rows'])->firstWhere('created', 1 && false)); // no crash on null origin
        $this->assertSame(6, $r['totals']['created']);                            // other account excluded
        $this->assertSame(3, $r['totals']['won']);
        $this->assertEquals(1700, $r['totals']['won_value']);
        $this->assertContains(null, array_column($r['rows'], 'origin'));
    }

    public function test_report_respects_the_period(): void
    {
        $d = $this->deal('old', 'Google');
        Deal::whereKey($d->id)->update(['created_at' => now()->subDays(200)]);

        $this->assertSame([], app(SourceReport::class)->build($this->owner, now()->subDays(30), now())['rows']);
    }

    public function test_pages_and_deal_origin_edit(): void
    {
        $this->actingAs($this->owner);
        $d = $this->deal('a', 'Google', 'Won', '100');

        $this->get(route('pipeline.sources'))->assertOk()->assertSee('Google');
        $this->get(route('pipeline.sources', ['period' => '30']))->assertOk();
        $this->get(route('pipeline.index'))->assertOk()->assertSee('originList', false);

        $this->put(route('pipeline.deals.update', $d->id), ['title' => 'a', 'origin' => 'Indicação'])->assertRedirect();
        $this->assertSame('Indicação', $d->fresh()->origin);
    }
}
