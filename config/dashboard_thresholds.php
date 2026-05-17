<?php

return [
    'modules' => [
        'students' => [
            'active_threshold' => 50,
            'dormant_threshold' => 5,
            'recency_window_days' => 30,
        ],
        'guardians' => [
            'active_threshold' => 30,
            'dormant_threshold' => 3,
            'recency_window_days' => 30,
        ],
        'academic' => [
            'active_threshold' => 5,
            'dormant_threshold' => 1,
            'recency_window_days' => 30,
        ],
        'attendance' => [
            'active_threshold' => 100,
            'dormant_threshold' => 10,
            'recency_window_days' => 7,
        ],
        'assessments' => [
            'active_threshold' => 50,
            'dormant_threshold' => 5,
            'recency_window_days' => 30,
        ],
        'finance' => [
            'active_threshold' => 20,
            'dormant_threshold' => 1,
            'recency_window_days' => 30,
        ],
        'communication' => [
            'active_threshold' => 30,
            'dormant_threshold' => 5,
            'recency_window_days' => 14,
        ],
        'files' => [
            'active_threshold' => 10,
            'dormant_threshold' => 1,
            'recency_window_days' => 30,
        ],
        'activity_log' => [
            'active_threshold' => 20,
            'dormant_threshold' => 5,
            'recency_window_days' => 7,
        ],
    ],
    'onboarding' => [
        'modules_active_required_for_full_dashboard' => 3,
    ],
];
