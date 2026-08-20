<?php

namespace App\Exceptions;

/** Raised when an outflow would take the Social Fund below zero. */
class InsufficientSocialFundException extends DomainRuleException {}
