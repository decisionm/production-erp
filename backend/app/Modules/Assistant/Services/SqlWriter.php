<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Exceptions\AskErpException;

/** Turns a question plus table specs into one SQL draft. Faked in tests. */
interface SqlWriter
{
    /** @throws AskErpException */
    public function write(SqlRequest $request): SqlDraft;
}
