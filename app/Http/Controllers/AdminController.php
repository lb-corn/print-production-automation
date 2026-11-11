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
    // Operational records only. Artwork mappings/rules and connection settings are
    // primary configuration and must survive a production reset.
    public const RESET_TABLES = ['batch_confirmations', 'print_batches', 'verification_decisions', 'production_items'];

    public function index()
    {
        return view('admin.users', ['users' => User::orderBy('name')->paginate(20)->withQueryString()]);
    }

    public function create(Request $request)
    {
        $user = new User(['role' => 'manager']);
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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'role' => ['required', Rule::in(array_merge(['admin'], \App\Models\Role::pluck('slug')->all()))],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(12)],
        ]);
        if ($request->user()->role !== 'admin') {
            abort_if($user?->role === 'admin' || $data['role'] === 'admin',403,'Only Admin can manage Admin accounts.');
            if(!$user || $user->role!==$data['role']) abort_unless($request->user()->allows('admin.roles.edit'),403,'Role assignment requires role management access.');
            $assigned=\App\Models\Role::where('slug',$data['role'])->firstOrFail();
            foreach($assigned->permissions as $permission) abort_unless($request->user()->allows($permission),403,'You cannot grant permissions you do not hold.');
        }
        return $data;
    }

    public function store(Request $request)
    {
        $data=$this->validated($request);
        DB::transaction(function()use($data,$request){$user=User::create($data);\App\Services\AccessAudit::record($request->user()->id,'user.role-assigned','user',(string)$user->id,null,['role'=>$user->role]);});
        return redirect()->route('admin.users')->with('ok', 'User created. Share their password through your usual secure channel.');
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);
        if ($user->id === $request->user()->id && $user->role === 'admin' && $data['role'] !== 'admin') {
            return back()->withErrors(['role' => 'You cannot remove your own admin access.'])->withInput($request->except('password', 'password_confirmation'));
        }
        if (empty($data['password'])) unset($data['password']);
        else $data['remember_token'] = Str::random(60);
        if ($data['role'] === 'admin') $data['is_active'] = true;
        DB::transaction(function()use($user,$data,$request){$before=$user->role;$user->forceFill($data)->save();if($before!==$user->role)\App\Services\AccessAudit::record($request->user()->id,'user.role-assigned','user',(string)$user->id,['role'=>$before],['role'=>$user->role]);});
        return redirect()->route('admin.users')->with('ok', 'User updated.');
    }

    public function resetPage()
    {
        abort_unless(count(\App\Services\PartnerContext::allowed(auth()->user()))===count(config('services.partners',[])),403,'Reset requires access to every production partner.');
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

    public function pcCleanup(Request $request)
    {
        abort_unless($request->user()?->allows('admin.reset.edit'),403);
        $python=base64_encode(file_get_contents(resource_path('factory-pc-cleanup.py')));
        $script=<<<'PS'
$ErrorActionPreference='Stop'
$python='C:\LuckyBagsFactory\.venv\Scripts\python.exe'
if(-not (Test-Path -LiteralPath $python -PathType Leaf)){throw 'Existing Print PC Python installation was not found.'}
$temp=Join-Path ([IO.Path]::GetTempPath()) ('LuckyBags-Cleanup-'+[guid]::NewGuid()+'.py')
try {
    [IO.File]::WriteAllBytes($temp,[Convert]::FromBase64String('__CLEANUP_CODE__'))
    & $python -I $temp
    if($LASTEXITCODE -ne 0){throw 'PC cleanup did not complete. Read the message above; portal data has not been reset by this download.'}
} finally {Remove-Item -LiteralPath $temp -ErrorAction SilentlyContinue}
PS;
        $launcher=<<<'CMD'
@echo off
setlocal DisableDelayedExpansion
set "LUCKYBAGS_CLEANUP_SELF=%~f0"
powershell.exe -NoProfile -ExecutionPolicy RemoteSigned -Command "$lines=[IO.File]::ReadAllLines($env:LUCKYBAGS_CLEANUP_SELF); $index=[Array]::IndexOf($lines,'# POWERSHELL_START'); if($index -lt 0){exit 1}; $temp=Join-Path ([IO.Path]::GetTempPath()) ('LuckyBags-Cleanup-'+[guid]::NewGuid()+'.ps1'); [IO.File]::WriteAllLines($temp,$lines[($index+1)..($lines.Length-1)]); try { & $temp } finally { Remove-Item -LiteralPath $temp -ErrorAction SilentlyContinue }"
set "CLEANUP_RESULT=%ERRORLEVEL%"
echo.
pause
exit /b %CLEANUP_RESULT%
# POWERSHELL_START
CMD;
        return response(str_replace("\n","\r\n",$launcher)."\r\n".str_replace('__CLEANUP_CODE__',$python,$script),200,['Content-Type'=>'application/octet-stream','Content-Disposition'=>'attachment; filename="Clear-LuckyBags-Print-Files.cmd"','Cache-Control'=>'no-store']);
    }

    public function reset(Request $request)
    {
        abort_unless(count(\App\Services\PartnerContext::allowed(auth()->user()))===count(config('services.partners',[])),403,'Reset requires access to every production partner.');
        $request->validate([
            'password' => ['required', 'current_password'],
            'confirmation' => ['required', Rule::in(['RESET PORTAL'])],
            'agents_paused' => ['accepted'],
            'reload_shopify' => ['sometimes','boolean'],
        ]);
        try {
            DB::transaction(function () use ($request) {
                foreach (self::RESET_TABLES as $table) DB::table($table)->delete();
                if($request->boolean('reload_shopify'))app(\App\Services\BacklogSyncService::class)->sync();
            });
        } catch (\Throwable $error) {
            report($error);
            return redirect()->route('admin.settings',['section'=>'reset'])->with('error','Reset could not finish. All portal records were preserved. Check Shopify, Drive and material-sheet access, then try again.');
        }
        return redirect()->route('dashboard')->with('ok', $request->boolean('reload_shopify')?'Production reset complete. Current Shopify bag orders were imported for fresh artwork review. PC files are not cleared by this portal reset.':'Production records reset. Settings and users were preserved. PC files are not cleared by this portal reset.');
    }
}
