<?php

namespace App\Exceptions;

/** Raised when money is asked for against a declaration the committee has not approved. */
class DeclarationNotApprovedException extends DomainRuleException {}
