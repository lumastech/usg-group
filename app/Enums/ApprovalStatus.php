<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case PartiallyApproved = 'partially_approved';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
