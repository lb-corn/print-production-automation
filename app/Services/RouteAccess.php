<?php
namespace App\Services;
class RouteAccess {
    public static function permission(string $name): ?string {
        $map=[
        'apparel.orders.index'=>'apparel.orders.view','apparel.orders.show'=>'apparel.orders.view','apparel.orders.tracking.update'=>'apparel.tracking.edit','apparel.orders.remark.update'=>'apparel.remarks.edit','apparel.orders.remark.processed.update'=>'apparel.remarks.approve','apparel.orders.sync'=>'apparel.sync.edit',
        'orders.sync'=>'bags.sync.edit','orders.sync-status'=>'bags.overview.view',
        'workspace.factory-status'=>'bags.batches.view','workspace.status'=>'bags.overview.view',
        'artwork.preview'=>'bags.artwork.view','backing.suggest'=>'bags.artwork.view','backing.swatch'=>'bags.artwork.view','production.return-review'=>'bags.artwork.approve',
        'production'=>'bags.artwork.view','production.sync-backlog'=>'bags.sync.edit','production.rematch'=>'bags.artwork.edit','production.bulk-verify'=>'bags.artwork.approve','verify'=>'bags.artwork.approve','queue'=>'bags.queue.view',
        'batch.create'=>'bags.batches.edit','batches'=>'bags.batches.view','batch.show'=>'bags.batches.view','batch.progress'=>'bags.batches.view','batch.dispatch'=>'bags.batches.approve','batch.return-item'=>'bags.batches.edit','batch.return-to-review'=>'bags.batches.edit','batch.edge-print'=>'bags.batches.approve','batch.printed'=>'bags.batches.approve',
        'custom.create'=>'bags.artwork.edit','custom.status'=>'bags.artwork.view','custom.cancel'=>'bags.artwork.edit','custom.confirm'=>'bags.artwork.approve',
        'admin.roles'=>'admin.roles.edit','admin.roles.store'=>'admin.roles.edit','admin.roles.update'=>'admin.roles.edit',
        'admin.users'=>'admin.users.view','admin.users.create'=>'admin.users.edit','admin.users.store'=>'admin.users.edit','admin.users.edit'=>'admin.users.edit','admin.users.update'=>'admin.users.edit','admin.users.status'=>'admin.users.edit','admin.users.delete'=>'admin.users.edit','admin.users.destroy'=>'admin.users.edit',
        'admin.reset'=>'admin.reset.edit','admin.reset.store'=>'admin.reset.edit','admin.reset.pc-cleanup'=>'admin.reset.edit','admin.data-sync'=>'admin.sync.edit','admin.filename-cleanup.process'=>'admin.filenames.edit',
        'admin.artwork-names'=>'admin.artwork.view','admin.artwork-names.scan'=>'admin.artwork.edit','admin.artwork-names.standardize'=>'admin.artwork.edit','admin.artwork-names.review'=>'admin.artwork.edit','admin.artwork-names.begin'=>'admin.artwork.edit','admin.artwork-names.step'=>'admin.artwork.edit',
        ];
        if(str_starts_with($name,'admin.factory-agent.')) return in_array($name,['admin.factory-agent.handoff-control','admin.factory-agent.processing'],true)?'admin.factory.edit':'admin.factory.view';
        return $map[$name] ?? null;
    }
    public static function section(string $section): ?string {
        return ['artwork'=>'admin.artwork.view','sync'=>'admin.sync.edit','filenames'=>'admin.filenames.edit','roles'=>'admin.roles.edit','users'=>'admin.users.view','agent'=>'admin.factory.view','reset'=>'admin.reset.edit'][$section] ?? null;
    }
}
