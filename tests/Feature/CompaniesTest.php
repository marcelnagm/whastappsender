<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class CompaniesTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private User $other;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->contact = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '5595999990000']);
    }

    public function test_crud_and_attach(): void
    {
        $this->actingAs($this->owner)->post(route('companies.store'), ['name' => 'Acme', 'domain' => 'acme.com'])->assertRedirect();
        $co = Company::first();
        $this->actingAs($this->owner)->post(route('companies.attach', $co->id), ['contact_id' => $this->contact->id])->assertRedirect();
        $this->assertSame($co->id, $this->contact->fresh()->company_id);
        $this->actingAs($this->owner)->get(route('companies.index'))->assertOk()->assertSee('Acme');
        $this->actingAs($this->owner)->get(route('companies.show', $co->id))->assertOk()->assertSee('Ana');
        $this->actingAs($this->owner)->delete(route('companies.detach', [$co->id, $this->contact->id]));
        $this->assertNull($this->contact->fresh()->company_id);
        $this->actingAs($this->owner)->delete(route('companies.destroy', $co->id));
        $this->assertSame(0, Company::count());
    }

    public function test_company_deals_follow_the_contact(): void
    {
        $co = Company::create(['user_id' => $this->owner->id, 'name' => 'Acme']);
        $deal = Deal::forceCreate(['user_id' => $this->owner->id, 'contact_id' => $this->contact->id, 'title' => 'X', 'value' => 100, 'status' => 'open', 'pipeline_id' => 1, 'stage_id' => 1]);
        $this->contact->update(['company_id' => $co->id]);
        $this->assertSame($co->id, $deal->fresh()->company_id);
    }

    public function test_cannot_use_another_accounts_company(): void
    {
        $foreign = Company::create(['user_id' => $this->other->id, 'name' => 'Theirs']);
        $this->contact->update(['company_id' => $foreign->id]);
        $this->assertNull($this->contact->fresh()->company_id);

        $this->actingAs($this->owner)->get(route('companies.show', $foreign->id))->assertNotFound();
        $this->actingAs($this->owner)->delete(route('companies.destroy', $foreign->id))->assertNotFound();
        $mine = Company::create(['user_id' => $this->owner->id, 'name' => 'Mine']);
        $theirContact = Contact::create(['user_id' => $this->other->id, 'name' => 'Bob', 'contact' => '5595888880000']);
        $this->actingAs($this->owner)->post(route('companies.attach', $mine->id), ['contact_id' => $theirContact->id])->assertNotFound();
    }
}
