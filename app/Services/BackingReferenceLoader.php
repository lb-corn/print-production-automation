<?php
namespace App\Services;

use Google\Client;
use Google\Service\Drive;
use GuzzleHttp\Client as HttpClient;
use RuntimeException;

/** Reads bounded private Drive images using the service account. */
class BackingReferenceLoader
{
    private ?\GuzzleHttp\ClientInterface $driveClient=null;
    private function bounded($response):string {
        if($response->getStatusCode()!==200 || (int)$response->getHeaderLine('Content-Length')>8388608)throw new RuntimeException('Reference image is unavailable or too large.');
        $body=$response->getBody();$bytes='';
        try {while(!$body->eof() && strlen($bytes)<=8388608)$bytes.=$body->read(min(65536,8388609-strlen($bytes)));}
        finally {$body->close();}
        if(strlen($bytes)>8388608)throw new RuntimeException('Reference image is too large.');
        return $bytes;
    }
    public function product(string $url):string {
        $parts=parse_url($url);
        if(!$parts || ($parts['scheme']??'')!=='https' || isset($parts['user'],$parts['pass']) || isset($parts['user']) || isset($parts['port'])
            || !in_array(strtolower($parts['host']??''),config('services.backing_colors.product_image_hosts',[]),true))throw new RuntimeException('Website image host is not configured for comparison.');
        return $this->bounded((new HttpClient(['timeout'=>15,'connect_timeout'=>5,'allow_redirects'=>false]))->get($url,['stream'=>true]));
    }
    public function swatch(array $file):string {
        if(!in_array($file['mimeType']??'', ['image/jpeg','image/png','image/webp'],true))throw new RuntimeException('Unsupported swatch format.');
        $this->authorizeDrive();
        $response=$this->driveClient->get('https://www.googleapis.com/drive/v3/files/'.rawurlencode($file['id']).'?alt=media&supportsAllDrives=true',['stream'=>true]);
        return $this->bounded($response);
    }
    private function authorizeDrive():void {
        if($this->driveClient===null){
            $client=new Client();$client->setHttpClient(new HttpClient(['timeout'=>15,'connect_timeout'=>5,'allow_redirects'=>false]));
            $client->setAuthConfig(config('services.google_drive.service_account_file'));$client->setScopes([Drive::DRIVE_READONLY]);
            $this->driveClient=$client->authorize();
        }
    }
    public function artworkPreview(string $id):string {
        $this->authorizeDrive();
        $metadata=$this->driveClient->get('https://www.googleapis.com/drive/v3/files/'.rawurlencode($id),['query'=>['fields'=>'thumbnailLink,mimeType','supportsAllDrives'=>'true']]);
        $file=json_decode((string)$metadata->getBody(),true,512,JSON_THROW_ON_ERROR);
        if(empty($file['thumbnailLink']))return $this->swatch(['id'=>$id,'mimeType'=>$file['mimeType']??'']);
        $url=$file['thumbnailLink'];$parts=parse_url($url);$host=strtolower($parts['host']??'');
        if(($parts['scheme']??'')!=='https' || !($host==='googleusercontent.com' || str_ends_with($host,'.googleusercontent.com')) || isset($parts['user']) || isset($parts['port']))throw new RuntimeException('Unexpected Drive thumbnail host.');
        $url=preg_replace('/=s\d+(?:-[A-Za-z0-9]+)*$/','=s800',$url);
        return $this->bounded($this->driveClient->get($url,['stream'=>true]));
    }
}
