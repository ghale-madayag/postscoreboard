<?php
// Copy this file to config.php and fill in the real credentials.
// config.php is git-ignored and must never be committed.

return [
    'imap' => [
        'host'       => 'imap.gmail.com',
        'port'       => 993,
        'encryption' => 'ssl',
        'username'   => 'abegail@depthlogistics.com',
        'password'   => 'xxxx xxxx xxxx xxxx', // Google App Password (requires 2-Step Verification)
        'folder'     => 'INBOX',
    ],

    // Case-insensitive substring matched against the email subject.
    'match_subject_substring' => 'scoreboard',

    // How many days back the IMAP SINCE search looks. Dedup state prevents re-posting.
    'lookback_days' => 3,

    'wordpress' => [
        'base_url'     => 'https://depthintranet.com',
        'username'     => 'wp-user',
        'app_password' => 'xxxx xxxx xxxx xxxx xxxx xxxx', // WP Admin > Users > Profile > Application Passwords

        // Rotation candidates. The section whose latest post is OLDEST receives the
        // next post. Ties are broken by this order.
        'cct_slugs' => [
            'weekly_challenge',
            'depth_at_work_3',
            'depth_at_work_2',
        ],

        // Only emails from users returned by this endpoint are posted
        // (matched on user_email, case-insensitive). Empty string disables
        // the filter.
        'approved_senders_path' => '/wp-json/user/contributor/',

        'timeout_seconds' => 60,
    ],

    // Images larger than this are skipped (the email still posts without them).
    'max_image_bytes' => 10 * 1024 * 1024,

    // If true, matched emails are marked read in Gmail after a successful post.
    'mark_processed_seen' => false,
];
