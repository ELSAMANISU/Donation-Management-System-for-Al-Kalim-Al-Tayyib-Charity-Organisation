<?php

namespace App\Enums;

enum AssistanceCoordinationState: string
{
    case AwaitingApplicant = 'awaiting_applicant';
    case ApplicantResponded = 'applicant_responded';
    case ChangesRequested = 'changes_requested';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingApplicant => 'Awaiting applicant / بانتظار مقدم الطلب',
            self::ApplicantResponded => 'Applicant responded / تم رد مقدم الطلب',
            self::ChangesRequested => 'Corrections requested / تصحيحات مطلوبة',
            self::Confirmed => 'Ready for future aid delivery / جاهز لبدء تسليم المساعدة لاحقًا',
        };
    }
}
