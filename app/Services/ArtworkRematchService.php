<?php

namespace App\Services;

use App\Models\ProductionItem;
use App\Models\VerificationDecision;
use Illuminate\Support\Facades\DB;

class ArtworkRematchService
{
    public function __construct(private GoogleDriveService $drive, private ArtworkMatcherService $matcher) {}

    /** Fetch before taking database locks; a failed Drive request leaves all selections untouched. */
    public function rematch(): array
    {
        self::ensureSchemaReady();
        $this->matcher->refreshReviewedSelections();
        $index = $this->drive->artworkIndex();
        $counts = ['matched'=>0,'missing'=>0,'ambiguous'=>0,'skipped'=>0];
        DB::transaction(function () use ($index, &$counts) {
            // Same lock order as batch creation, serializing selection changes against batching.
            $items = ProductionItem::orderBy('id')->lockForUpdate()->get();
            $processed = app(ProductionService::class)->batchIndex(true);
            foreach ($items as $item) {
                if (isset($processed[$item->line_item_id]) || $item->is_custom) { $counts['skipped']++; continue; }
                $match = $this->matcher->match($item->toArray(), $index);
                $this->apply($item, $match);
                $counts[match ($match['match_status']) { 'REVIEW'=>'matched', 'AMBIGUOUS'=>'ambiguous', default=>'missing' }]++;
            }
        });
        return $counts;
    }

    public static function schemaReady(): bool { return \Illuminate\Support\Facades\Schema::hasColumn('production_items','artwork_review'); }

    public static function ensureSchemaReady(): void
    {
        if (!self::schemaReady()) {
            throw new \App\Exceptions\ArtworkSchemaNotReady();
        }
    }

    /** Caller must hold the item lock (or be creating a new item). */
    public function apply(ProductionItem $item, array $match,bool $persistReview=true): void
    {
        $before = $this->identity($item->matches ?? []);
        $oldPolicy = $item->artwork_review['policy'] ?? null;
        $validMatch=ArtworkMatcherService::validSelection((object)$match);
        if(!$persistReview)unset($match['artwork_review']);
        $item->fill($match);
        if ($before !== $this->identity($item->matches ?? [])
            || ($persistReview && $oldPolicy !== ArtworkMatcherService::POLICY) || !$validMatch) {
            VerificationDecision::where('line_item_id', $item->line_item_id)->delete();
            $item->unsetRelation('decision');
        }
        $item->save();
    }

    private function identity(array $files): array
    {
        $keys = array_map(fn($file) => json_encode(array_intersect_key($file, array_flip([
            'id','name','parent_id','side','modifiedTime','md5Checksum','version',
        ]))), $files);
        sort($keys);
        return $keys;
    }
}
