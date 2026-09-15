<?php

namespace App\Enums;

enum AssistanceDeliveryMethod: string
{
    case BankTransfer = 'bank_transfer';
    case MobileWallet = 'mobile_wallet';
    case CashCollection = 'cash_collection';
    case InKind = 'in_kind';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer / تحويل بنكي',
            self::MobileWallet => 'Mobile wallet / محفظة إلكترونية',
            self::CashCollection => 'Cash collection / استلام نقدي',
            self::InKind => 'In kind / مساعدة عينية',
            self::Other => 'Other / أخرى',
        };
    }
}
