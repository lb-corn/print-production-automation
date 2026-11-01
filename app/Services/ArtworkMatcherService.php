<?php

namespace App\Services;

class ArtworkMatcherService
{
    private const SERIES = [
        'SRF-ELT'=>['single',['SRF-ELT']], 'ELT-S'=>['single',['ELT-S']],
        'PSE'=>['single',['PSE','PSN-ELT']], 'SUP'=>['single',['SUP']],
        'LUC'=>['single',['LUC']], 'CLV'=>['single',['CLV']],
        'SRF'=>['double',['SRF']], 'CLT'=>['double',['CLT']],
        'SNP'=>['double',['SNP']], 'PSN'=>['double',['PSN']],
    ];
    // Folder/series-only variant aliases identify scope; filenames still need exact code tokens.
    private const FOLDERS = [
        'SRF-ELT'=>['SRF-ELT','SFE','SUREFIRE-ELITE'], 'ELT-S'=>['ELT-S','ELITE-S'],
        'PSE'=>['PSE','PSN-ELT','PRO-SNIPER-ELITE'], 'SUP'=>['SUP','SUPREME'],
        'LUC'=>['LUC','LUCIANO'], 'CLV'=>['CLV','CLOVER','CLOVERS'],
        'SRF'=>['SRF','SUREFIRE'], 'CLT'=>['CLT','CLOUT'],
        'SNP'=>['SNP','SNIPER','SNIPERS'], 'PSN'=>['PSN','PRO-SNIPER','PRO-SNIPERS'],
    ];
    private const COLORS = ['BLACK','WHITE','RED','BROWN','NAVY','BLUE','ROYAL','GREEN','GOLD','TEAL','AQUA','PINK','ORANGE','GRAY','GREY','SILVER','CHARCOAL','NATURAL'];
    public const POLICY = 'strict-v1';

    public function __construct(private ?GoogleDriveService $drive = null) {}

    public function seriesFromSku(?string $sku): ?string
    {
        // Anchor the series at the end of the SKU, before its numeric item identifier.
        foreach (array_keys(self::SERIES) as $series) {
            if (preg_match('/^\d{4}-.+-'.preg_quote($series, '/').'-\d+$/', strtoupper(trim((string)$sku)))) return $series;
        }
        return null;
    }

    public function match(array $row, ?array $index = null): array
    {
        $sku = strtoupper(trim((string)($row['sku'] ?? '')));
        $series = $this->seriesFromSku($sku);
        $type = $series ? self::SERIES[$series][0] : null;
        $design = null;
        if ($series && preg_match('/^\d{4}-(.+)-'.preg_quote($series, '/').'-\d+$/', $sku, $parts)) $design = $this->norm($parts[1]);
        $colors = array_values(array_intersect(self::COLORS, explode('-', $this->norm($row['variant'] ?? ''))));
        $color = count($colors) === 1 ? $colors[0] : null;
        $variantName = $this->norm($row['variant'] ?? '');
        $variantKnown = count($colors) === 1 || (count($colors) === 0 && in_array($variantName, ['', 'DEFAULT-TITLE', ...(self::FOLDERS[$series] ?? [])], true));
        $custom = stripos($row['product'] ?? '', 'custom') !== false;
        $review = ['policy'=>self::POLICY, 'expected_folder'=>$series ? 'Approved Group A / Group B / '.$series : 'Unknown series',
            'folder_url'=>null, 'suggested_filenames'=>[], 'issues'=>[], 'candidates'=>[], 'corrections'=>[], 'mismatches'=>[]];
        $result = ['series_code'=>$series,'design_type'=>$type,'design'=>$design,'color'=>$color,'is_custom'=>$custom,
            'match_status'=>$custom ? 'CUSTOM' : 'MISSING','confidence'=>null,'matches'=>[]];
        if (!$custom && $series && $design && $variantKnown) {
            foreach ($type === 'double' ? ['FRONT','BACK'] : [null] as $side) {
                $review['suggested_filenames'][] = implode('-', array_filter([$series,$design,$color,$side], fn($value)=>$value !== null)).'.jpg';
            }
        }
        if ($custom) return [...$result,'artwork_review'=>$review];
        if (!$series || !$design || !$variantKnown) {
            $review['issues'][] = 'Missing or ambiguous SKU series, design code or variant color. Operator correction required.';
            return [...$result,'artwork_review'=>$review];
        }
        $index ??= ($this->drive ?? app(GoogleDriveService::class))->artworkIndex();
        $folders = array_values(array_filter($index, fn($f) => ($f['kind'] ?? '') === 'series_folder'
            && in_array($this->norm($f['name']), self::FOLDERS[$series], true)));
        if (count($folders) !== 1) {
            $review['issues'][] = count($folders) ? 'Ambiguous series folder: multiple approved folders. No artwork selected.' : 'Series folder missing from the approved manufacturing branches.';
            if (count($folders)) $result['match_status'] = 'AMBIGUOUS';
            return [...$result,'artwork_review'=>$review];
        }
        $folder = $folders[0];
        $review['expected_folder'] = $folder['path'];
        $review['folder_url'] = 'https://drive.google.com/drive/folders/'.rawurlencode($folder['id']);
        $bySide = [];
        foreach ($index as $file) {
            if (($file['kind'] ?? '') !== 'artwork' || ($file['parent_id'] ?? null) !== $folder['id']) continue;
            $name = $this->norm(pathinfo($file['name'], PATHINFO_FILENAME));
            if (preg_match('/(?:^|-)(?:MOCKUPS?|PREVIEWS?|ARCHIVE)(?:-|$)/', $name)) continue;
            if (count($review['mismatches']) < 20) {
                $attributes = [];
                $seriesHit = false;
                foreach (self::SERIES[$series][1] as $alias) {
                    if (str_starts_with($name, $alias.'-')) { $seriesHit = true; break; }
                }
                if (!$seriesHit) $attributes[] = 'series';
                if (!preg_match('/(?:^|-)'.preg_quote($design, '/').'(?:-|$)/', $name)) $attributes[] = 'design';
                $fileColors = array_values(array_intersect(self::COLORS, explode('-', $name)));
                if ($fileColors !== ($color ? [$color] : [])) $attributes[] = 'color';
                if ($type === 'double' && !preg_match('/-(FRONT|BACK)$/', $name)) $attributes[] = 'print side';
                if ($attributes) $review['mismatches'][] = ['filename'=>$file['name'],'attributes'=>$attributes];
                if ($seriesHit && !in_array('design', $attributes, true) && $attributes) {
                    foreach ($review['suggested_filenames'] as $suggested) {
                        $review['corrections'][] = ['existing'=>$file['name'], 'suggested'=>pathinfo($suggested, PATHINFO_FILENAME).'.'.pathinfo($file['name'], PATHINFO_EXTENSION)];
                    }
                }
            }
            // Full filename grammar: no extra design, color, series or side tokens are tolerated.
            foreach (self::SERIES[$series][1] as $alias) {
                $base = preg_quote(implode('-', array_filter([$alias,$design,$color], fn($value)=>$value !== null)), '/');
                $pattern = $type === 'double' ? '/^'.$base.'-(FRONT|BACK)$/' : '/^'.$base.'(?:-(SINGLE|FRONT))?$/';
                if (!preg_match($pattern, $name, $parts)) continue;
                $side = $type === 'double' ? strtolower($parts[1]) : 'single';
                $file['side'] = $side;
                $bySide[$side][] = $file;
                break;
            }
        }
        $ambiguous = false; $selected = [];
        foreach ($type === 'double' ? ['front','back'] : ['single'] as $side) {
            $files = $bySide[$side] ?? [];
            if (count($files) > 1) $ambiguous = true;
            if (count($files) !== 1) $review['issues'][] = count($files) ? 'Multiple plausible '.$side.' files. Operator review required.' : 'Missing '.$side.' artwork with exact series, design and color tokens.';
            $review['candidates'] = [...$review['candidates'], ...array_map(fn($f)=>['id'=>$f['id'],'name'=>$f['name'],'side'=>$side], $files)];
            if (count($files) === 1) $selected[] = $files[0];
        }
        if (!$review['issues']) { $result['matches'] = $selected; $result['match_status'] = 'REVIEW'; }
        elseif ($ambiguous) $result['match_status'] = 'AMBIGUOUS';
        return [...$result,'artwork_review'=>$review];
    }

    /** Display-only legacy preview. It never confers eligibility or proves current Drive existence. */
    public function savedPreviews(array $row, array $files): array
    {
        $series = $this->seriesFromSku($row['sku'] ?? null);
        if (!$series) return [];
        $index = []; $folders = [];
        foreach ($files as $file) {
            $parts = array_map('trim', explode('/', $file['path'] ?? ''));
            if (count($parts) !== 3 || !in_array($parts[0], config('services.google_drive.allowed_root_folders', []), true)
                || !in_array($this->norm($parts[1]), self::FOLDERS[$series], true)
                || $parts[2] !== ($file['name'] ?? '') || empty($file['id'])) continue;
            $folderId = $parts[0].'/'.$parts[1];
            $folders[$folderId] = ['kind'=>'series_folder','id'=>$folderId,'name'=>$parts[1],'path'=>$folderId];
            if (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['png','jpg','jpeg','tif','tiff','webp','psd','pdf'], true)) continue;
            $index[] = [...$file,'kind'=>'artwork','parent_id'=>$folderId];
        }
        return $this->match($row, [...array_values($folders),...$index])['matches'];
    }

    public static function validSelection($item): bool
    {
        if (($item->artwork_review['policy'] ?? null) !== self::POLICY || $item->match_status !== 'REVIEW' || $item->is_custom || !in_array($item->design_type, ['single','double'], true)) return false;
        $sides = array_column($item->matches ?? [], 'side'); sort($sides);
        return $sides === ($item->design_type === 'double' ? ['back','front'] : ['single'])
            && count(array_filter(array_column($item->matches ?? [], 'id'))) === count($sides);
    }

    private function norm(string $value): string
    {
        return trim(preg_replace('/[^A-Z0-9]+/', '-', strtoupper($value)), '-');
    }
}
