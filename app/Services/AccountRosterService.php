<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountRosterService
{
    public function replace(string $adminEmail, string $adminPassword, string $operatorEmail, string $operatorPassword): void
    {
        validator(compact('adminEmail', 'adminPassword', 'operatorEmail', 'operatorPassword'), [
            'adminEmail'=>'required|email|different:operatorEmail', 'operatorEmail'=>'required|email',
            'adminPassword'=>'required|string|min:12', 'operatorPassword'=>'required|string|min:12',
        ])->validate();
        $handle = fopen(storage_path('framework/portal-write.lock'), 'c');
        if (!$handle) throw new \RuntimeException('Cannot obtain portal write lock.');
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another portal update is running. Try again after it finishes.');
        }
        try {
            DB::transaction(function () use ($adminEmail, $adminPassword, $operatorEmail, $operatorPassword) {
                foreach ([[$adminEmail, $adminPassword, 'Admin', 'admin'], [$operatorEmail, $operatorPassword, 'Mike', 'operator']] as [$email, $password, $name, $role]) {
                    $user = User::firstOrNew(['email'=>$email]);
                    $user->forceFill(['name'=>$name, 'password'=>$password, 'role'=>$role, 'is_active'=>true, 'remember_token'=>Str::random(60)])->save();
                }
                User::whereNotIn('email', [$adminEmail, $operatorEmail])->delete();
            });
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
