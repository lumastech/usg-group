<?php

namespace App\Exceptions;

use RuntimeException;

/** Raised when an amount cannot be read, or carries more precision than a ngwee. */
class PaymentAmountException extends RuntimeException {}
