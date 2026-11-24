<?php

declare(strict_types=1);

namespace App\Domain\Meeting\Enums;

enum MeetingType: string
{
    case VIDEO_KYC = 'video_kyc';
    case SUPPORT = 'support';
    case ADVISORY = 'advisory';
    case ONBOARDING = 'onboarding';
}
