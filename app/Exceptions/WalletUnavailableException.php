<?php

namespace App\Exceptions;

/**
 * Raised when a wallet exists but may not take this movement.
 *
 * A frozen wallet is a committee hold and stops both sides. A closed wallet may still
 * be drained — a member who does not rejoin withdraws from the closed cycle's wallet —
 * but nothing new may be put into it.
 */
class WalletUnavailableException extends DomainRuleException {}
