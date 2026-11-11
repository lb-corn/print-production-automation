<?php
// Legacy workflow fixtures have no backing-color table; feature-specific tests enable it explicitly.
config(['services.google_drive.factory_root_id'=>null,'services.partners.vibe.enabled'=>false,'services.backing_colors.enabled'=>false,'services.materials.authenticated'=>false,'services.materials.sheet_tab'=>'Lucky Bags']);
// Isolated metadata fixture. Production always reads the linked Lucky Bags tab.
app()->instance(\App\Services\MaterialCatalog::class,new class extends \App\Services\MaterialCatalog {
 public function rows(bool $fresh=false):array {return $this->parse(file_get_contents(__DIR__.'/fixtures/lucky-bags-materials.csv'));}
});
