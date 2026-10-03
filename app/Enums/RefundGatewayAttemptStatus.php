<?php

namespace App\Enums;

enum RefundGatewayAttemptStatus: string
{
    case Submitted = 'submitted';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Ambiguous = 'ambiguous';
}
