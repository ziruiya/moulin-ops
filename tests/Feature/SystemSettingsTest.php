<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_for_both_system_settings_routes(): void
    {
        $this->get(route('settings.system.index'))->assertRedirectToRoute('login');
        $this->post(route('settings.system.update'), SettingsService::DEFAULTS)->assertRedirectToRoute('login');
    }

    public function test_controller_policy_forbids_a_viewer_even_without_the_route_authorizer(): void
    {
        $this->withoutMiddleware(Authorize::class);
        $this->actingAs($this->user('Viewer'));

        $this->get(route('settings.system.index'))->assertForbidden();
        $this->post(route('settings.system.update'), SettingsService::DEFAULTS)->assertForbidden();
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_saved_rules_survive_a_refresh_and_audit_uses_the_signed_in_admin(): void
    {
        $admin = $this->user('Admin', ['id' => 7001]);
        $this->actingAs($admin);
        $defaults = ['data_review_days' => 90, 'eol_warning_days' => 60, 'restore_review_days' => 90];
        $updated = ['data_review_days' => 1, 'eol_warning_days' => 25, 'restore_review_days' => 3650];

        $this->get(route('settings.system.index'))->assertOk()->assertViewHas('ruleValues', $defaults);
        $this->post(route('settings.system.update'), array_map('strval', $updated) + ['user_id' => 123, 'app_key' => 'ignored'])
            ->assertRedirectToRoute('settings.system.index')->assertSessionHasNoErrors();

        $this->get(route('settings.system.index'))->assertOk()->assertViewHas('ruleValues', $updated);
        $this->assertSame($updated, File::json($this->systemSettingsPath));
        $this->assertSame([
            'user_id' => 7001,
            'auditable_type' => 'SystemSetting',
            'auditable_id' => 1,
            'event_type' => 'system_settings_updated',
            'old_values' => $defaults,
            'new_values' => $updated,
        ], AuditEvent::query()->sole()->only(['user_id', 'auditable_type', 'auditable_id', 'event_type', 'old_values', 'new_values']));
    }

    public function test_invalid_rule_payloads_leave_the_previous_file_and_audit_intact(): void
    {
        $this->actingAs($this->user('Admin'));
        $updated = ['data_review_days' => 45, 'eol_warning_days' => 12, 'restore_review_days' => 120];
        $url = route('settings.system.index');
        $this->post(route('settings.system.update'), $updated)->assertRedirectToRoute('settings.system.index');
        $saved = File::get($this->systemSettingsPath);

        $this->postJson(route('settings.system.update'), ['data_review_days' => 0, 'eol_warning_days' => 1.5, 'restore_review_days' => 3651])
            ->assertUnprocessable()->assertJsonValidationErrors(array_keys($updated));
        $this->postJson(route('settings.system.update'), ['data_review_days' => true] + $updated)
            ->assertUnprocessable()->assertJsonValidationErrors('data_review_days');
        $this->from($url)->post(route('settings.system.update'), ['data_review_days' => [10], 'eol_warning_days' => 'abc'])
            ->assertRedirect($url)->assertSessionHasErrors(array_keys($updated));

        $this->get($url)->assertOk()->assertViewHas('ruleValues', $updated);
        $this->assertSame($saved, File::get($this->systemSettingsPath));
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_corrupt_settings_file_falls_back_to_defaults_and_the_page_renders(): void
    {
        $this->actingAs($this->user('Admin'));
        $settings = app(SettingsService::class);
        $defaults = ['data_review_days' => 90, 'eol_warning_days' => 60, 'restore_review_days' => 90];
        File::put($this->systemSettingsPath, '{"data_review_days":');
        $this->assertSame($defaults, $settings->rules());

        File::put($this->systemSettingsPath, '{"data_review_days":45,"eol_warning_days":false,"restore_review_days":120}');
        $this->assertSame($defaults, $settings->rules());
        $this->get(route('settings.system.index'))->assertOk()->assertViewHas('ruleValues', $defaults);
    }

    public function test_file_write_failure_rolls_back_the_new_audit_event(): void
    {
        $this->actingAs($this->user('Admin'));
        $this->post(route('settings.system.update'), SettingsService::DEFAULTS)->assertRedirectToRoute('settings.system.index');
        File::partialMock()->shouldReceive('replace')->once()->andThrow(new RuntimeException('Test file write failure'));

        $this->post(route('settings.system.update'), ['data_review_days' => 30] + SettingsService::DEFAULTS)->assertServerError();

        $this->assertSame(SettingsService::DEFAULTS, File::json($this->systemSettingsPath));
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_system_page_deduplicates_database_sources_and_renders_only_listed_config(): void
    {
        $this->actingAs($this->user('Admin'));
        config(['app.name' => 'Settings <demo>', 'mail.mailers.smtp.password' => 'TEST_ONLY_CONFIG_SENTINEL']);
        $component = Asset::create(['asset_code' => 'SETTINGS-SOURCES', 'name' => 'Source inventory', 'asset_type' => 'Server'])
            ->components()->create(['product_name' => 'Runtime', 'version' => '1.0', 'component_type' => 'Runtime']);
        $officialUrl = 'https://vendor.example.test/support?a=1&b=2';
        $otherUrl = 'https://vendor.example.test/releases';
        foreach ([
            ['OFFICIAL_MANUAL_SEED', $officialUrl],
            ['OFFICIAL_MANUAL', $officialUrl],
            ['OFFICIAL_MANUAL', $otherUrl],
            ['OFFICIAL_MANUAL', 'https://user:example@vendor.example.test/with-userinfo'],
        ] as $index => [$type, $url]) {
            $component->lifecycleRecords()->create([
                'product_name' => $component->product_name, 'version' => $component->version,
                'source_name' => 'Vendor', 'source_url' => $url, 'data_source_type' => $type,
                'support_phase' => 'Active', 'verified_at' => now()->subDays($index),
            ]);
        }

        $this->get(route('settings.system.index'))->assertOk()
            ->assertViewHas('sourceTypes', fn ($types) => $types->all() === ['OFFICIAL_MANUAL', 'OFFICIAL_MANUAL_SEED'])
            ->assertViewHas('sourceUrls', fn ($urls) => $urls->all() === [$otherUrl, $officialUrl])
            ->assertViewHas('systemConfig', [
                'アプリケーション名' => 'Settings <demo>',
                '実行環境' => 'testing',
                'タイムゾーン' => config('app.timezone'),
                'PHP バージョン' => PHP_VERSION,
                'Laravel バージョン' => app()->version(),
                'DB ドライバー' => 'mysql',
                'デモデータの表示' => '表示中（DEMO DATA）',
            ])
            ->assertSee('href="'.e($officialUrl).'"', false)
            ->assertSee('Settings <demo>')
            ->assertDontSee('Settings <demo>', false)
            ->assertDontSee('TEST_ONLY_CONFIG_SENTINEL')
            ->assertDontSee('with-userinfo');
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }
}
