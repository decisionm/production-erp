<?php

namespace App\Exceptions;

/**
 * Marker for an expected business-rule violation (insufficient stock, an
 * invalid status transition, ...) as opposed to a bug. Any module's
 * exception implementing this renders as a plain 422 — see bootstrap/app.php.
 */
interface DomainException {}
