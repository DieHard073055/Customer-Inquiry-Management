<?php

namespace App\Enums;

enum InquiryCategory: string
{
    case Trading = 'trading';
    case MarketData = 'market_data';
    case TechnicalIssues = 'technical_issues';
    case GeneralQuestions = 'general_questions';

    public function label(): string
    {
        return match($this) {
            self::Trading => 'Trading',
            self::MarketData => 'Market Data',
            self::TechnicalIssues => 'Technical Issues',
            self::GeneralQuestions => 'General Questions',
        };
    }
}
