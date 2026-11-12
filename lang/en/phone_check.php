<?php

declare(strict_types=1);

return [
    'title' => 'Phone check',
    'intro' => 'This page checks whether your phone can do everything docHamster needs. Nothing is sent to the server.',
    'open' => 'pending',
    'ok' => 'yes',
    'warn' => 'unclear',
    'fail' => 'no',

    'check_standalone' => 'Runs as a page on the home screen',
    'check_key' => 'Secret key works',
    'check_storage' => 'Storage persistence granted',
    'check_camera' => 'Camera in the page',
    'check_qr' => 'QR code read',
    'check_photo' => 'Photo with the phone camera',
    'check_file' => 'Photo or document chosen',

    'key_new' => 'newly created',
    'key_found' => 'found again, created on :date',
    'other_code' => 'different QR code detected',

    'start_camera' => 'Start camera',
    'take_photo' => 'Take photo',
    'pick_file' => 'Choose photo or document',

    'qr_title' => 'Phone check: QR codes',
    'qr_step_page' => '1. Open the test page',
    'qr_step_page_hint' => 'Scan with the phone’s regular camera and add the page to the home screen.',
    'qr_step_test' => '2. Test the camera',
    'qr_step_test_hint' => 'On the test page, tap “Start camera” and scan this code. It changes every 30 seconds.',
];
