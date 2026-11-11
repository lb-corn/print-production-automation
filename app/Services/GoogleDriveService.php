<?php

namespace App\Services;

use Google\Client;
use Google\Service\Drive;
use RuntimeException;

class GoogleDriveService
{
    private Drive $drive;
    private string $rootId;
    private array $allowedFolders;

    public function __construct()
    {
        $credentials = config(
            'services.google_drive.service_account_file'
        );

        $this->rootId = trim(
            PartnerContext::current()==='lucky'?(string)config('services.google_drive.artwork_root_id'):(string)config('services.partners.'.PartnerContext::current().'.drive_root_id')
        );

        $this->allowedFolders = config(
            'services.google_drive.allowed_root_folders',
            []
        );

        if (!$credentials || !file_exists($credentials)) {
            throw new RuntimeException(
                'Google service account file not found: ' .
                $credentials
            );
        }

        if ($this->rootId === '' && !config('services.google_drive.factory_root_id')) {
            throw new RuntimeException(
                'Google Drive artwork root is not configured.'
            );
        }

        $client = new Client();

        $client->setAuthConfig($credentials);

        $client->setScopes([
            Drive::DRIVE_READONLY,
        ]);

        $this->drive = new Drive($client);
    }

    public function partnerRoot(): string
    {
        $factory=trim((string)config('services.google_drive.factory_root_id'));
        if($factory==='')return $this->rootId;
        $name=(string)config('services.partners.'.PartnerContext::current().'.drive_folder');
        if($name==='')throw new RuntimeException('Partner artwork folder name is not configured.');
        return \Illuminate\Support\Facades\Cache::remember('drive.partner-root.'.hash('sha256',json_encode([$factory,$name,config('services.google_drive.service_account_file')])),300,function()use($factory,$name){
            $folders=array_values(array_filter($this->listChildren($factory),fn($file)=>$file['mimeType']==='application/vnd.google-apps.folder' && $file['name']===$name));
            if(count($folders)!==1)throw new RuntimeException('The partner folder is missing or ambiguous inside the configured factory root.');
            return $folders[0]['id'];
        });
    }

    /**
     * List direct children of a Drive folder.
     */
    public function listChildren(string $folderId): array
    {
        $files = [];
        $pageToken = null;

        do {
            $response = $this->drive->files->listFiles([
                'q' =>
                    sprintf(
                        "'%s' in parents and trashed = false",
                        addslashes($folderId)
                    ),

                'fields' =>
                    'nextPageToken,files(' .
                    'id,name,mimeType,parents,' .
                    'webViewLink,imageMediaMetadata,modifiedTime,md5Checksum,version,capabilities(canRename)' .
                    ')',

                'pageSize' => 1000,

                'pageToken' => $pageToken,

                'supportsAllDrives' => true,

                'includeItemsFromAllDrives' => true,
            ]);

            foreach ($response->getFiles() as $file) {
                $metadata = $file->getImageMediaMetadata();

                $files[] = [
                    'id' => $file->getId(),
                    'modifiedTime' => $file->getModifiedTime(),
                    'version' => $file->getVersion(),
                    'canRename' => $file->getCapabilities()?->getCanRename(),
                    'md5Checksum' => $file->getMd5Checksum(),
                    'name' => $file->getName(),
                    'mimeType' => $file->getMimeType(),
                    'webViewLink' => $file->getWebViewLink(),

                    'width' =>
                        $metadata
                            ? $metadata->getWidth()
                            : null,

                    'height' =>
                        $metadata
                            ? $metadata->getHeight()
                            : null,
                ];
            }

            $pageToken = $response->getNextPageToken();

        } while ($pageToken);

        return $files;
    }

    /**
     * Return only the approved manufacturing
     * branches underneath "2027 Bags".
     */
    public function manufacturingRoots(): array
    {
        return collect(
            $this->listChildren($this->partnerRoot())
        )
            ->filter(
                fn (array $file) =>
                    $file['mimeType']
                        === 'application/vnd.google-apps.folder'
                    &&
                    in_array(
                        $file['name'],
                        $this->allowedFolders,
                        true
                    )
            )
            ->values()
            ->all();
    }

    /** Search every production subfolder within the selected partner's approved series. */
    public function artworkIndex(): array
    {
        $results = [];
        foreach ($this->manufacturingRoots() as $root) {
            foreach ($this->listChildren($root['id']) as $folder) {
                if ($folder['mimeType'] !== 'application/vnd.google-apps.folder' || !ArtworkMatcherService::seriesForFolder($folder['name'])) continue;
                if (preg_match('/(?:^|[^A-Z0-9])(MOCKUPS?|PREVIEWS?|ARCHIVE)(?:$|[^A-Z0-9])/i', $folder['name'])) continue;
                $path = $root['name'].' / '.$folder['name'];
                $results[] = ['kind'=>'series_folder','id'=>$folder['id'],'name'=>$folder['name'],'path'=>$path];
                $visited=[];
                $this->collectArtwork($folder['id'],$folder['id'],$path,$results,$visited);
            }
        }
        return $results;
    }

    private function collectArtwork(string $parent,string $series,string $path,array &$results,array &$visited):void
    {
        if(isset($visited[$parent]))return;
        $visited[$parent]=true;
        foreach($this->listChildren($parent) as $file){
            if($file['mimeType']==='application/vnd.google-apps.shortcut')continue;
            if(preg_match('/(?:^|[^A-Z0-9])(MOCKUPS?|PREVIEWS?|ARCHIVES?)(?:$|[^A-Z0-9])/i',$file['name']))continue;
            $filePath=$path.' / '.$file['name'];
            if($file['mimeType']==='application/vnd.google-apps.folder'){
                $this->collectArtwork($file['id'],$series,$filePath,$results,$visited);
                continue;
            }
            if(!in_array(strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)),['jpg','jpeg','png','tif','tiff','webp','psd','pdf'],true))continue;
            $results[]=[...$file,'kind'=>'artwork','parent_id'=>$parent,'series_folder_id'=>$series,'path'=>$filePath];
        }
    }
}
