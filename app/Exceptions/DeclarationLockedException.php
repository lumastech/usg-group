<?php

namespace App\Exceptions;

/** Raised when a declaration that has already been locked or processed is edited. */
class DeclarationLockedException extends DomainRuleException {}
