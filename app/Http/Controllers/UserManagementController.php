<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    private const ROLES = ['Admin', 'Operator', 'Reviewer', 'Viewer'];

    public function index()
    {
        Gate::authorize('manage-users');

        return view('settings.users.index', ['users' => User::with('roles')->orderBy('name')->paginate(30), 'roleNames' => self::ROLES]);
    }

    public function updateRoles(Request $request, User $user)
    {
        Gate::authorize('manage-users');
        $data = $request->validate(['roles' => 'present|array', 'roles.*' => ['string', Rule::in(self::ROLES)]]);
        $ids = collect($data['roles'])->map(fn (string $name) => Role::firstOrCreate(['name' => $name])->id);
        $user->roles()->sync($ids);

        return back()->with('status', 'Roles updated');
    }
}
