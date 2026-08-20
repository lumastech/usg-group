<?php

namespace App\Exceptions;

/** Raised when a member who has left, been expelled or died is credited a contribution. */
class MemberNotActiveException extends DomainRuleException {}
