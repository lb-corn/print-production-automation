<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);});
$check=function($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS: '.$message.PHP_EOL;};
$parser=new App\Services\MaterialSheetParser;
$header=['Bag Name','Bag Code','Front Material','Back Material','Type','Template Size'];
$values=[['Partner Bags Configuration'],$header,['Example','EX','Fabric A','Backing A','Single','Small'],[],['Partner Single Side Back Material Configuration'],['Backing A','Backing B'],['Charcoal','Red'],['White','Blue']];
$result=$parser->parse($values);
$check($result['bags']['EX']['back']==='Backing A','Title rows do not prevent reading the bag table');
$check($result['backing_materials'][0]===['material'=>'Backing A','colors'=>['Charcoal','White']],'Backing colors are read as their own table');
$spaced=$values;$second=$values[2];$second[1]='EX2';array_splice($spaced,4,0,[$second]);
$check(count($parser->parse($spaced)['bags'])===2,'Blank rows do not silently omit later bag configurations');
$other=$values;$other[2][2]='Other partner fabric';
$check($parser->parse($other)['bags']['EX']['front']==='Other partner fabric' && $parser->parse($values)['bags']['EX']['front']==='Fabric A','Same code in separate tabs does not mix material mappings');
foreach(['missing code','duplicate code','invalid type'] as $case){$invalid=$values;if($case==='missing code')$invalid[2][1]='';elseif($case==='duplicate code')array_splice($invalid,3,0,[$invalid[2]]);else $invalid[2][4]='Unknown';try{$parser->parse($invalid);throw new LogicException('Invalid configuration accepted');}catch(RuntimeException $e){$check(!($e instanceof LogicException),'Configuration rejects '.$case);}}
$flat=[$header,$values[2]];$stream=fopen('php://temp','r+');foreach($flat as $row)fputcsv($stream,$row,',','"','');rewind($stream);$csv=stream_get_contents($stream);fclose($stream);
$check((new App\Services\MaterialCatalog)->parse($csv)===$parser->parse($flat)['bags'],'Existing flat CSV material sheets remain supported');
echo "Material sheet parser checks passed.\n";
