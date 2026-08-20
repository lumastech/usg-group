<?php

namespace App\Exceptions;

/** Raised when a tally is recorded against a motion the group has already decided. */
class MotionAlreadyDecidedException extends DomainRuleException {}
