<?php

namespace App\Enums;

enum ProcedureAttachmentType: string
{
    case Consent = 'consent';
    case PreOp = 'pre_op';
    case Operation = 'operation';
    case PostOp = 'post_op';
    case Anaesthesia = 'anaesthesia';
    case Other = 'other';

    /**
     * Get the translated label for the attachment type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Consent => __('Consent'),
            self::PreOp => __('Pre-op'),
            self::Operation => __('Operation'),
            self::PostOp => __('Post-op'),
            self::Anaesthesia => __('Anaesthesia'),
            self::Other => __('Other'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
