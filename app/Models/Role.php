<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Role extends Model {
    protected $fillable=['name','slug','permissions'];
    protected function casts(): array { return ['permissions'=>'array']; }
}
