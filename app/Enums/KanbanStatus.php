<?php

namespace App\Enums;

enum KanbanStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';
    case AppliedInSystem = 'applied_in_system';

    /**
     * Get the translated label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Todo => __('To Do'),
            self::InProgress => __('In Progress'),
            self::Done => __('Done'),
            self::AppliedInSystem => __('Applied in System'),
        };
    }

    /**
     * Get all status values as a list.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    /**
     * Get the statuses in board order.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Todo,
            self::InProgress,
            self::Done,
            self::AppliedInSystem,
        ];
    }
}
