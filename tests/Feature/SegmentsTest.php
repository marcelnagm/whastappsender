<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactField;
use App\Models\ContactSegment;
use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Contacts\ContactFilter;
use App\Services\Contacts\CustomFields;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Custom contact fields and saved segments. */
class SegmentsTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        foreach ([['city', 'City', 'text', null], ['age', 'Age', 'number', null], ['plan', 'Plan', 'select', ['basic', 'pro']], ['vip', 'VIP', 'boolean', null], ['born', 'Born', 'date', null]] as $i => [$k, $l, $t, $o]) {
            ContactField::create(['user_id' => $this->owner->id, 'key' => $k, 'label' => $l, 'type' => $t, 'options' => $o, 'position' => $i]);
        }
    }

    private function contact(string $phone, array $custom = [], array $o = []): Contact
    {
        return Contact::create($o + ['user_id' => $this->owner->id, 'name' => 'C' . $phone, 'contact' => $phone, 'status' => 'ativo', 'custom' => $custom ?: null]);
    }

    private function filter(array $conds): array
    {
        return app(ContactFilter::class)->query($this->owner->id, $conds)->pluck('contact')->sort()->values()->all();
    }

    public function test_values_are_cleaned_by_type(): void
    {
        $v = app(CustomFields::class)->clean($this->owner->id, [
            'city' => ' Boa Vista ', 'age' => '42,5', 'plan' => 'gold', 'vip' => 'sim', 'born' => '31/12/1990', 'ghost' => 'x',
        ]);

        $this->assertSame(['city' => 'Boa Vista', 'age' => 42.5, 'vip' => '1', 'born' => '1990-12-31'], $v); // gold is not a choice, ghost not a field
    }

    public function test_filters_on_custom_and_core_fields(): void
    {
        $this->contact('1', ['city' => 'Boa Vista', 'age' => 30, 'plan' => 'pro', 'vip' => '1'], ['score' => 80]);
        $this->contact('2', ['city' => 'Manaus', 'age' => 50, 'plan' => 'basic', 'vip' => '0'], ['score' => 20]);
        $this->contact('3', [], ['score' => 60, 'email' => 'a@b.c']);

        $this->assertSame(['1'], $this->filter([['field' => 'custom:city', 'op' => 'contains', 'value' => 'boa']]));
        $this->assertSame(['2'], $this->filter([['field' => 'custom:age', 'op' => 'gte', 'value' => '40']]));
        $this->assertSame(['1', '2'], $this->filter([['field' => 'custom:plan', 'op' => 'filled']]));
        $this->assertSame(['3'], $this->filter([['field' => 'custom:plan', 'op' => 'empty']]));
        $this->assertSame(['1'], $this->filter([['field' => 'custom:vip', 'op' => 'eq', 'value' => '1']]));
        $this->assertSame(['1', '3'], $this->filter([['field' => 'score', 'op' => 'gte', 'value' => '60']]));
        $this->assertSame(['3'], $this->filter([['field' => 'has_email', 'op' => 'eq', 'value' => '1']]));
        $this->assertSame(['1'], $this->filter([['field' => 'score', 'op' => 'gte', 'value' => '60'], ['field' => 'custom:city', 'op' => 'eq', 'value' => 'Boa Vista']])); // AND
    }

    public function test_invalid_conditions_are_dropped_not_guessed(): void
    {
        $n = app(ContactFilter::class)->normalize($this->owner->id, [
            ['field' => 'custom:nope', 'op' => 'eq', 'value' => 'x'],
            ['field' => 'custom:age', 'op' => 'contains', 'value' => 'x'],
            ['field' => 'custom:age', 'op' => 'gte', 'value' => 'abc'],
            ['field' => 'custom:city', 'op' => 'eq', 'value' => ''],
            ['field' => 'password', 'op' => 'eq', 'value' => 'x'],
            ['field' => 'custom:city', 'op' => 'eq', 'value' => 'Rio'],
        ]);

        $this->assertSame([['field' => 'custom:city', 'op' => 'eq', 'value' => 'Rio']], $n);
    }

    public function test_other_accounts_contacts_never_match(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        Contact::create(['user_id' => $other->id, 'name' => 'X', 'contact' => '9', 'status' => 'ativo', 'score' => 99]);
        $this->contact('1', [], ['score' => 99]);

        $this->assertSame(['1'], $this->filter([['field' => 'score', 'op' => 'gte', 'value' => '90']]));
    }

    public function test_segment_narrows_campaign_audience(): void
    {
        $this->contact('1', ['city' => 'Boa Vista']);
        $this->contact('2', ['city' => 'Manaus']);
        $seg = ContactSegment::create(['user_id' => $this->owner->id, 'name' => 'BV', 'filters' => [['field' => 'custom:city', 'op' => 'eq', 'value' => 'Boa Vista']]]);

        $aud = new CampaignAudience();
        $this->assertSame(2, $aud->count($this->owner->id, ['channels' => ['whatsapp']])['total']);
        $this->assertSame(1, $aud->count($this->owner->id, ['channels' => ['whatsapp'], 'segment_id' => $seg->id])['total']);
        $this->assertSame($seg->id, CampaignAudience::normalize(['segment_id' => (string) $seg->id])['segment_id']);
        $this->assertArrayNotHasKey('segment_id', CampaignAudience::normalize([]));

        $seg->delete(); // a vanished segment reaches nobody
        $this->assertSame(0, $aud->count($this->owner->id, ['channels' => ['whatsapp'], 'segment_id' => $seg->id])['total']);
    }

    public function test_segment_of_another_account_is_not_usable(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $seg = ContactSegment::create(['user_id' => $other->id, 'name' => 'S', 'filters' => [['field' => 'score', 'op' => 'gte', 'value' => '0']]]);
        $this->contact('1');

        $this->assertSame(0, (new CampaignAudience())->count($this->owner->id, ['channels' => ['whatsapp'], 'segment_id' => $seg->id])['total']);
    }

    public function test_create_and_remove_fields_and_segments_over_http(): void
    {
        $this->actingAs($this->owner);

        $this->post(route('audience.fields.store'), ['label' => 'Número de filhos', 'type' => 'number'])->assertSessionHasNoErrors();
        $this->assertTrue(ContactField::where('user_id', $this->owner->id)->where('key', 'numero_de_filhos')->exists());

        $this->post(route('audience.fields.store'), ['label' => 'Número de filhos', 'type' => 'text'])->assertSessionHasErrors('label'); // duplicate
        $this->post(route('audience.fields.store'), ['label' => 'Email', 'type' => 'text'])->assertSessionHasErrors('label');          // reserved
        $this->post(route('audience.fields.store'), ['label' => 'Nivel', 'type' => 'select'])->assertSessionHasErrors('options');

        $this->post(route('audience.segments.store'), ['name' => 'Vazio', 'filters' => [['field' => 'x', 'op' => 'eq', 'value' => '1']]])->assertSessionHasErrors('name');
        $this->post(route('audience.segments.store'), ['name' => 'Pro', 'filters' => [['field' => 'custom:plan', 'op' => 'eq', 'value' => 'pro']]])->assertSessionHasNoErrors();
        $seg = ContactSegment::firstOrFail();

        $this->contact('1', ['plan' => 'pro']);
        $this->contact('2', ['plan' => 'basic']);
        $this->postJson(route('audience.preview'), ['filters' => $seg->filters])->assertJson(['count' => 1]);
        $this->get(route('audience.index'))->assertOk()->assertSee('Pro');

        $csv = $this->get(route('audience.segments.export', $seg->id))->assertOk()->streamedContent();
        $this->assertStringContainsString('C1', $csv);
        $this->assertStringNotContainsString('C2', $csv);

        $this->delete(route('audience.fields.destroy', ContactField::where('key', 'plan')->first()->id));
        $this->assertSame([], $seg->fresh()->filters); // the segment lost the condition on the removed field

        $this->delete(route('audience.segments.destroy', $seg->id));
        $this->assertSame(0, ContactSegment::count());
    }

    public function test_contact_form_stores_custom_values(): void
    {
        $this->actingAs($this->owner);

        $this->post(route('contacts.store'), ['name' => 'Ana', 'contact' => '5595999990001', 'custom' => ['city' => 'Recife', 'plan' => 'pro', 'junk' => 'x']]);
        $c = Contact::where('name', 'Ana')->firstOrFail();
        $this->assertSame(['city' => 'Recife', 'plan' => 'pro'], $c->custom);

        $this->put(route('contacts.update', $c->id), ['name' => 'Ana', 'contact' => '5595999990001', 'custom' => ['city' => '']]);
        $this->assertNull($c->fresh()->custom); // cleared input removes the value
        $this->get(route('contacts.edit', $c->id))->assertOk()->assertSee('custom[city]', false);
    }

    public function test_import_fills_custom_fields_by_column_name(): void
    {
        $this->actingAs($this->owner);
        (new \App\Imports\ContactsImport())->model(['name' => 'Bia', 'contact' => '5595999990002', 'city' => 'Natal', 'age' => '33', 'plan' => 'pro'])->save();

        $this->assertSame(['city' => 'Natal', 'age' => 33, 'plan' => 'pro'], Contact::where('name', 'Bia')->first()->custom);
    }
}
