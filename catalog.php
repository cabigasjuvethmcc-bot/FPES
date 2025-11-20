<?php
// Centralized catalog of departments and programs
// Canonical department keys: Business, Education, Technology

$DEPT_LABELS = [
    'Business'   => 'SOB (School of Business)',
    'Education'  => 'SOE (School of Education)',
    'Technology' => 'SOT (School of Technology)',
];

$PROGRAMS_BY_DEPT = [
    'Business' => [
        'BS in Business Administration – Marketing Management',
        'BS in Business Administration – Human Resource Management',
    ],
    'Education' => [
        'Bachelor of Elementary Education – General Content',
        'Bachelor of Secondary Education – English',
        'Bachelor of Secondary Education – Math',
        'Bachelor of Secondary Education – Filipino',
    ],
    'Technology' => [
        'Bachelor of Industrial Technology – Computer Technology',
        'Bachelor of Industrial Technology – Electronics Technology',
        'BS in Information Technology',
    ],
];

// Helper: normalize department codes/labels to canonical keys
function normalize_department_key($dept) {
    $map = [
        'SOB' => 'Business',
        'SOE' => 'Education',
        'SOT' => 'Technology',
        'School of Business' => 'Business',
        'School of Education' => 'Education',
        'School of Technology' => 'Technology',
    ];
    return $map[$dept] ?? $dept;
}
