<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminController extends Controller
{
    public const RESET_TABLES = ['batch_confirmations', 'print_batches', 'verification_decisions', 'production_items'];

    public function index()
    {
        return view('admin.users', ['users' => User::orderBy('name')->paginate(20)]);
    }

    public function create(Request $request)
    {
        $user = new User(['role' => 'operator']);
        if ($request->query('preset') === 'mike') $user->fill(['name' => 'Mike', 'email' => 'mike@luckycornhole.com']);
        return view('admin.user-form', compact('user'));
    }

    public function edit(User $user)
    {
        return view('admin.user-form', compact('user'));
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'role' => ['required', Rule::in(['admin', 'operator'])],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(12)],
        ]);
    }

    public function store(Request $request)
    {
        User::create($this->validated($request));
        return redirect()->route('admin.users')->with('ok', 'User created. Share their password through your usual secure channel.');
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);
        if ($user->id === $request->user()->id && $data['role'] !== 'admin') {
            return back()->withErrors(['role' => 'You cannot remove your own admin access.'])->withInput($request->except('password', 'password_confirmation'));
        }
        if (empty($data['password'])) unset($data['password']);
        else $data['remember_token'] = Str::random(60);
        if ($data['role'] === 'admin') $data['is_active'] = true;
        $user->forceFill($data)->save();
        return redirect()->route('admin.users')->with('ok', 'User updated.');
    }

    public function resetPage()
    {
        $counts = collect(self::RESET_TABLES)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        return view('admin.reset', compact('counts'));
    }

    public function status(Request $request, User $user)
    {
        abort_if($user->role === 'admin', 403, 'Admin accounts cannot be deactivated.');
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $user->forceFill(['is_active' => (bool) $data['is_active'], 'remember_token' => Str::random(60)])->save();
        return redirect()->route('admin.users')->with('ok', $data['is_active'] ? 'User activated.' : 'User deactivated. Access is blocked, including existing sessions.');
    }

    public function deletePage(User $user)
    {
        abort_if($user->role === 'admin', 403, 'Admin accounts cannot be deleted.');
        return view('admin.user-delete', compact('user'));
    }

    public function destroy(Request $request, User $user)
    {
        abort_if($user->role === 'admin', 403, 'Admin accounts cannot be deleted.');
        $request->validate(['password' => ['required', 'current_password'], 'confirmation' => ['required', Rule::in([$user->email])]]);
        // Foreign keys null user references; historical production records remain.
        $user->delete();
        return redirect()->route('admin.users')->with('ok', 'User deleted. Production history was preserved.');
    }

    public function reset(Request $request)
    {
        $request->validate([
            'password' => ['required', 'current_password'],
            'confirmation' => ['required', Rule::in(['RESET PORTAL'])],
            'agents_paused' => ['accepted'],
        ]);
        DB::transaction(function () {
            foreach (self::RESET_TABLES as $table) DB::table($table)->delete();
        });
        return redirect()->route('dashboard')->with('ok', 'Portal production data reset. Users and migrations were preserved. Pull the Shopify backlog to start again.');
    }
}
