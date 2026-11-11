<?php
namespace App\Http\Controllers;

use App\Services\{PartnerContext,BackingReferenceLoader};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache,URL};

class ArtworkPreviewController extends Controller
{
    public static function url(string $id):string {
        return URL::temporarySignedRoute('artwork.preview',now()->addHours(2),['file'=>$id,'partner'=>PartnerContext::current()]);
    }
    public function show(Request $request,string $file) {
        $bytes=Cache::remember(PartnerContext::key('artwork.preview.'.hash('sha256',$file)),300,fn()=>app(BackingReferenceLoader::class)->artworkPreview($file));
        $size=@getimagesizefromstring($bytes);
        abort_unless($size && in_array($size['mime']??'', ['image/jpeg','image/png','image/webp'],true),404);
        return response($bytes)->header('Content-Type',$size['mime'])->header('Cache-Control','private, max-age=300')->header('X-Content-Type-Options','nosniff')->header('Vary','Cookie');
    }
}
