<?php
// Isolated metadata fixture. Production always reads the linked Lucky Bags tab.
app()->instance(\App\Services\MaterialCatalog::class,new class extends \App\Services\MaterialCatalog {
 public function rows(bool $fresh=false):array {return $this->parse(file_get_contents(__DIR__.'/fixtures/lucky-bags-materials.csv'));}
});
