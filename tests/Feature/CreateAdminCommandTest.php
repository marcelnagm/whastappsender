<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use CreatesTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
    }

    public function test_creates_an_admin_with_the_given_password(): void
    {
        $this->artisan('user:admin', ['email' => 'Boss@Example.com', '--password' => 'secret-123', '--name' => 'Boss'])->assertExitCode(0);

        $user = User::where('email', 'boss@example.com')->first();
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue((bool) $user->active);
        $this->assertSame('boss', $user->username);
        $this->assertTrue(Hash::check('secret-123', $user->password));
    }

    public function test_generates_a_password_and_promotes_an_existing_user_without_duplicating(): void
    {
        User::create(['name' => 'Ana', 'email' => 'ana@t.dev', 'username' => 'ana', 'password' => 'old-password']);

        $this->artisan('user:admin', ['email' => 'ana@t.dev'])->expectsOutputToContain('Generated password:')->assertExitCode(0);

        $this->assertSame(1, User::where('email', 'ana@t.dev')->count());
        $ana = User::where('email', 'ana@t.dev')->first();
        $this->assertSame('admin', $ana->role);
        $this->assertSame('Ana', $ana->name);
        $this->assertFalse(Hash::check('old-password', $ana->password));
    }

    public function test_rejects_bad_input(): void
    {
        $this->artisan('user:admin', ['email' => 'nope'])->assertExitCode(1);
        $this->artisan('user:admin', ['email' => 'a@b.co', '--password' => 'short'])->assertExitCode(1);
        $this->assertSame(0, User::count());
    }
}
