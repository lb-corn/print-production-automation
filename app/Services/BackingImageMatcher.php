<?php
namespace App\Services;

use RuntimeException;

/** Conservative color comparison of an isolated backside and reference scans, using GD. */
class BackingImageMatcher
{
    private function image(string $bytes) {
        if (!function_exists('imagecreatefromstring')) throw new RuntimeException('GD image support is required.');
        $size=@getimagesizefromstring($bytes);
        if (!$size || strlen($bytes)>8388608 || $size[0]*$size[1]>16000000 || min($size[0],$size[1])<32
            || !in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)) throw new RuntimeException('Unsupported reference image.');
        $source=@imagecreatefromstring($bytes);if(!$source)throw new RuntimeException('Reference image could not be decoded.');
        $w=192;$h=max(1,(int)round(imagesy($source)*192/imagesx($source)));
        if($h>256){$w=max(1,(int)round($w*256/$h));$h=256;}
        $small=imagecreatetruecolor($w,$h);imagefill($small,0,0,0xffffff);
        imagecopyresampled($small,$source,0,0,0,0,$w,$h,imagesx($source),imagesy($source));imagedestroy($source);
        return $small;
    }
    private function rgb($image,int $x,int $y):array {$n=imagecolorat($image,$x,$y);return [($n>>16)&255,($n>>8)&255,$n&255];}
    private function median(array $values):float {sort($values);return $values[intdiv(count($values),2)];}
    private function descriptor(array $pixels):array {
        if(count($pixels)<30)throw new RuntimeException('Not enough backside pixels.');
        $rgb=[];foreach([0,1,2] as $c)$rgb[]=$this->median(array_column($pixels,$c));
        $spread=0;foreach($pixels as $p)$spread+=sqrt(array_sum(array_map(fn($v,$m)=>($v-$m)**2,$p,$rgb)));
        return ['rgb'=>$rgb,'lab'=>$this->lab($rgb),'spread'=>$spread/count($pixels)];
    }
    private function lab(array $rgb):array {
        $v=array_map(function($n){$n/=255;return $n<=.04045?$n/12.92:(($n+.055)/1.055)**2.4;},$rgb);
        [$r,$g,$b]=$v;
        $f=fn($n)=>$n>.008856?$n**(1/3):7.787*$n+16/116;
        $x=$f((.4124564*$r+.3575761*$g+.1804375*$b)/.95047);
        $y=$f(.2126729*$r+.7151522*$g+.0721750*$b);
        $z=$f((.0193339*$r+.1191920*$g+.9503041*$b)/1.08883);
        return [116*$y-16,500*($x-$y),200*($y-$z)];
    }
    public function profile(string $bytes):array {
        $swatch=$this->image($bytes);
        try {$pixels=[];$w=imagesx($swatch);$h=imagesy($swatch);
            for($y=(int)($h*.2);$y<$h*.8;$y+=2)for($x=(int)($w*.2);$x<$w*.8;$x+=2)$pixels[]=$this->rgb($swatch,$x,$y);
            return $this->descriptor($pixels);
        } finally {imagedestroy($swatch);}
    }
    /** FPD view 2 already isolates the backing; sample its central fabric area. */
    public function compareBackside(string $backside,array $references):array {
        $target=$this->profile($backside);$ranked=[];
        foreach($references as $name=>$reference){
            $descriptor=is_array($reference)?$reference:$this->profile($reference);
            $distance=sqrt(array_sum(array_map(fn($a,$b)=>($a-$b)**2,$target['lab'],$descriptor['lab'])));
            $ranked[]=['color'=>$name,'distance'=>round($distance,2)];
        }
        usort($ranked,fn($a,$b)=>$a['distance']<=>$b['distance']);
        $clear=count($ranked)>=2 && $ranked[0]['distance']<18 && $ranked[1]['distance']-$ranked[0]['distance']>=3;
        return ['suggested'=>$clear?$ranked[0]['color']:null,'candidates'=>array_slice($ranked,0,3),
            'message'=>$clear?'Possible FPD backing match. Check the backside image and confirm the color before approval.':'No clear FPD backing match. Compare the backside image and choose an allowed color.'];
    }
    public function compare(string $product,array $references):array {
        $image=$this->image($product);
        try {
            $w=imagesx($image);$h=imagesy($image);
            $corners=[$this->rgb($image,0,0),$this->rgb($image,$w-1,0),$this->rgb($image,0,$h-1),$this->rgb($image,$w-1,$h-1)];
            $bg=[];foreach([0,1,2] as $c)$bg[]=$this->median(array_column($corners,$c));
            $mask=[];$seen=[];
            for($y=0;$y<$h;$y++)for($x=0;$x<$w;$x++){
                $rgb=$this->rgb($image,$x,$y);
                $mask[$y*$w+$x]=sqrt(array_sum(array_map(fn($a,$b)=>($a-$b)**2,$rgb,$bg)))>40;
            }
            $components=[];
            for($seed=0;$seed<$w*$h;$seed++){
                if(!$mask[$seed] || isset($seen[$seed]))continue;
                $queue=[$seed];$seen[$seed]=true;$xs=[];$ys=[];
                for($head=0;$head<count($queue);$head++){
                    $n=$queue[$head];$x=$n%$w;$y=intdiv($n,$w);$xs[]=$x;$ys[]=$y;
                    foreach([[-1,0],[1,0],[0,-1],[0,1]] as [$dx,$dy]){$nx=$x+$dx;$ny=$y+$dy;$next=$ny*$w+$nx;
                        if($nx>=0 && $ny>=0 && $nx<$w && $ny<$h && $mask[$next] && !isset($seen[$next])){$seen[$next]=true;$queue[]=$next;}}
                }
                if(count($queue)<$w*$h*.035)continue;
                $left=min($xs);$right=max($xs);$top=min($ys);$bottom=max($ys);$cw=$right-$left+1;$ch=$bottom-$top+1;
                if($cw<$w*.15 || $ch<$h*.18 || $cw>$w*.72 || $ch>$h*.88 || $cw/$ch<.45 || $cw/$ch>1.9)continue;
                $pixels=[];foreach($queue as $n){$x=$n%$w;$y=intdiv($n,$w);if($x>$left+$cw*.2 && $x<$right-$cw*.2 && $y>$top+$ch*.2 && $y<$bottom-$ch*.2)$pixels[]=$this->rgb($image,$x,$y);}
                if(count($pixels)<30)continue;
                $components[]=['description'=>$this->descriptor($pixels),'crop'=>['x'=>$left/$w,'y'=>$top/$h,'width'=>$cw/$w,'height'=>$ch/$h]];
            }
            if(count($components)!==2)return ['suggested'=>null,'candidates'=>[],'message'=>'The backside could not be isolated reliably. Compare the original website image with the swatches and choose a color.'];
            usort($components,fn($a,$b)=>$a['description']['spread']<=>$b['description']['spread']);
            if($components[1]['description']['spread']-$components[0]['description']['spread']<3)return ['suggested'=>null,'candidates'=>[],'message'=>'Front and back look too similar to isolate reliably. Select the backing color manually.'];
            $target=$components[0];$ranked=[];
            foreach($references as $name=>$reference){
                $descriptor=is_array($reference)?$reference:$this->profile($reference);
                $distance=sqrt(array_sum(array_map(fn($a,$b)=>($a-$b)**2,$target['description']['lab'],$descriptor['lab'])));
                $ranked[]=['color'=>$name,'distance'=>round($distance,2)];
            }
            usort($ranked,fn($a,$b)=>$a['distance']<=>$b['distance']);
            $clear=count($ranked)>=2 && $ranked[0]['distance']<18 && $ranked[1]['distance']-$ranked[0]['distance']>=3;
            return ['suggested'=>$clear?$ranked[0]['color']:null,'candidates'=>array_slice($ranked,0,3),'crop'=>$target['crop'],
                'message'=>$clear?'Possible backing-color match. Compare the highlighted backside and confirm before approval.':'No clear swatch match. Compare the highlighted backside and select the correct sheet color.'];
        } finally {imagedestroy($image);}
    }
}
