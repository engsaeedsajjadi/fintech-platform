<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Enums;

enum ParticipantStatus: string
{
    case INVITED = 'invited';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
}
