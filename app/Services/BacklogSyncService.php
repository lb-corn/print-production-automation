<?php
namespace App\Services;

use App\Models\ProductionItem;
use App\Models\VerificationDecision;
use Illuminate\Support\Facades\DB;

class BacklogSyncService
{
    public function __construct(private ShopifyService $shopify, private GoogleDriveService $drive, private ArtworkMatcherService $matcher) {}

    public function sync(): array
    {
        $hasReview=ArtworkRematchService::schemaReady();
        $this->matcher->refreshReviewedSelections();
        $backlog = $this->shopify->getProductionBacklog();
        $index = $this->drive->artworkIndex();
        $added = $updated = $unchanged = $matched = $missing = $ambiguous = $skipped = 0;
        $rematcher = new ArtworkRematchService($this->drive, $this->matcher);
        DB::transaction(function () use ($backlog,$index,$rematcher,$hasReview,&$added,&$updated,&$unchanged,&$matched,&$missing,&$ambiguous,&$skipped) {
            foreach ($backlog['items'] as $row) {
                $item = ProductionItem::where('line_item_id', $row['line_item_id'])->lockForUpdate()->first();
                if (isset(app(ProductionService::class)->batchIndex(true)[$row['line_item_id']])) { $skipped++; continue; }
                $fields = array_intersect_key($row, array_flip(['order_number','product','variant','sku','quantity','shopify_image']));
                $match = $this->matcher->match($row, $index);
                if(!empty($row['shopify_variant_id']) && (($row['order_sku']??null)!==($row['sku']??null) || ($row['order_variant']??null)!==($row['variant']??null))) {
                    $match['artwork_review']['catalog_refresh']=['variant_id'=>$row['shopify_variant_id'],'order_sku'=>$row['order_sku']??null,'current_sku'=>$row['sku']??null,'order_variant'=>$row['order_variant']??null,'current_variant'=>$row['variant']??null];
                }
                if ($item) {
                    $contextChanged = $item->sku !== ($fields['sku'] ?? $item->sku) || $item->variant !== ($fields['variant'] ?? $item->variant) || $item->product !== ($fields['product'] ?? $item->product);
                    $item->fill($fields);
                    if ($contextChanged) VerificationDecision::where('line_item_id',$item->line_item_id)->delete();
                    $item->isDirty() || $item->matches !== $match['matches'] ? $updated++ : $unchanged++;
                    $rematcher->apply($item,$match,$hasReview);
                } else {
                    if(!$hasReview)unset($match['artwork_review']);
                    ProductionItem::create(['line_item_id'=>$row['line_item_id'],...$fields,...$match]);
                    $added++;
                }
                match ($match['match_status']) { 'REVIEW'=>$matched++, 'AMBIGUOUS'=>$ambiguous++, default=>$missing++ };
            }
        });
        return ['artwork_schema_ready'=>$hasReview,'shopify_items'=>$backlog['item_count'],'orders_examined'=>$backlog['order_count'],'added'=>$added,
            'updated'=>$updated,'unchanged'=>$unchanged,'matched'=>$matched,'missing'=>$missing,'ambiguous'=>$ambiguous,
            'processed_skipped'=>$skipped,'drive_files'=>count(array_filter($index,fn($f)=>($f['kind']??'')==='artwork'))];
    }
}
