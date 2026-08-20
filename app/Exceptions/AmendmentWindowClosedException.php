<?php

namespace App\Exceptions;

/** Raised when the constitution is amended less than six months after the last change. */
class AmendmentWindowClosedException extends DomainRuleException {}
