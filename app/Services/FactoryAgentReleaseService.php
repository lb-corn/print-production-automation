<?php
namespace App\Services;

class FactoryAgentReleaseService
{
    public function releases(): array
    {
        $index=resource_path('factory-agent-releases/index.json');
        if(!is_file($index))return [];
        $rows=json_decode(file_get_contents($index),true);
        if(!is_array($rows)||count($rows)>5)return [];
        $seen=[];
        foreach($rows as $row){
            $version=$row['version']??'';
            if(!preg_match('/^\d+\.\d+\.\d+$/',$version)||isset($seen[$version])||($row['file']??'')!=="LuckyBags-Factory-Agent-v$version.zip"||!preg_match('/^[a-f0-9]{64}$/',$row['sha256']??''))return [];
            $seen[$version]=true;
        }
        usort($rows,fn($a,$b)=>version_compare($b['version'],$a['version']));
        return $rows;
    }

    public function package(string $version): string
    {
        $release=collect($this->releases())->firstWhere('version',$version);
        abort_unless($release,404,'This agent release is not retained.');
        $root=realpath(resource_path('factory-agent-releases'));
        $path=realpath(resource_path('factory-agent-releases/'.$release['file']));
        abort_unless($root&&$path&&dirname($path)===$root&&is_file($path)&&hash_equals($release['sha256'],hash_file('sha256',$path)),503,'Release package verification failed.');
        return $path;
    }
}
