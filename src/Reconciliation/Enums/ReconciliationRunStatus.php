<?php

namespace Ahmed\LaravelPayment\Reconciliation\Enums;

enum ReconciliationRunStatus: string
{
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case COMPLETED_WITH_ERRORS = 'completed_with_errors';
    case FAILED = 'failed';
}
