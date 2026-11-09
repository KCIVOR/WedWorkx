<?php

namespace App\Enums;

enum SupplierStatus: string
{
    case PendingReview = 'pending_review';
    case Waitlisted = 'waitlisted';
    case Invited = 'invited';
    case Expired = 'expired';
    case DetailsSubmitted = 'details_submitted';
    case PaymentPending = 'payment_pending';
    case Paid = 'paid';
    case Building = 'building';
    case Delivered = 'delivered';
}
