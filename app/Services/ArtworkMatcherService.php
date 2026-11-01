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
    private const FOLDERS = [
        'SRF-ELT'=>['SRF-ELT','SFE','SUREFIRE-ELITE'], 'ELT-S'=>['ELT-S','ELITE-S'],
        'PSE'=>['PSE','PSN-ELT','PRO-SNIPER-ELITE'], 'SUP'=>['SUP','SUPREME'],
        'LUC'=>['LUC','LUCIANO'], 'CLV'=>['CLV','CLOVER','CLOVERS'],
        'SRF'=>['SRF','SUREFIRE'], 'CLT'=>['CLT','CELTICS','CLOUT'],
        'SNP'=>['SNP','SNIPER','SNIPERS'], 'PSN'=>['PSN','PRO-SNIPER','PRO-SNIPERS'],
    ];
    private const COLORS = ['BLACK','WHITE','RED','BROWN','NAVY','BLUE','ROYAL','GREEN','GOLD','TEAL','AQUA','PINK','ORANGE','GRAY','GREY','SILVER','CHARCOAL','NATURAL','PURPLE','SLOTH'];
    private const COMPOUND_COLORS = ['BLACK-LEATHER','BBY-BLUE','BABY-BLUE','ROYAL-BLUE','NAVY-BLUE'];
    public const POLICY = 'strict-v2';

    public function __construct(private ?GoogleDriveService $drive = null) {}

    public static function seriesForFolder(string $name): ?string
    {
        $normalized=trim(preg_replace('/[^A-Z0-9]+/','-',strtoupper($name)),'-');
        foreach(self::FOLDERS as $series=>$aliases)if(in_array($normalized,$aliases,true))return $series;
        return null;
    }

    public function seriesFromSku(?string $sku): ?string { return $this->skuParts($sku)['series'] ?? null; }

    private function skuParts(?string $sku): ?array
    {
        $sku=strtoupper(trim((string)$sku));
        foreach(self::SERIES as $series=>$definition)foreach($definition[1] as $alias) {
            if(preg_match('/^(\d{4})-(.+)-'.preg_quote($alias,'/').'-\d+$/',$sku,$parts))return ['year'=>$parts[1],'design'=>$this->norm($parts[2]),'series'=>$series];
        }
        return null;
    }

    public function match(array $row, ?array $index = null): array
    {
        $sku = strtoupper(trim((string)($row['sku'] ?? '')));
        $series = $this->seriesFromSku($sku);
        $type = $series ? self::SERIES[$series][0] : null;
        $design = null; $year = null;
        $parts=$this->skuParts($sku);
        if($parts){$year=$parts['year'];$design=$parts['design'];}
        [$variantKnown, $color] = $this->variant($row['variant'] ?? '', $series);
        $custom = stripos($row['product'] ?? '', 'custom') !== false;
        $review = ['policy'=>self::POLICY, 'expected_folder'=>$series ? 'Approved Group A / Group B / '.$series : 'Unknown series',
            'folder_url'=>null, 'suggested_filenames'=>[], 'issues'=>[], 'candidates'=>[], 'corrections'=>[], 'mismatches'=>[]];
        $result = ['series_code'=>$series,'design_type'=>$type,'design'=>$design,'color'=>$color,'is_custom'=>$custom,
            'match_status'=>$custom ? 'CUSTOM' : 'MISSING','confidence'=>null,'matches'=>[]];
        if (!$custom && $series && $design && $variantKnown) {
            foreach ($type === 'double' ? ['FRONT','BACK'] : [null] as $side) {
                $review['suggested_filenames'][] = $this->productionFilename($series,$design,$color,$side ?? 'SINGLE','jpg');
            }
        }
        if ($custom) return [...$result,'artwork_review'=>$review];
        if (!$series || !$design || !$variantKnown) {
            $review['issues'][] = 'SKU or variant needs review; no reliable filename can be inferred.';
            return [...$result,'artwork_review'=>$review];
        }
        $index ??= ($this->drive ?? app(GoogleDriveService::class))->artworkIndex();
        $folders = array_values(array_filter($index, fn($f) => ($f['kind'] ?? '') === 'series_folder'
            && in_array($this->norm($f['name']), self::FOLDERS[$series], true)));
        if (count($folders) !== 1) {
            $review['issues'][] = count($folders) ? 'Multiple series folders found. Resolve duplicates before matching.' : 'Series folder not found in the approved manufacturing branches.';
            if (count($folders)) $result['match_status'] = 'AMBIGUOUS';
            return [...$result,'artwork_review'=>$review];
        }
        $folder = $folders[0];
        $review['expected_folder'] = $folder['path'];
        $review['folder_url'] = 'https://drive.google.com/drive/folders/'.rawurlencode($folder['id']);
        $designs = $this->designs($row, $design, $year);
        $bySide = []; $seen = [];
        foreach ($index as $file) {
            if (($file['kind'] ?? '') !== 'artwork' || ($file['parent_id'] ?? null) !== $folder['id'] || empty($file['id']) || isset($seen[$file['id']])) continue;
            if (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['jpg','jpeg','png','tif','tiff','webp','psd','pdf'], true)) continue;
            $name = $this->norm(pathinfo($file['name'], PATHINFO_FILENAME));
            if (preg_match('/(?:^|-)(?:MOCKUPS?|PREVIEWS?|ARCHIVE)(?:-|$)/', $name)) continue;
            $name = $this->namingStem($name,$file['id']);
            if($name===null)continue;
            $parsed = $this->parse($name, $series, $type, $designs, $year, $color);
            if (!$parsed) continue; // Unrelated files are not errors for this order.
            if ($color !== null && $parsed['color'] !== $color) {
                if (count($review['mismatches']) < 10) {
                    $review['mismatches'][] = ['filename'=>$file['name'],'attributes'=>['color / variant']];
                    // A rename cannot fix image content. Show the convention only, preserving the extension.
                    $review['corrections'][] = ['existing'=>$file['name'], 'suggested'=>$this->productionFilename($series,$design,$color,$parsed['side'],pathinfo($file['name'],PATHINFO_EXTENSION),$file['id'])];
                }
                continue;
            }
            $seen[$file['id']] = true;
            $file['side'] = $parsed['side'];
            $file['filename_color'] = $parsed['color'];
            $bySide[$parsed['side']][] = $file;
        }
        $ambiguous = false; $selected = [];
        foreach ($type === 'double' ? ['front','back'] : ['single'] as $side) {
            $files = $bySide[$side] ?? [];
            if (count($files) > 1) $ambiguous = true;
            if (count($files) !== 1) $review['issues'][] = count($files) ? 'Multiple matching '.$side.' files. Operator review required.' : 'No eligible '.$side.' artwork found for this design and variant.';
            $review['candidates'] = [...$review['candidates'], ...array_map(fn($f)=>['id'=>$f['id'],'name'=>$f['name'],'side'=>$side], $files)];
            if (count($files) === 1) $selected[] = $files[0];
        }
        // Without a Shopify color, front/back must still describe the same color variant.
        if ($type === 'double' && count($selected) === 2 && $selected[0]['filename_color'] !== $selected[1]['filename_color']) {
            $ambiguous = true;
            $review['issues'][] = 'Front and back colors differ; review the artwork pair.';
        }
        if (!$review['issues']) { $result['matches'] = $selected; $result['match_status'] = 'REVIEW'; }
        elseif ($ambiguous) $result['match_status'] = 'AMBIGUOUS';
        return [...$result,'artwork_review'=>$review];
    }

    private function variant(string $variant, ?string $series): array
    {
        $name = $this->norm($variant);
        if (in_array($name, ['', 'DEFAULT-TITLE', ...(self::FOLDERS[$series] ?? [])], true)) return [true, null];
        // Color is the whole option, not any substring inside a longer variant.
        if (str_contains($variant, '/')) {
            $parts = array_map('trim', explode('/', $variant));
            if (count($parts) !== 2 || !in_array($this->norm($parts[0]), self::FOLDERS[$series] ?? [], true)) return [false, null];
            $name = $this->norm($parts[1]);
        }
        $valid=$name!=='' && strlen($name)<=40 && !preg_match('/(?:^|-)(CUSTOM|FRONT|BACK|SINGLE)(?:-|$)/',$name);
        return [$valid,$valid?$name:null];
    }

    private function isColor(string $value): bool
    {
        return in_array($value, [...self::COLORS, ...self::COMPOUND_COLORS], true);
    }

    private function designs(array $row, string $code, string $year): array
    {
        // Exact full product title is independent evidence; never expand a code by substring.
        $title = $this->designIdentity($row['product'] ?? '');
        if (in_array($title, array_merge(...array_values(self::FOLDERS)), true)) $title = '';
        if ($title !== '' && preg_match('/^(.*)-ACL-CORNHOLE-BAGS$/', $title, $parts)) $title = $parts[1];
        return array_values(array_unique(array_filter([$code, $title], fn($value)=>$value !== '')));
    }

    private function parse(string $name, string $series, string $type, array $designs, string $year,?string $expectedVariant=null): ?array
    {
        if (preg_match('/^(\d{4})-(.+)$/', $name, $parts)) {
            $name = $parts[2];
        }
        $side = 'single';
        if (preg_match('/-(FRONT|BACK|SINGLE)$/', $name, $parts)) {
            $side = strtolower($parts[1]);
            $name = substr($name, 0, -strlen($parts[0]));
        } elseif ($type === 'double') return null;
        if ($type === 'single' && $side === 'back') return null;
        if ($type === 'double' && !in_array($side, ['front','back'], true)) return null;
        foreach (self::SERIES[$series][1] as $alias) {
            if (!str_starts_with($name, $alias.'-')) continue;
            $body = substr($name, strlen($alias) + 1);
            foreach ($designs as $design) {
                if ($body === $design) return ['side'=>$type === 'single' ? 'single' : $side,'color'=>null];
                if (!str_starts_with($body, $design.'-')) continue;
                $color = substr($body, strlen($design) + 1);
                if ($this->isColor($color) || $color===$expectedVariant) return ['side'=>$type === 'single' ? 'single' : $side,'color'=>$color];
            }
        }
        return null;
    }

    private function productionFilename(string $series,string $design,?string $color,string $side,string $extension,?string $fileId=null): string
    {
        $stem=implode('-',array_filter(['LB',$series,$design,$color,strtoupper($side)],fn($value)=>$value!==null));
        if($fileId!==null)$stem.='-ID-'.strtoupper(substr(hash('sha256',$fileId),0,12));
        return $stem.'.'.$extension;
    }

    /** Saved previews never confer approval or prove current Drive existence. */
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
            $index[] = [...$file,'kind'=>'artwork','parent_id'=>$folderId];
        }
        return $this->match($row, [...array_values($folders),...$index])['matches'];
    }


    /** A rename proposal only: exact catalog/filename evidence, or explicit admin mapping. */
    public function namingCandidate(array $row, array $file, string $folderName, ?array $mapping = null): ?array
    {
        $series=$this->seriesFromSku($row['sku'] ?? null);
        if (!$series || !in_array($this->norm($folderName),self::FOLDERS[$series],true) || stripos($row['product'] ?? '', 'custom') !== false) return null;
        $parts=$this->skuParts($row['sku']);
        if(!$parts)return null;
        $sku=[null,$parts['year'],$parts['design']];
        [$known,$variantColor]=$this->variant($row['variant'] ?? '',$series);
        if (!$known) return null;
        $extension=pathinfo($file['name'],PATHINFO_EXTENSION);
        if (!in_array(strtolower($extension),['jpg','jpeg','png','tif','tiff','webp','psd','pdf'],true)) return null;
        $name=$this->norm(pathinfo($file['name'],PATHINFO_FILENAME));
        if (preg_match('/(?:^|-)(MOCKUPS?|PREVIEWS?|ARCHIVE)(?:-|$)/',$name)) return null;
        $type=self::SERIES[$series][0];
        $name=$this->namingStem($name,$file['id']);
        if($name===null && $mapping===null)return null;
        if ($mapping !== null) {
            $side=$mapping['side'] ?? '';
            if (!in_array($side,$type==='double'?['front','back']:['single'],true)) return null;
            $color=$this->norm($mapping['color'] ?? '');
            $color=$color===''?null:$color;
            if (($color!==null && !$this->isColor($color) && $color!==$variantColor) || ($variantColor!==null && $color!==$variantColor)) return null;
        } else {
            $designs=$this->designs($row,$this->norm($sku[2]),$sku[1]);
            $parsed=$this->parse($name,$series,$type,$designs,$sku[1],$variantColor);
            if (!$parsed) {
                // A bare exact design may use its approved folder as series context when preparing a reviewed rename.
                // An explicit different series is never silently overwritten.
                foreach (self::SERIES as $definition) foreach ($definition[1] as $alias) if (str_starts_with($name,$alias.'-')) return null;
                $bare=$name;
                if (preg_match('/^(\d{4})-(.+)$/',$bare,$parts)) $bare=$parts[2];
                $parsed=$this->parse($series.'-'.$bare,$series,$type,$designs,$sku[1],$variantColor);
            }
            if (!$parsed || ($variantColor!==null && $parsed['color']!==$variantColor)) return null;
            $side=$parsed['side']; $color=$parsed['color'];
        }
        $target=$this->productionFilename($series,$this->norm($sku[2]),$color,$side,$extension,$file['id']);
        if(strlen($target)>255)return null;
        return ['catalog_key'=>$row['catalog_key'],'sku'=>$row['sku'],'product'=>$row['product'],'variant'=>$row['variant'],
            'series'=>$series,'type'=>$type,'side'=>$side,'color'=>$color,'target'=>$target,'design_identity'=>$this->designIdentity($row['product'] ?? '')];
    }

    public function catalogColor(array $row): ?string { return $this->variant($row['variant']??'',$this->seriesFromSku($row['sku']??null))[1]; }

    public function designIdentity(string $title): string
    {
        $title=$this->norm($title);
        $title=preg_replace('/^LUCKY-BAGS-(?:\d{4}-)?/','',$title);
        return preg_replace('/-ACL-CORNHOLE-BAGS$/','',$title);
    }

    /** Descriptive grouping only, never proof of catalog identity or printable artwork. */
    public function sourceAttributes(array $file,string $folderName,array $catalogColors=[]): ?array
    {
        $series=self::seriesForFolder($folderName);if(!$series)return null;
        $name=$this->norm(pathinfo($file['name'],PATHINFO_FILENAME));
        if(preg_match('/(?:^|-)(MOCKUPS?|PREVIEWS?|ARCHIVE)(?:-|$)/',$name))return null;
        $name=$this->namingStem($name,$file['id']);if($name===null)return null;
        $name=preg_replace('/^\d{4}-/','',$name);
        $aliases=[];foreach(self::SERIES as $code=>$definition)foreach($definition[1] as $alias)$aliases[$alias]=$code;
        uksort($aliases,fn($a,$b)=>strlen($b)<=>strlen($a));
        foreach($aliases as $alias=>$code)if(str_starts_with($name,$alias.'-')){
            if($code!==$series)return null;$name=substr($name,strlen($alias)+1);break;
        }
        $side=self::SERIES[$series][0]==='single'?'single':null;
        if(preg_match('/-(FRONT|BACK|SINGLE)$/',$name,$parts)){$side=strtolower($parts[1]);$name=substr($name,0,-strlen($parts[0]));}
        $color=null;$colors=array_values(array_unique([...self::COLORS,...self::COMPOUND_COLORS,...$catalogColors]));usort($colors,fn($a,$b)=>strlen($b)<=>strlen($a));
        foreach($colors as $candidate)if(str_ends_with($name,'-'.$candidate)){$color=$candidate;$name=substr($name,0,-strlen($candidate)-1);break;}
        $name=preg_replace('/-\d{4}$/','',$name);
        if($name==='' || ctype_digit(str_replace('-','',$name)) || preg_match('/^(IMG|IMAGE|FILE|ARTWORK|DESIGN|UNTITLED|COPY)(?:-\d+)?$/',$name))return null;
        return ['design'=>$name,'side'=>$side,'color'=>$color,'series'=>$series];
    }

    private function namingStem(string $name,string $id): ?string
    {
        if(!str_starts_with($name,'LB-'))return $name;
        $body=substr($name,3);
        if(preg_match('/^(.+)-ID-([A-F0-9]{12})$/',$body,$parts))return hash_equals(strtoupper(substr(hash('sha256',$id),0,12)),$parts[2])?$parts[1]:null;
        return $body;
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
