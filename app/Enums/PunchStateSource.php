<?php

namespace App\Enums;

enum PunchStateSource: string
{
    case Device = 'device';
    case InferredFirst = 'inferred_first';
    case InferredLast = 'inferred_last';
    case Manual = 'manual';
}
