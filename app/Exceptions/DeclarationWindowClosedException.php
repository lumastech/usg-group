<?php

namespace App\Exceptions;

/**
 * Raised when a member tries to declare outside the 1st-to-3rd window.
 *
 * A treasurer may still capture a declaration after the window has closed — it is
 * stamped late rather than refused — so this is only ever raised for a member acting
 * on their own behalf, or for anyone at all before the window has opened.
 */
class DeclarationWindowClosedException extends DomainRuleException {}
