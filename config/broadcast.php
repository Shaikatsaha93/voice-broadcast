<?php

return [
    // Seconds before an un-refreshed DID slot is considered stale and recoverable.
    'slot_ttl' => (int) env('BROADCAST_SLOT_TTL', 180),

    // false => one user per DID (enforced). true => a DID may be shared by several users.
    'shared_did_assignment' => (bool) env('DID_SHARED_ASSIGNMENT', false),

    'retry_delay_seconds' => (int) env('BROADCAST_RETRY_DELAY', 300),
    'max_attempts_limit' => 10,
    'max_audio_kb' => (int) env('BROADCAST_MAX_AUDIO_KB', 10240),
    'max_import_kb' => (int) env('BROADCAST_MAX_IMPORT_KB', 51200),
    'import_chunk' => 1000,
    // Shown in front of every balance / rate / cost.
    'currency' => env('BROADCAST_CURRENCY', '৳'),
    'default_country_prefix' => env('BROADCAST_COUNTRY_PREFIX', '880'),
    'originate_timeout_ms' => 45000,

    'asterisk' => [
        'host' => env('ASTERISK_HOST'),
        'ami_port' => (int) env('ASTERISK_AMI_PORT', 5038),
        'ami_username' => env('ASTERISK_AMI_USERNAME'),
        'ami_password' => env('ASTERISK_AMI_PASSWORD'),
        'ari_url' => env('ASTERISK_ARI_URL'),
        'ari_username' => env('ASTERISK_ARI_USERNAME'),
        'ari_password' => env('ASTERISK_ARI_PASSWORD'),
        'ari_app' => env('ASTERISK_ARI_APP', 'broadcast'),
        'trunk' => env('ASTERISK_TRUNK', 'PJSIP/{number}@trunk'),
        'context' => env('ASTERISK_CONTEXT', 'broadcast'),
        // Folder owned by the asterisk user where normalized audio is copied so Asterisk can play it.
        'sounds_dir' => env('ASTERISK_SOUNDS_DIR', ''),
        'pjsip_file' => env('ASTERISK_PJSIP_FILE', '/etc/asterisk/pjsip_broadcast.conf'),
        'pjsip_transport' => env('ASTERISK_PJSIP_TRANSPORT', 'transport-udp'),
        'dry_run' => (bool) env('ASTERISK_DRY_RUN', false),
    ],
];
