<?php
namespace App\Http\Controllers;
use App\Models\Role;
use App\Services\AccessCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Services\AccessAudit;
class RoleController extends Controller {
    public function index() { return view('admin.settings',['section'=>'roles','roles'=>Role::orderBy('name')->get(),'permissions'=>AccessCatalog::permissions(),'audits'=>DB::table('access_audits')->leftJoin('users','users.id','=','access_audits.actor_id')->select('access_audits.*','users.name as actor_name')->orderByDesc('access_audits.id')->limit(20)->get()]); }
    private function data(Request $request, ?Role $role = null): array {
        $data=$request->validate(['name'=>['required','string','max:80',Rule::unique('roles','name')->ignore($role?->id),'not_regex:/^(admin|demo)$/i'],'permissions'=>['sometimes','array'],'permissions.*'=>['string',Rule::in(array_keys(AccessCatalog::permissions()))]]);
        $data['permissions']=array_values(array_unique($data['permissions'] ?? []));
        foreach($data['permissions'] as $permission) {
            $parts=explode('.',$permission);
            if(in_array($parts[0],['bags','apparel'],true)) $data['permissions'][]=$parts[0].'.overview.view';
            if($parts[0]==='apparel' && in_array($parts[1],['tracking','remarks'],true)) $data['permissions'][]='apparel.orders.view';
            $view=$parts[0].'.'.$parts[1].'.view';
            if($parts[2]!=='view' && isset(AccessCatalog::permissions()[$view])) $data['permissions'][]=$view;
        }
        $data['permissions']=array_values(array_unique($data['permissions']));
        foreach($data['permissions'] as $permission) abort_unless($request->user()->allows($permission),403,'You cannot grant permissions you do not hold.');
        return $data;
    }
    public function store(Request $request) {
        $data=$this->data($request);$data['slug']='custom-'.Str::uuid();DB::transaction(function() use($data,$request){$role=Role::create($data);AccessAudit::record($request->user()->id,'role.created','role',$role->slug,null,$data);});
        return back()->with('ok','Role created. Assign it from Manage Users.');
    }
    public function update(Request $request, Role $role) {
        abort_if($role->slug==='demo',403,'Demo is permanently read only.');
        if($request->user()->role!=='admin') {
            abort_if($request->user()->role===$role->slug,403,'You cannot change your own role permissions.');
            foreach($role->permissions as $permission) abort_unless($request->user()->allows($permission),403);
        }
        $data=$this->data($request,$role);
        DB::transaction(function()use($role,$data,$request){$before=$role->only(['name','permissions']);$role->update($data);AccessAudit::record($request->user()->id,'role.updated','role',$role->slug,$before,$data);});
        return back()->with('ok','Role updated. Permissions apply to existing sessions on their next request.');
    }
}
