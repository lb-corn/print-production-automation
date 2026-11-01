<?php
namespace App\Services;

use Google\Client;
use Google\Service\Drive;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/** Metadata-only writer; normal indexing still uses the existing read-only client. */
class GoogleDriveRenameService
{
    private const FIELDS='id,title,parents(id),mimeType,labels(trashed),modifiedDate,md5Checksum,version,etag,capabilities(canRename)';
    public function __construct(private ?ClientInterface $http=null) {}
    private function client(): ClientInterface
    {
        if($this->http)return $this->http;
        $credentials=config('services.google_drive.service_account_file');
        if(!$credentials || !is_file($credentials))throw new RuntimeException('Drive rename credentials unavailable.');
        $client=new Client();$client->setAuthConfig($credentials);$client->setScopes([Drive::DRIVE_METADATA]);
        return $this->http=$client->authorize(new \GuzzleHttp\Client(['timeout'=>20,'connect_timeout'=>5,'allow_redirects'=>false]));
    }
    /** Read-only preflight, also repeated immediately before every conditional PATCH. */
    public function verify(array $expected): array
    {
        $response=$this->client()->request('GET',$this->url($expected['id']),['query'=>['fields'=>self::FIELDS,'supportsAllDrives'=>'true'],'http_errors'=>false]);
        if($response->getStatusCode()!==200)throw new RuntimeException('Cannot verify current Drive file.');
        $file=$this->normalize(json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR));
        if(!$file['canRename'])throw new RuntimeException('The service account needs Editor access to this approved Drive folder before files can be renamed.');
        if(!$file['etag'] || empty($expected['version']) || $file['trashed'] || $file['id']!==$expected['id']
            || $file['name']!==$expected['name'] || $file['parents']!==[$expected['parent_id']]
            || $file['modifiedTime']!==($expected['modifiedTime']??null) || $file['md5Checksum']!==($expected['md5Checksum']??null)
            || $file['version']!==(string)$expected['version'] || str_starts_with($file['mimeType'],'application/vnd.google-apps.'))throw new RuntimeException('File changed or has no concurrency validator. Scan again.');
        return $file;
    }
    public function rename(array $expected,string $target): array
    {
        abort_unless(config('services.google_drive.rename_enabled',false),403,'Drive renaming is disabled in server configuration.');
        if($target==='' || strlen($target)>255 || preg_match('~[\\\\/\x00-\x1f]~',$target)
            || pathinfo($expected['name'],PATHINFO_EXTENSION)!==pathinfo($target,PATHINFO_EXTENSION))throw new RuntimeException('Invalid rename target or extension change.');
        $file=$this->verify($expected);
        // v2 exposes an explicit etag. Only the title metadata is patched; no content or parents are sent.
        $response=$this->client()->request('PATCH',$this->url($expected['id']),['query'=>['fields'=>self::FIELDS,'supportsAllDrives'=>'true'],
            'headers'=>['If-Match'=>$file['etag']],'json'=>['title'=>$target],'http_errors'=>false]);
        if($response->getStatusCode()!==200)throw new RuntimeException('Rename was not confirmed. Recheck Drive before retrying.');
        $after=$this->normalize(json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR));
        if($after['id']!==$file['id'] || $after['name']!==$target || $after['parents']!==$file['parents']
            || $after['md5Checksum']!==$file['md5Checksum'])throw new RuntimeException('Unexpected rename response. Recheck Drive.');
        return $after;
    }
    private function url(string $id): string { return 'https://www.googleapis.com/drive/v2/files/'.rawurlencode($id); }
    private function normalize(array $file): array
    {
        return ['id'=>$file['id']??null,'name'=>$file['title']??null,'parents'=>array_column($file['parents']??[],'id'),
            'mimeType'=>$file['mimeType']??'','trashed'=>$file['labels']['trashed']??false,'modifiedTime'=>$file['modifiedDate']??null,
            'md5Checksum'=>$file['md5Checksum']??null,'version'=>(string)($file['version']??''),'etag'=>$file['etag']??null,'canRename'=>$file['capabilities']['canRename']??false];
    }
}
