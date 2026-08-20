<?php

namespace App\Exceptions;

/** Raised when an office already has a serving holder, or a member already has one. */
class CommitteeSeatTakenException extends DomainRuleException {}
