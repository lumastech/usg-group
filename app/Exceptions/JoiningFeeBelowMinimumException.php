<?php

namespace App\Exceptions;

/** Raised when a member is registered paying less than their tier's joining fee. */
class JoiningFeeBelowMinimumException extends DomainRuleException {}
