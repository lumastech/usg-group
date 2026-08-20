<?php

namespace App\Exceptions;

/** Raised when a resignation takes effect before the month's notice has run. */
class NoticePeriodNotServedException extends DomainRuleException {}
