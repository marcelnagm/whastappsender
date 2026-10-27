<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class LanguageCoverageTest extends TestCase
{
    use CreatesTestSchema;

    private function user(string $locale): User
    {
        $this->createTestSchema();

        return User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x', 'locale' => $locale]);
    }

    public function test_pages_follow_the_chosen_language(): void
    {
        $u = $this->user('pt_BR');

        $this->actingAs($u)->get('/instances')->assertOk()->assertSee('Minhas instâncias')->assertDontSee('My instances');

        $u->forceFill(['locale' => 'es'])->save();
        $this->actingAs($u->fresh())->get('/instances')->assertOk()->assertSee('Mis instancias')->assertDontSee('My instances');

        $u->forceFill(['locale' => 'en'])->save();
        $this->actingAs($u->fresh())->get('/instances')->assertOk()->assertSee('My instances');
    }

    public function test_every_string_used_in_views_and_app_exists_in_pt_BR_and_es(): void
    {
        $used = [];
        foreach (['app', 'resources/views'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!preg_match('/\.php$/', $f)) {
                    continue;
                }
                preg_match_all('/(?:__|@lang)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', file_get_contents($f), $m);
                foreach ($m[1] as $k) {
                    $k = stripcslashes($k);
                    if (!preg_match('/^[a-z_]+\.[a-z_.]+$/', $k)) {
                        $used[$k] = true;
                    }
                }
            }
        }

        foreach (['pt_BR', 'es'] as $locale) {
            $json = json_decode(file_get_contents(base_path("lang/{$locale}.json")), true);
            $missing = array_values(array_diff(array_keys($used), array_keys($json)));
            $this->assertSame([], $missing, "Untranslated strings for {$locale}");
        }
    }

    public function test_notifications_use_the_language_of_the_user(): void
    {
        $u = $this->user('pt_BR');

        $this->assertSame('pt_BR', $u->preferredLocale());
        app()->setLocale($u->preferredLocale());
        $alert = new \App\Notifications\InstanceAlert('instance_recovered', ['instance' => 'Loja']);

        $this->assertStringContainsString('conectado novamente', $alert->toArray($u)['title']);
    }
}
