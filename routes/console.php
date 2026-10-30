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
