<?php
namespace App\Exceptions;

class ArtworkSchemaNotReady extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Artwork setup is incomplete: apply the artwork_review migration before rematching. No orders or batches were changed.');
    }
}
