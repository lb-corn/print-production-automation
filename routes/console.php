<?php
use Illuminate\Support\Facades\Artisan;Artisan::command('production:info',function(){$this->info('Lucky Bags Production Management MVP');});


Artisan::command('production:create-admin {email} {--name=Production Admin}', function () {
    $email = strtolower(trim($this->argument('email')));
    $name = trim($this->option('name'));
    $validator = validator(['email' => $email, 'name' => $name], ['email' => 'required|email|max:255', 'name' => 'required|string|max:255']);
    if ($validator->fails()) {
        $this->error($validator->errors()->first());
        return 1;
    }
    if (\App\Models\User::where('email', $email)->exists()) {
        $this->error('This account already exists. No account or password was changed.');
        return 1;
    }
    $password = $this->secret('Enter the admin password (at least 12 characters)');
    if (!is_string($password) || mb_strlen($password) < 12) {
        $this->error('Password must contain at least 12 characters.');
        return 1;
    }
    if ($password !== $this->secret('Confirm the admin password')) {
        $this->error('Passwords do not match. No user was created.');
        return 1;
    }
    \App\Models\User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => 'admin']);
    $this->info('Admin account created. You can now sign in.');
    return 0;
})->purpose('Create a production administrator with a hidden password prompt; never overwrite an existing account');

Artisan::command('production:replace-users {admin_email} {operator_email}', function () {
    $adminEmail = strtolower(trim($this->argument('admin_email')));
    $operatorEmail = strtolower(trim($this->argument('operator_email')));
    $this->warn('This replaces the user roster with exactly one Admin and Mike as a normal user. All other accounts are deleted. Production history is preserved.');
    $this->line('Admin: '.$adminEmail.'; normal user: '.$operatorEmail);
    if (!$this->confirm('Replace all users with these two accounts?', false)) return 1;
    $passwords = [];
    foreach (['Admin', 'Mike'] as $label) {
        $password = $this->secret($label.' password (at least 12 characters)');
        if (!is_string($password) || mb_strlen($password) < 12 || $password !== $this->secret('Confirm '.$label.' password')) {
            $this->error('Passwords must match and contain at least 12 characters. No users changed.');
            return 1;
        }
        $passwords[] = $password;
    }
    app(\App\Services\AccountRosterService::class)->replace($adminEmail, $passwords[0], $operatorEmail, $passwords[1]);
    $this->info('User roster replaced. Exactly these two active accounts remain. Sign in with the new credentials.');
    return 0;
})->purpose('Explicitly replace the user roster; passwords entered through hidden prompts, never command arguments');
