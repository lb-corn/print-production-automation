<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
class User extends Authenticatable {
    protected $fillable=['name','email','password','role'];
    protected $hidden=['password','remember_token'];
    protected function casts(): array { return ['password'=>'hashed','is_active'=>'boolean']; }
    public function accessRole() { return $this->belongsTo(Role::class,'role','slug'); }
    public function allows(string $permission): bool {
        if($this->role==='admin') return true;
        if($this->role==='demo' && !str_ends_with($permission,'.view')) return false;
        return in_array($permission,$this->accessRole?->permissions ?? [],true);
    }
    public function roleLabel(): string { return $this->role==='admin' ? 'Admin' : ($this->accessRole?->name ?? 'Unassigned'); }
}
