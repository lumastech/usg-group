<?php

namespace App\Exceptions;

/** Raised when a member status change is not one the constitution allows. */
class InvalidStatusTransitionException extends DomainRuleException {}
