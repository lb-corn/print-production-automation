<?php
namespace App\Http\Controllers;
use App\Services\FactoryMonitoringService;
use Illuminate\Http\Request;
class FactoryMonitoringController extends Controller {
    public function health(FactoryMonitoringService $monitor){
        return response()->json(['ok'=>true,'authenticated'=>true,'server_time'=>now()->toIso8601String(),'monitoring_ready'=>$monitor->ready(),'protocol'=>1])->header('Cache-Control','no-store')->header('X-LuckyBags-Portal','factory-monitor');
    }
    public function heartbeat(Request $request,FactoryMonitoringService $monitor){
        abort_if(strlen($request->getContent())>8192,413,'Telemetry exceeds the size limit.');
        abort_unless($monitor->ready(),503,'Apply the factory_agents migration before sending telemetry.');
        $form=strtolower(trim(explode(';',(string)$request->header('Content-Type'))[0]))==='application/x-www-form-urlencoded';
        abort_unless($request->isJson() || $form,415,'Use JSON or URL-encoded telemetry.');
        $input=$request->isJson()?$request->json()->all():$request->request->all();
        if($form){$normalize=function($value)use(&$normalize){return is_array($value)?array_map($normalize,$value):($value===''?null:$value);};$input=$normalize($input);}
        $monitor->accept($input);
        return response()->json(['ok'=>true,'server_time'=>now()->toIso8601String()])->header('Cache-Control','no-store')->header('X-LuckyBags-Portal','factory-monitor');
    }
    public function status(Request $request,FactoryMonitoringService $monitor){
        abort_unless($request->user()?->role==='admin',403);
        return response()->json($monitor->snapshot())->header('Cache-Control','no-store');
    }
    public function connection(FactoryMonitoringService $monitor){return response()->json($monitor->connection())->header('Cache-Control','no-store');}
    public function connectivityScript(Request $request){
        abort_unless($request->user()?->role==='admin',403);
        return response()->download(resource_path('factory-agent-test-connectivity.ps1'),'test-connectivity.ps1',['Cache-Control'=>'no-store']);
    }
    public function connectionReporter(Request $request){
        abort_unless($request->user()?->role==='admin',403);
        return response()->download(resource_path('factory-agent-report-connection.ps1'),'report-connection.ps1',['Cache-Control'=>'no-store']);
    }
}
