<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public const VERIFIED_AT = '2026-11-18 00:00:00';

    public function run(AuditLogger $audit, DemoInventorySeeder $inventory, DemoVulnerabilitySeeder $vulnerabilities, DemoChangeSeeder $changes): void
    {
        $originalClock = Date::getTestNow();
        Date::setTestNow(self::VERIFIED_AT);
        try {
            DB::transaction(function () use ($audit, $inventory, $vulnerabilities, $changes): void {
                // The fixed role row serializes demo imports, including natural keys without a unique index.
                Role::where('name', 'Operator')->lockForUpdate()->firstOrFail();
                $operator = $this->account('Operator', $audit);
                $reviewer = $this->account('Reviewer', $audit);
                $this->account('Admin', $audit);
                $this->account('Viewer', $audit);

                $assets = $inventory->run($operator?->id);
                $vulnerabilities->run($assets, $operator);
                if ($operator && $reviewer) {
                    $changes->run($assets, $operator, $reviewer);
                } else {
                    $this->command?->warn('変更計画・承認・障害・作業記録には Operator と Reviewer のデモアカウントが必要です。');
                }
            }, 3);
        } finally {
            Date::setTestNow($originalClock);
        }
    }

    private function account(string $role, AuditLogger $audit): ?User
    {
        $username = 'demo-'.strtolower($role);
        $password = config('demo.passwords.'.$role);
        $user = User::firstOrNew(['username' => $username]);
        if (! is_string($password) || blank($password)) {
            $this->command?->warn(strtoupper(str_replace('-', '_', $username)).'_PASSWORD 未設定：'.$username.' のアカウント作成・更新をスキップします。');

            return $user->exists && $user->roles()->where('name', $role)->exists() ? $user : null;
        }

        $user->fill(['name' => $role.'（DEMO）', 'email' => $username.'@example.test']);
        if (! $user->exists || ! Hash::check($password, $user->password) || Hash::needsRehash($user->password)) {
            $user->password = $password;
        }
        $user->save();
        $roleId = Role::where('name', $role)->firstOrFail()->id;
        $roleChanges = $user->roles()->sync([$roleId]);
        if ($user->wasRecentlyCreated || $roleChanges['attached'] !== [] || $roleChanges['detached'] !== []) {
            $audit->log($user, 'demo_role_assigned', [], ['username' => $user->username, 'role' => $role]);
        }

        return $user;
    }
}
