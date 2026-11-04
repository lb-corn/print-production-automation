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
    public function repairStartup(Request $request){
        abort_unless($request->user()?->role==='admin',403);
        return response()->download(resource_path('factory-agent-repair-startup.ps1'),'repair-background-startup.ps1',['Cache-Control'=>'no-store']);
    }
    public function handoffControl(Request $request,\App\Models\FactoryAgent $agent,\App\Services\FactoryDispatchService $dispatch){
        abort_unless($request->user()?->role==='admin',403);
        $data=$request->validate(['blocked'=>'required|boolean','confirm_control'=>'required|accepted']);
        $dispatch->setHandoffBlocked($agent,(bool)$data['blocked'],$request->user()->id);
        return redirect()->route('admin.settings',['section'=>'agent'])->with('ok',$data['blocked']?'New handoffs blocked for all users. A delivery already started may finish safely; this does not stop a physical print.':'Handoff allowed. Previously signed waiting handoffs may now proceed.');
    }
    public function processing(Request $request,\App\Models\FactoryAgent $agent,\App\Services\FactoryDispatchService $dispatch){
        abort_unless($request->user()?->role==='admin',403);
        $data=$request->validate(['enabled'=>'required|boolean','confirm_control'=>'required|accepted']);
        $dispatch->configure($agent,(bool)$data['enabled'],$request->user()->id);
        return redirect()->route('admin.settings',['section'=>'agent'])->with('ok',$data['enabled']?'Portal jobs enabled for this Factory PC. Only authorized queue requests and signed handoffs can launch work.':'New jobs paused. An operation already running may finish safely; this does not stop a physical print.');
    }
    public function updater(Request $request){
        abort_unless($request->user()?->role==='admin',403);
        $release=json_decode(file_get_contents(resource_path('factory-agent-release.json')),true);
        $version=$release['version']??'';$sha=$release['portal_zip_sha256']??'';
        abort_unless(preg_match('/^\d+\.\d+\.\d+$/',$version) && preg_match('/^[a-f0-9]{64}$/',$sha),503,'Agent release metadata is unavailable.');
        $relative=\Illuminate\Support\Facades\URL::temporarySignedRoute('factory-agent.release',now()->addDays(2),['version'=>$version,'sha'=>$sha],false);
        $base=rtrim((string)config('app.url'),'/');abort_unless(str_starts_with($base,'https://'),422,'Set the existing APP_URL to the public HTTPS portal address.');
        $script=file_get_contents(resource_path('factory-agent-updater.ps1'));
        $script=strtr($script,['__PACKAGE_URL__'=>str_replace("'","''",$base.$relative),'__PACKAGE_SHA256__'=>$sha,'__PACKAGE_VERSION__'=>$version]);
        $launcher=<<<'CMD'
@echo off
setlocal DisableDelayedExpansion
set "LUCKYBAGS_UPDATE_SELF=%~f0"
powershell.exe -NoProfile -ExecutionPolicy RemoteSigned -Command "$lines=[IO.File]::ReadAllLines($env:LUCKYBAGS_UPDATE_SELF); $index=[Array]::IndexOf($lines,'# POWERSHELL_START'); if($index -lt 0){exit 1}; $temp=Join-Path ([IO.Path]::GetTempPath()) ('LuckyBags-Launch-'+[guid]::NewGuid()+'.ps1'); [IO.File]::WriteAllLines($temp,$lines[($index+1)..($lines.Length-1)]); try { & $temp } finally { Remove-Item -LiteralPath $temp -ErrorAction SilentlyContinue }"
set "UPDATE_RESULT=%ERRORLEVEL%"
echo.
pause
exit /b %UPDATE_RESULT%
# POWERSHELL_START
CMD;
        return response(str_replace("\n","\r\n",$launcher)."\r\n".$script,200,['Content-Type'=>'application/octet-stream','Content-Disposition'=>'attachment; filename="Update-LuckyBags-Agent-v'.$version.'.cmd"','Cache-Control'=>'no-store']);
    }
    public function signedPackage(Request $request,string $version){
        $release=json_decode(file_get_contents(resource_path('factory-agent-release.json')),true);$path=resource_path('factory-agent-install.zip');
        abort_unless(($release['version']??null)===$version && is_file($path) && hash_equals((string)($release['portal_zip_sha256']??''),(string)$request->query('sha')) && hash_equals((string)$request->query('sha'),hash_file('sha256',$path)),409,'A newer release is available. Download a fresh updater from Admin.');
        return response()->download($path,'LuckyBags-Factory-Agent-v'.$version.'.zip',['Cache-Control'=>'no-store']);
    }
    public function portalPackage(Request $request){
        abort_unless($request->user()?->role==='admin',403);
        if($request->filled('version')){
            $version=(string)$request->query('version');
            return response()->download(app(\App\Services\FactoryAgentReleaseService::class)->package($version),'LuckyBags-Factory-Agent-v'.$version.'.zip',['Cache-Control'=>'no-store']);
        }
        abort_unless(is_file(resource_path('factory-agent-install.zip')),404,'Managed agent package is not available.');
        return response()->download(resource_path('factory-agent-install.zip'),'LuckyBags-Factory-Agent.zip',['Cache-Control'=>'no-store']);
    }
    public function portalSetup(Request $request){
        abort_unless($request->user()?->role==='admin',403);
        return response()->download(resource_path('factory-agent-install-portal-control.ps1'),'install-portal-control.ps1',['Cache-Control'=>'no-store']);
    }
}
