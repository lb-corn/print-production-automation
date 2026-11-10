<?php
namespace App\Services;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Sheets;
use RuntimeException;

/** Authenticated metadata only; does not require making the workbook public. */
class ConfigurationSheetReader
{
    public function read(string $sheetId, string $tab): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,100}$/',$sheetId) || trim($tab)==='' || strlen($tab)>100 || preg_match('/[\x00-\x1F\x7F]/',$tab)) throw new RuntimeException('Sheet configuration missing');
        $client=new Client();
        $client->setHttpClient(new \GuzzleHttp\Client(['timeout'=>20,'connect_timeout'=>5]));
        $client->setAuthConfig(config('services.google_drive.service_account_file'));
        $client->setScopes([Drive::DRIVE_READONLY]);
        $sheets=new Sheets($client);
        $range="'".str_replace("'","''",$tab)."'!A1:Z1000";
        return $sheets->spreadsheets_values->get($sheetId,$range,['valueRenderOption'=>'FORMATTED_VALUE'])->getValues()??[];
    }
}
