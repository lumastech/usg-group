<?php

namespace App\Exceptions;

/** Raised when a social fund contribution is not the exact once-per-cycle amount. */
class InvalidSocialFundContributionException extends DomainRuleException {}
