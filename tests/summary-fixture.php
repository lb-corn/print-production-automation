<?php
app()->instance(\App\Services\ProductionSummaryService::class,new class extends \App\Services\ProductionSummaryService {
 public function forUser(\App\Models\User $user,bool $refresh=false):array {
  $areas=[];
  if($user->allows('bags.overview.view'))$areas['bags']=['ready'=>0,'active'=>0,'done'=>0];
  if($user->allows('apparel.overview.view'))$areas['apparel']=['waiting'=>0,'active'=>0,'done'=>0];
  return ['areas'=>$areas];
 }
});
