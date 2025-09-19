<?php
// app/Enums/ItemStatus.php
namespace App\Enums;

enum ItemStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match($this) {
            self::Draft => 'مسودة',
            self::InReview => 'قيد المراجعة',
            self::ChangesRequested => 'يحتاج تعديل',
            self::Approved => 'معتمد',
            self::Published => 'منشور',
            self::Archived => 'مؤرشف',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft => '#6b7280',
            self::InReview => '#3b82f6',
            self::ChangesRequested => '#f59e0b',
            self::Approved => '#10b981',
            self::Published => '#059669',
            self::Archived => '#6b7280',
        };
    }
}
