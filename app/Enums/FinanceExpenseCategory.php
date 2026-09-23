<?php

namespace App\Enums;

enum FinanceExpenseCategory: string
{
    case Salary = 'salary';
    case Other = 'other';

    /**
     * Get the translated label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::Salary => __('Salary'),
            self::Other => __('Other'),
        };
    }

    /**
     * Get all category values as a list.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $category) => $category->value, self::cases());
    }
}
