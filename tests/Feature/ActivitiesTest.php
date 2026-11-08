<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use App\Services\Contacts\ContactTimeline;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class ActivitiesTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->contact = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '5511999990000']);
    }

    public function test_logging_an_activity_shows_in_timeline_and_links_open_deal(): void
    {
        $deal = Deal::forceCreate(['user_id' => $this->owner->id, 'contact_id' => $this->contact->id, 'title' => 'N', 'value' => 1, 'status' => 'open', 'pipeline_id' => 1, 'stage_id' => 1, 'updated_at' => now()->subDays(20)]);
        $this->actingAs($this->owner)->post(route('tasks.activities.store'), ['contact_id' => $this->contact->id, 'type' => 'call', 'duration_min' => 12, 'outcome' => 'Interessada', 'notes' => 'Pediu proposta'])->assertRedirect();

        $a = Activity::first();
        $this->assertSame($deal->id, $a->deal_id);
        $this->assertTrue($deal->fresh()->updated_at->isToday());
        $text = app(ContactTimeline::class)->for($this->contact)->firstWhere('kind', 'activity')['text'];
        $this->assertStringContainsString('12 min', $text);
        $this->assertStringContainsString('Interessada', $text);
        $this->actingAs($this->owner)->get(route('contacts.show', $this->contact->id))->assertOk()->assertSee('Pediu proposta');
    }

    public function test_validation_and_account_scoping(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->post(route('tasks.activities.store'), ['contact_id' => $this->contact->id, 'type' => 'call'])->assertStatus(404);
        $this->actingAs($this->owner)->post(route('tasks.activities.store'), ['contact_id' => $this->contact->id, 'type' => 'fax'])->assertSessionHasErrors('type');
        $this->actingAs($this->owner)->post(route('tasks.activities.store'), ['contact_id' => $this->contact->id, 'type' => 'visit', 'occurred_at' => now()->addDays(3)->toDateTimeString()])->assertSessionHasErrors('occurred_at');

        $this->actingAs($this->owner)->post(route('tasks.activities.store'), ['contact_id' => $this->contact->id, 'type' => 'meeting']);
        $id = Activity::value('id');
        $this->actingAs($other)->delete(route('tasks.activities.destroy', $id));
        $this->assertSame(1, Activity::count());
        $this->actingAs($this->owner)->delete(route('tasks.activities.destroy', $id));
        $this->assertSame(0, Activity::count());
    }
}
