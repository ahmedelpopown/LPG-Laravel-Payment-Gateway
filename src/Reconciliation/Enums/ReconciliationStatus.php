<?php

namespace Ahmed\LaravelPayment\Reconciliation\Enums;

enum ReconciliationStatus: string
{
    case MATCHED = 'matched';
    case STATUS_MISMATCH = 'status_mismatch';
    case AMOUNT_MISMATCH = 'amount_mismatch';
    case CURRENCY_MISMATCH = 'currency_mismatch';
    case REFUND_MISMATCH = 'refund_mismatch';
    case PROVIDER_NOT_FOUND = 'provider_not_found';
    case LOCAL_NOT_FOUND = 'local_not_found';
    case DUPLICATE_REFERENCE = 'duplicate_reference';
    case MANUAL_REVIEW = 'manual_review';
    case PROVIDER_ERROR = 'provider_error';
}
