<?php

namespace App\Enums;

enum HelpApplicationDocumentPurpose: string
{
    case MedicalReport = 'medical_report';
    case CostEstimate = 'cost_estimate';
    case TuitionInvoice = 'tuition_invoice';
    case AdmissionLetter = 'admission_letter';
    case Other = 'other';
}
