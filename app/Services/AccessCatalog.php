<?php
namespace App\Services;
class AccessCatalog
{
    public static function permissions(): array {
        return ['partners.lucky.view'=>'Lucky: access','partners.vibe.view'=>'Vibe: access','bags.overview.view'=>'Bag overview: view','bags.artwork.view'=>'Bag artwork: view','bags.artwork.edit'=>'Bag artwork: edit / upload','bags.artwork.approve'=>'Bag artwork: approve','bags.queue.view'=>'Bag print queue: view','bags.batches.view'=>'Bag batches: view','bags.batches.edit'=>'Bag batches: create / return','bags.batches.approve'=>'Bag batches: dispatch / confirm printing','bags.sync.edit'=>'Bag backlog: refresh','apparel.overview.view'=>'Apparel overview: view','apparel.orders.view'=>'Apparel orders: view','apparel.tracking.edit'=>'Apparel tracking: edit','apparel.remarks.edit'=>'Apparel remarks: edit','apparel.remarks.approve'=>'Apparel remarks: mark processed','apparel.sync.edit'=>'Apparel orders: sync','admin.artwork.view'=>'Artwork settings: view','admin.artwork.edit'=>'Artwork settings: edit','admin.sync.edit'=>'Data sources: sync','admin.filenames.edit'=>'Filenames: clean up','admin.factory.view'=>'Factory administration: view / downloads','admin.factory.edit'=>'Factory administration: change controls','admin.users.view'=>'Users: view','admin.users.edit'=>'Users: create / edit / deactivate / delete','admin.roles.edit'=>'Roles: manage permissions and assignments','admin.reset.edit'=>'Portal data: reset'];
    }
    public static function defaults(): array {
        $all=array_keys(self::permissions());
        return ['manager'=>['Manager',array_values(array_filter($all,fn($p)=>!str_starts_with($p,'admin.')))],'demo'=>['Demo',array_values(array_filter($all,fn($p)=>(str_starts_with($p,'bags.') || str_starts_with($p,'partners.')) && str_ends_with($p,'.view')))],'bag-production'=>['Bag Production',array_values(array_filter($all,fn($p)=>str_starts_with($p,'bags.') || str_starts_with($p,'partners.')))],'apparel-production'=>['Apparel Production',array_values(array_filter($all,fn($p)=>str_starts_with($p,'apparel.') || str_starts_with($p,'partners.')))]];
    }
}
