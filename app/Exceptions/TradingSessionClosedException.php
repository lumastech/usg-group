<?php

namespace App\Exceptions;

/** Raised when a concluded trading session is written to a second time. */
class TradingSessionClosedException extends DomainRuleException {}
