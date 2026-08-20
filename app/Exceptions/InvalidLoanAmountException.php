<?php

namespace App\Exceptions;

/** Raised when a principal falls outside the tenor table's range. */
class InvalidLoanAmountException extends DomainRuleException {}
