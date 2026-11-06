<?php

namespace App\Enums;

enum ShopifyFulfillmentStatus: string
{
    case Unfulfilled = 'UNFULFILLED';
    case PartiallyFulfilled = 'PARTIALLY_FULFILLED';
    case InProgress = 'IN_PROGRESS';
    case OnHold = 'ON_HOLD';
    case Scheduled = 'SCHEDULED';
    case PendingFulfillment = 'PENDING_FULFILLMENT';
    case Open = 'OPEN';
    case Restocked = 'RESTOCKED';
    case Fulfilled = 'FULFILLED';

    /**
     * @return list<string>
     */
    public static function priority(): array
    {
        return [
            self::Unfulfilled->value,
            self::PartiallyFulfilled->value,
            self::InProgress->value,
            self::OnHold->value,
            self::Scheduled->value,
            self::PendingFulfillment->value,
            self::Open->value,
            self::Restocked->value,
            self::Fulfilled->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function editableStatuses(): array
    {
        return [
            self::Unfulfilled->value,
            self::PartiallyFulfilled->value,
            self::InProgress->value,
            self::OnHold->value,
            self::Scheduled->value,
            self::PendingFulfillment->value,
            self::Open->value,
            self::Restocked->value,
        ];
    }
}
