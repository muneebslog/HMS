<?php

namespace App\Enums;

enum StockMovementReason: string
{
    case Receive = 'receive';
    case Adjustment = 'adjustment';
    case ShiftIssue = 'shift_issue';
    case Replenish = 'replenish';
    case Delivery = 'delivery';
    case ConsumableUse = 'consumable_use';
    case Return = 'return';

    /**
     * Get the translated label for the stock movement reason.
     */
    public function label(): string
    {
        return match ($this) {
            self::Receive => __('Receive'),
            self::Adjustment => __('Adjustment'),
            self::ShiftIssue => __('Shift Issue'),
            self::Replenish => __('Replenish'),
            self::Delivery => __('Delivery'),
            self::ConsumableUse => __('Consumable Use'),
            self::Return => __('Return'),
        };
    }
}
