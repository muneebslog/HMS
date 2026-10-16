<?php

namespace App\Models;

use Database\Factories\AttendanceDutyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDuty extends Model
{
    /** @use HasFactory<AttendanceDutyFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'duty_session_id',
        'shift_template_id',
        'scheduled_start',
        'scheduled_end',
        'late_minutes',
        'early_leave_minutes',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_start' => 'immutable_datetime',
            'scheduled_end' => 'immutable_datetime',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Shift name with timings, or "Custom" for times that match no template.
     */
    public function label(): string
    {
        return $this->shiftTemplate?->label()
            ?? __('Custom').' '.$this->scheduled_start->format('H:i').'–'.$this->scheduled_end->format('H:i');
    }

    /**
     * @return BelongsTo<DutySession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(DutySession::class, 'duty_session_id');
    }

    /**
     * @return BelongsTo<ShiftTemplate, $this>
     */
    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class);
    }
}
