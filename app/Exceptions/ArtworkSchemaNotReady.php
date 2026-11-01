<?php
namespace App\Exceptions;

class ArtworkSchemaNotReady extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Server database update required: apply the artwork_review migration before rematching and approval. Backlog sync remains available; this is separate from missing artwork on individual products.');
    }
}
