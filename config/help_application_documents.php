<?php

use App\Enums\HelpApplicationDocumentSecurityStatus;

return [
    'disk' => 'help_application_documents',
    'qpdf' => [
        'binary' => env('QPDF_BINARY'),
        'version' => '12.4.1',
        'timeout_seconds' => 10,
        'max_output_bytes' => 8388608,
        'supported_json_versions' => [2],
    ],
    'limits' => [
        'max_file_bytes' => 10485760,
        'max_active_documents' => 10,
        'max_combined_active_bytes' => 52428800,
        'max_image_width' => 8000,
        'max_image_height' => 8000,
        'max_decoded_image_pixels' => 40000000,
        'max_pdf_pages' => 100,
    ],
    'formats' => [
        'jpg' => ['extension' => 'jpg', 'mime_type' => 'image/jpeg'],
        'png' => ['extension' => 'png', 'mime_type' => 'image/png'],
        'pdf' => ['extension' => 'pdf', 'mime_type' => 'application/pdf'],
    ],
    'submission_eligible_security_statuses' => [
        HelpApplicationDocumentSecurityStatus::AcceptedUnscanned->value,
        HelpApplicationDocumentSecurityStatus::Clean->value,
    ],
];
