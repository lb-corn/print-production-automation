<?php
// Isolated in-memory database only. Never resets the configured portal database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
Illuminate\Support\Facades\DB::purge('sqlite');
foreach (glob(__DIR__.'/../database/migrations/*.php') as $file) (require $file)->up();
use App\Models\User;
use App\Http\Controllers\AdminController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$check = function ($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; };
$admin = User::create(['name'=>'Admin','email'=>'admin@example.test','password'=>'AdminPassword123','role'=>'admin']);
$normal = User::create(['name'=>'Normal','email'=>'normal@example.test','password'=>'NormalPassword123','role'=>'operator']);
$request = function ($data = [], $user = null) use ($app, $admin) {
    $r = Request::create('/admin/reset', 'POST', $data);
    $r->setUserResolver(fn () => $user ?? $admin);
    $r->setLaravelSession($app['session']->driver());
    return $r;
};
$gate = new App\Http\Middleware\RequireAdmin;
foreach ([null, $normal] as $user) {
    $r = Request::create('/admin/users'); $r->setUserResolver(fn () => $user);
    try { $gate->handle($r, fn () => 'allowed'); throw new RuntimeException('Non-admin admitted'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $check($e->getStatusCode() === 403, 'Non-admin is rejected server-side'); }
}
$check($gate->handle($request(), fn () => 'allowed') === 'allowed', 'Admin is admitted');
$writeLock = new App\Http\Middleware\PortalWriteLock;
$check($writeLock->handle($request(), fn () => 'updated') === 'updated', 'Write lock permits a single update');
$handle = fopen(storage_path('framework/portal-write.lock'), 'c');
flock($handle, LOCK_EX);
try { $writeLock->handle($request(), fn () => 'unsafe'); throw new RuntimeException('Overlapping write admitted'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { $check($e->getStatusCode() === 409, 'Overlapping portal updates rejected'); }
finally { flock($handle, LOCK_UN); fclose($handle); }
foreach (app('router')->getRoutes() as $route) {
    if (str_starts_with($route->getName() ?? '', 'admin.')) {
        $check(in_array(App\Http\Middleware\RequireAdmin::class, $route->gatherMiddleware()), 'Admin route protected: '.$route->getName());
    }
}
view()->share('errors', new Illuminate\Support\ViewErrorBag);
Auth::login($admin);
$controller = new AdminController;
$data = ['name'=>'Mike','email'=>'MIKE@luckycornhole.com','role'=>'operator','password'=>'MikeReviewPassword123','password_confirmation'=>'MikeReviewPassword123'];
$controller->store($request($data));
$mike = User::where('email','mike@luckycornhole.com')->firstOrFail();
$check($mike->role === 'operator' && Hash::check($data['password'], $mike->password), 'Mike created as normal user with hashed password');
try { $controller->store($request($data)); throw new RuntimeException('Duplicate email admitted'); }
catch (Illuminate\Validation\ValidationException $e) { $check(isset($e->errors()['email']), 'Duplicate email rejected'); }
$oldPassword = $mike->password;
$controller->update($request(['name'=>'Mike Updated','email'=>$mike->email,'role'=>'operator','password'=>'','password_confirmation'=>'']), $mike);
$check($mike->fresh()->name === 'Mike Updated' && $mike->fresh()->password === $oldPassword, 'Edit preserves blank password');
$controller->update($request(['name'=>$mike->name,'email'=>$mike->email,'role'=>'operator','password'=>'ChangedPassword123','password_confirmation'=>'ChangedPassword123']), $mike);
$check(Hash::check('ChangedPassword123', $mike->fresh()->password) && strlen($mike->fresh()->remember_token) === 60, 'Password update hashes password and rotates remember token');
foreach ([['password'=>'short','password_confirmation'=>'short'], ['password_confirmation'=>'Mismatch12345']] as $bad) {
    try { $controller->store($request(array_replace($data, ['email'=>'new@example.test'], $bad))); throw new RuntimeException('Invalid password admitted'); }
    catch (Illuminate\Validation\ValidationException $e) { $check(isset($e->errors()['password']), 'Invalid or mismatched password rejected'); }
}
$controller->update($request(['name'=>'Admin','email'=>$admin->email,'role'=>'operator']), $admin);
$check($admin->fresh()->role === 'admin', 'Self-demotion blocked');
try { $controller->store($request(array_replace($data, ['email'=>'other@example.test','role'=>'superuser']))); throw new RuntimeException('Bad role admitted'); }
catch (Illuminate\Validation\ValidationException $e) { $check(isset($e->errors()['role']), 'Invalid role rejected'); }

DB::statement('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
DB::table('migrations')->insert(['migration'=>'preserve-me','batch'=>1]);
$item = App\Models\ProductionItem::create(['line_item_id'=>'reset-test','product'=>'Reset test','quantity'=>1]);
DB::table('verification_decisions')->insert(['line_item_id'=>'reset-test','decision'=>'CORRECT','drive_file_ids'=>'[]','updated_by'=>$admin->id]);
$batch = App\Models\PrintBatch::create(['batch_id'=>'reset-batch','status'=>'QUEUED','order_count'=>1,'artwork_file_count'=>1,'payload'=>[]]);
DB::table('batch_confirmations')->insert(['print_batch_id'=>$batch->id,'user_id'=>$admin->id,'action'=>'HANDOFF','signer_name'=>'Admin','account_name'=>'Admin','confirmed_at'=>now(),'order_count'=>1,'total_sets'=>1,'artwork_file_count'=>1,'payload_sha256'=>str_repeat('a',64)]);
$valid = ['password'=>'AdminPassword123','confirmation'=>'RESET PORTAL','agents_paused'=>'1'];
foreach ([['password'=>'incorrect'], ['confirmation'=>'RESET'], ['agents_paused'=>'0']] as $bad) {
    try { $controller->reset($request(array_replace($valid, $bad))); throw new RuntimeException('Unsafe reset admitted'); }
    catch (Illuminate\Validation\ValidationException $e) { $check(DB::table('production_items')->count() === 1, 'Invalid reset confirmation preserves records'); }
}
$usersBefore = User::orderBy('id')->get()->toJson();
$controller->reset($request($valid));
foreach (AdminController::RESET_TABLES as $table) $check(DB::table($table)->count() === 0, 'Reset clears '.$table);
$check(User::orderBy('id')->get()->toJson() === $usersBefore, 'Reset preserves every user and credential');
$check(DB::table('migrations')->count() === 1, 'Reset preserves migration history');
App\Models\ProductionItem::create(['line_item_id'=>'reset-test','product'=>'Fresh Shopify import','quantity'=>1]);
$check(DB::table('production_items')->count() === 1, 'Previously imported item can be imported after reset');
foreach (['admin.users'=>['users'=>User::paginate(20)], 'admin.user-form'=>['user'=>$mike], 'admin.reset'=>['counts'=>collect(AdminController::RESET_TABLES)->mapWithKeys(fn($table)=>[$table=>DB::table($table)->count()])]] as $view=>$data) {
    $check(str_contains(view($view, $data)->render(), '<main>'), 'Admin view renders: '.$view);
}
Auth::login($normal);
$menu = view('partials.account-menu')->render();
$check(!str_contains($menu, '/admin/'), 'Normal-user menu hides admin controls');
echo "Admin controls checks completed.\n";
