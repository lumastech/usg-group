<?php

namespace App\Exceptions;

/** Raised when a motion is decided in a meeting that never reached quorum. */
class QuorumNotMetException extends DomainRuleException {}
