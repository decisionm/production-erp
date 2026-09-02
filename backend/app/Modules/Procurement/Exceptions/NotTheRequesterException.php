<?php

namespace App\Modules\Procurement\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class NotTheRequesterException extends RuntimeException implements DomainException {}
