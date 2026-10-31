<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // Update only the existing requested account; do not create credentials.
        DB::table('users')->where('email', 'demo@lbprint.com')->update(['role' => 'demo']);
    }
    public function down(): void {
        // Account permissions must not be broadened automatically on rollback.
    }
};
