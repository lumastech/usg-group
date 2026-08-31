<?php

namespace App\Exceptions;

/**
 * Raised when a debit would take a wallet below zero.
 *
 * On a member wallet this means they are trying to spend money they do not have. On
 * the group wallet it means the group is trying to pay out money it does not hold,
 * which is the failure the whole wallet design exists to make impossible.
 */
class InsufficientWalletBalanceException extends DomainRuleException {}
