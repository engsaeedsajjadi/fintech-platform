<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Video Meeting Provider
    |--------------------------------------------------------------------------
    |
    | `sandbox` is the default. It creates clearly-labelled fake rooms and
    | never pretends to be a real video call. `jitsi` points at a real
    | Jitsi Meet deployment. Jitsi has no server-side "create room" REST
    | endpoint: a room exists when the first participant opens its URL, so
    | the provider builds the join URL from the configured base URL and,
    | when an app id + JWT secret are configured, signs a JWT for the
    | documented Jitsi external-API / token-auth pattern. If
    | `jitsi.base_url` is empty the provider throws
    | MeetingProviderNotConfiguredException instead of inventing a room.
    |
    */

    'default_provider' => env('MEETING_PROVIDER', 'sandbox'),

    'jitsi' => [
        // e.g. https://meet.example.com — no trailing slash. Empty = not configured.
        'base_url' => env('JITSI_BASE_URL', ''),
        // Jitsi app id (iss) used in the JWT. Empty = unsigned (public) room URL.
        'app_id' => env('JITSI_APP_ID', ''),
        // HS256 secret for the Jitsi JWT (at least 32 characters — the
        // JWT library rejects shorter keys). Empty = no token is issued.
        'jwt_secret' => env('JITSI_JWT_SECRET', ''),
        // JWT lifetime in seconds.
        'jwt_ttl' => (int) env('JITSI_JWT_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling Rules
    |--------------------------------------------------------------------------
    */

    // Maximum meeting length in minutes.
    'max_duration_minutes' => (int) env('MEETING_MAX_DURATION_MINUTES', 120),

    // How far ahead a meeting may be booked, in days.
    'max_advance_days' => (int) env('MEETING_MAX_ADVANCE_DAYS', 90),

    // Host working hours (24h) used by the availability endpoint.
    'working_hours' => [
        'start' => env('MEETING_WORKING_HOURS_START', '09:00'),
        'end' => env('MEETING_WORKING_HOURS_END', '17:00'),
    ],

    // Slot length in minutes for availability.
    'slot_minutes' => (int) env('MEETING_SLOT_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Eligibility & Reminders
    |--------------------------------------------------------------------------
    |
    | Video-KYC meetings require the host's existing KYC status
    | (users.kyc_status, managed by the Compliance domain) to be
    | `approved`. This reads the status field only — it does not call a
    | KYC vendor and does not change any KYC state.
    */

    'require_kyc_for_video_kyc' => (bool) env('MEETING_REQUIRE_KYC_FOR_VIDEO_KYC', true),

    // Send a reminder this many minutes before the meeting starts.
    'reminder_minutes_before' => (int) env('MEETING_REMINDER_MINUTES_BEFORE', 60),
];
