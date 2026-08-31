<?php

namespace App\Modules\TallySync\Exceptions;

use RuntimeException;

/**
 * The bytes handed to the importer are not a Tally XML document we can read.
 *
 * Distinct from "read it, matched nothing": an unreadable FILE is the caller's
 * mistake and is reported as one, while an unmatched VOUCHER is a normal
 * outcome that gets recorded.
 */
class TallyXmlUnreadable extends RuntimeException {}
