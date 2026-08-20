<?php

namespace App\Exceptions;

/**
 * Raised on any attempt to edit or delete a posted ledger entry.
 *
 * The savings ledger is append-only: a mistake is corrected with a reversing
 * Adjustment, never by rewriting history, so the audit trail always shows what was
 * recorded at the time as well as what it was later corrected to.
 */
class ImmutableLedgerException extends DomainRuleException {}
