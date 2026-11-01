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
            (string) config(
                'services.google_drive.artwork_root_id'
            )
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

        if ($this->rootId === '') {
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
                    'webViewLink,imageMediaMetadata,modifiedTime,md5Checksum' .
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
            $this->listChildren($this->rootId)
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

    /** Only direct files in direct series folders are eligible. Never descend further. */
    public function artworkIndex(): array
    {
        $results = [];
        foreach ($this->manufacturingRoots() as $root) {
            foreach ($this->listChildren($root['id']) as $folder) {
                if ($folder['mimeType'] !== 'application/vnd.google-apps.folder') continue;
                if (preg_match('/(?:^|[^A-Z0-9])(MOCKUPS?|PREVIEWS?|ARCHIVE)(?:$|[^A-Z0-9])/i', $folder['name'])) continue;
                $path = $root['name'].' / '.$folder['name'];
                $results[] = ['kind'=>'series_folder','id'=>$folder['id'],'name'=>$folder['name'],'path'=>$path];
                foreach ($this->listChildren($folder['id']) as $file) {
                    if ($file['mimeType'] === 'application/vnd.google-apps.folder' || $file['mimeType'] === 'application/vnd.google-apps.shortcut') continue;
                    if (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['jpg','jpeg','png','tif','tiff','webp','psd','pdf'], true)) continue;
                    if (preg_match('/(?:^|[^A-Z0-9])(MOCKUPS?|PREVIEWS?|ARCHIVE)(?:$|[^A-Z0-9])/i', $file['name'])) continue;
                    $results[] = [...$file,'kind'=>'artwork','parent_id'=>$folder['id'],'path'=>$path.' / '.$file['name']];
                }
            }
        }
        return $results;
    }
}
