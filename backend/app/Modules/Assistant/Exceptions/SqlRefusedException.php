<?php

namespace App\Modules\Assistant\Exceptions;

use RuntimeException;

/** SqlGuard's refusal. The message is shown to the user as written. */
class SqlRefusedException extends RuntimeException {}
