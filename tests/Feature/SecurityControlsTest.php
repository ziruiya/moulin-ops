<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_csrf_requires_a_matching_session_token_for_a_write(): void
    {
        // Laravel normally bypasses its CSRF middleware under PHPUnit.
        $this->app->instance('env', 'local');
        $operator = $this->operator();
        $this->actingAs($operator)->get(route('assets.create'))->assertOk()->assertSee('name="_token"', false);
        $token = session()->token();
        $url = route('assets.store');
        $asset = $this->assetInput('CSRF-NEW', 'Documented change');

        $this->withSession(['_token' => $token])->post($url, $asset)->assertStatus(419);
        $this->withSession(['_token' => $token])->post($url, $asset + ['_token' => Str::random(40)])->assertStatus(419);
        $this->assertDatabaseCount('assets', 0);
        $this->withSession(['_token' => $token])->post($url, $asset + ['_token' => $token])->assertRedirect();
        $this->assertDatabaseHas('assets', ['asset_code' => 'CSRF-NEW']);
    }

    public function test_an_expired_database_session_cannot_reuse_the_operators_permissions(): void
    {
        config(['session.driver' => 'database', 'session.lifetime' => 1]);
        $password = Str::random(32);
        $operator = $this->operator($password);
        $login = $this->post(route('login.store'), ['login' => $operator->username, 'password' => $password]);
        $login->assertRedirect(route('dashboard'));
        $cookieName = config('session.cookie');
        $sessionId = $login->getCookie($cookieName)->getValue();
        $this->assertDatabaseHas('sessions', ['id' => $sessionId, 'user_id' => $operator->id]);

        Auth::forgetGuards();
        $this->withCookie($cookieName, $sessionId)->get(route('assets.create'))->assertOk();
        $this->travel(2)->minutes();
        // Discard the in-memory attributes reused by the test client between requests.
        session()->flush();
        Auth::forgetGuards();
        $this->post(route('assets.store'), $this->assetInput('EXPIRED-NEW', 'Expired session'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_inventory_text_is_escaped_and_search_value_is_parameter_bound(): void
    {
        $title = 'Shop\'s <catalog> & "notes"';
        $this->actingAs($this->operator())->post(route('assets.store'), $this->assetInput('ESCAPE-NEW', $title))->assertRedirect();
        $asset = Asset::where('asset_code', 'ESCAPE-NEW')->sole();
        $this->get(route('assets.show', $asset))->assertOk()->assertSee($title)->assertDontSee($title, false);
        $search = "Shop's <catalog>";

        DB::enableQueryLog();
        $response = $this->get(route('assets.index', ['q' => $search]));
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()->assertSee($title)->assertDontSee($title, false);
        $select = $queries->first(fn ($entry) => str_starts_with($entry['query'], 'select * from') && str_contains($entry['query'], 'assets')
            && str_contains(strtolower($entry['query']), 'like ?'));
        $this->assertNotNull($select);
        $this->assertSame(['%'.$search.'%', '%'.$search.'%'], $select['bindings']);
        $this->assertStringNotContainsString($search, $select['query']);
    }

    public function test_server_validates_asset_fields_and_credentials_are_not_logged_or_flashed(): void
    {
        $operator = $this->operator();
        $url = route('assets.store');
        $credentials = ['password' => Str::random(32), 'token' => Str::random(32), 'secret' => Str::random(32)];
        $this->actingAs($operator)->postJson($url, ['asset_code' => 'INVALID', 'operational_status' => 'unsupported'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'asset_type', 'operational_status']);
        $this->assertDatabaseCount('assets', 0);

        $this->post($url, $this->assetInput('CONFIG-NEW', 'Routine inventory') + $credentials)->assertRedirect();
        $event = AuditEvent::where('event_type', 'created')->sole();
        $logged = $event->new_values;
        $this->assertSame('CONFIG-NEW', $logged['asset_code']);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $login = $this->from(route('login'))->post(route('login.store'), ['login' => $operator->username] + $credentials);
        $login->assertRedirect(route('login'))->assertSessionHasErrors('login')->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.token')->assertSessionMissing('_old_input.secret');

        $this->actingAs($operator)->get(route('audit-events.index'))->assertOk()
            ->assertDontSee($credentials['password'])->assertDontSee($credentials['token'])->assertDontSee($credentials['secret']);
        foreach (array_keys($credentials) as $key) {
            $this->assertArrayNotHasKey($key, $logged);
        }
    }

    private function operator(?string $password = null): User
    {
        $operator = User::factory()->create($password ? ['password' => $password] : []);
        $operator->roles()->attach(Role::firstOrCreate(['name' => 'Operator']));

        return $operator;
    }

    private function assetInput(string $code, string $name): array
    {
        return ['asset_code' => $code, 'name' => $name, 'asset_type' => 'Server', 'operational_status' => 'Active'];
    }
}
