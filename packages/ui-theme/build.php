<?php
// Publish the package's canonical CSS to existing asset URLs. --check never writes.
$root=dirname(__DIR__,2);$check=in_array('--check',$argv,true);$failed=false;
foreach(['app.css','apparel.css'] as $name){
    $source=__DIR__.'/resources/css/'.$name;$target=$root.'/public/css/'.$name;
    $bytes=file_get_contents($source);
    if($check){if(!is_file($target) || str_replace("\r\n","\n",file_get_contents($target))!==str_replace("\r\n","\n",$bytes)){fwrite(STDERR,'Theme asset is stale: '.$name.PHP_EOL);$failed=true;}}
    elseif(file_put_contents($target,$bytes)===false){fwrite(STDERR,'Cannot publish theme asset: '.$name.PHP_EOL);$failed=true;}
}
if($failed)exit(1);
echo $check?"Theme assets match their package sources.\n":"Theme assets published.\n";
