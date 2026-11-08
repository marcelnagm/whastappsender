<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\User;
use App\Services\Export\DataExport;
use App\Services\Team\TeamRoles;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class DataExportTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
    }

    public function test_contacts_csv_has_only_own_rows_and_neutralises_formulas(): void
    {
        Contact::create(['user_id' => $this->owner->id, 'name' => '=HYPERLINK("http://x")', 'contact' => '5595999990000']);
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        Contact::create(['user_id' => $other->id, 'name' => 'Alheio', 'contact' => '5595888880000']);

        $r = $this->actingAs($this->owner)->get(route('export.download', 'contacts'))->assertOk();
        $csv = $r->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Alheio', $csv);
        $this->assertStringStartsWith("\xEF\xBB\xBFid;name;phone", $csv);
        $this->assertStringContainsString('attachment', $r->headers->get('content-disposition'));
    }

    public function test_every_type_downloads_and_unknown_is_404(): void
    {
        foreach (array_keys(DataExport::types()) as $t) {
            $this->actingAs($this->owner)->get(route('export.download', $t))->assertOk();
            $this->assertNotSame('', $this->actingAs($this->owner)->get(route('export.download', $t))->streamedContent());
        }
        $this->actingAs($this->owner)->get(route('export.download', 'users'))->assertNotFound();
    }

    public function test_download_is_audited_and_agents_are_blocked(): void
    {
        $this->actingAs($this->owner)->get(route('export.download', 'tasks'))->streamedContent();
        $this->assertSame('export.download', AuditLog::first()->action);
        $this->assertSame('tasks', AuditLog::first()->subject);

        $agent = User::create(['name' => 'Ag', 'email' => 'a@t.dev', 'username' => 'a', 'password' => 'x'])->fresh();
        $agent->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => TeamRoles::AGENT, 'active' => true])->save();
        $this->actingAs($agent)->get(route('export.download', 'contacts'))->assertForbidden();
        $this->actingAs($agent)->get(route('exports.index'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('exports.index'))->assertOk();
    }
}
