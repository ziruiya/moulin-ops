<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserManagementController extends Controller
{
    private const ROLES = ['Admin', 'Operator', 'Reviewer', 'Viewer'];

    public function index()
    {
        Gate::authorize('manage-users');

        return view('settings.users.index', ['users' => User::with('roles')->orderBy('name')->paginate(30), 'roleNames' => self::ROLES]);
    }

    public function updateRoles(Request $request, User $user, AuditLogger $audit)
    {
        Gate::authorize('manage-users');
        $data = $request->validate([
            'roles' => 'present|array',
            'roles.*' => ['string', Rule::in(self::ROLES)],
            'grant_reason' => 'nullable|string|max:255',
        ]);
        DB::transaction(function () use ($user, $request, $data, $audit): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $before = $user->roles()->get();
            $roleIds = collect($data['roles'])->unique()->mapWithKeys(fn (string $name) => [$name => Role::firstOrCreate(['name' => $name])->id]);
            $assignments = $roleIds->mapWithKeys(fn (int $id) => [$id => []])->all();
            $grant = ['granted_by' => null, 'grant_reason' => null, 'grant_scope' => null];

            if ($roleIds->has('Reviewer')) {
                if ($roleIds->has('Operator')) {
                    $previous = $before->firstWhere('name', 'Reviewer')?->pivot;
                    if (filled($data['grant_reason'] ?? null)) {
                        $grant = [
                            'granted_by' => $request->user()->id,
                            'grant_reason' => $data['grant_reason'],
                            'grant_scope' => User::CHANGE_APPROVAL_GRANT,
                        ];
                    } elseif ($before->contains('name', 'Operator') && $previous?->grant_scope === User::CHANGE_APPROVAL_GRANT
                        && $previous->granted_by && filled($previous->grant_reason)) {
                        $grant = $previous->only(array_keys($grant));
                    } else {
                        throw ValidationException::withMessages(['grant_reason' => '兼任者の変更承認には付与理由が必要です。']);
                    }
                }
                $assignments[$roleIds['Reviewer']] = $grant;
            }

            $user->roles()->sync($assignments);
            $audit->log($user, 'roles_updated', ['roles' => $before->pluck('name')->all()], ['roles' => $roleIds->keys()->all()] + $grant);
        });

        return back()->with('status', 'ロールを更新しました。');
    }
}
