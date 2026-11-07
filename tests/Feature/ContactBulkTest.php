<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class ContactBulkTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
    }

    private function contact(User $u, string $n): Contact
    {
        return Contact::create(['user_id' => $u->id, 'name' => $n, 'contact' => '55959' . random_int(10000000, 99999999)]);
    }

    public function test_assign_company_and_clear_only_own_contacts(): void
    {
        $co = Company::create(['user_id' => $this->owner->id, 'name' => 'Acme']);
        $a = $this->contact($this->owner, 'A');
        $b = $this->contact($this->owner, 'B');
        $foreign = $this->contact($this->other, 'F');

        $this->actingAs($this->owner)->post(route('contacts.bulk-update'), ['ids' => json_encode([$a->id, $b->id, $foreign->id]), 'action' => 'company', 'company_id' => $co->id])->assertRedirect();
        $this->assertSame($co->id, $a->fresh()->company_id);
        $this->assertNull($foreign->fresh()->company_id);

        $this->actingAs($this->owner)->post(route('contacts.bulk-update'), ['ids' => json_encode([$a->id]), 'action' => 'company', 'company_id' => 0])->assertRedirect();
        $this->assertNull($a->fresh()->company_id);
        $this->assertSame($co->id, $b->fresh()->company_id);
    }

    public function test_foreign_company_is_refused(): void
    {
        $theirs = Company::create(['user_id' => $this->other->id, 'name' => 'T']);
        $a = $this->contact($this->owner, 'A');
        $this->actingAs($this->owner)->post(route('contacts.bulk-update'), ['ids' => json_encode([$a->id]), 'action' => 'company', 'company_id' => $theirs->id])->assertStatus(422);
        $this->assertNull($a->fresh()->company_id);
    }

    public function test_opt_out_marks_consent_with_a_log(): void
    {
        $a = $this->contact($this->owner, 'A');
        $foreign = $this->contact($this->other, 'F');
        $this->actingAs($this->owner)->post(route('contacts.bulk-update'), ['ids' => json_encode([$a->id, $foreign->id]), 'action' => 'optout'])->assertRedirect();
        $this->assertSame('revoked', $a->fresh()->consent_status);
        $this->assertNotSame('revoked', $foreign->fresh()->consent_status);
        $this->assertSame(1, \DB::table('consent_logs')->where('contact_id', $a->id)->where('action', 'revoked')->count());
    }

    public function test_bad_input(): void
    {
        $this->actingAs($this->owner)->post(route('contacts.bulk-update'), ['ids' => '[]', 'action' => 'optout'])->assertSessionHas('error');
        $this->actingAs($this->owner)->post(route('contacts.bulk-update'), ['ids' => '[1]', 'action' => 'drop'])->assertSessionHasErrors('action');
    }
}
