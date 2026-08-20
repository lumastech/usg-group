<?php

namespace App\Exceptions;

/**
 * Raised when a deposit would take a member over the lockdown month's savings cap.
 *
 * Extends the general savings-amount error because to a caller it is the same kind
 * of refusal — the amount is not allowed — while still being catchable on its own.
 */
class LockdownSavingsCapException extends InvalidSavingsAmountException {}
