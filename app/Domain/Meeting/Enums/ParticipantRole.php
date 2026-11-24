<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Enums;

enum ParticipantRole: string
{
    case HOST = 'host';
    case PARTICIPANT = 'participant';
    case STAFF = 'staff';
}
