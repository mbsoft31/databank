<?php
// app/Enums/ExportStatus.php
namespace App\Enums;

enum ExportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    // Labels for UI display
    public function label(): string
    {
        return match($this) {
            self::Pending => 'قيد الانتظار',
            self::Processing => 'قيد المعالجة',
            self::Completed => 'مكتمل',
            self::Failed => 'فشل',
            self::Cancelled => 'ملغي',
            self::Expired => 'منتهي الصلاحية',
        };
    }

    // English labels
    public function labelEn(): string
    {
        return match($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    // Colors for UI indication
    public function color(): string
    {
        return match($this) {
            self::Pending => '#6b7280',      // gray-500
            self::Processing => '#3b82f6',    // blue-500
            self::Completed => '#10b981',     // emerald-500
            self::Failed => '#ef4444',        // red-500
            self::Cancelled => '#f59e0b',     // amber-500
            self::Expired => '#6b7280',       // gray-500
        };
    }

    // Icons for UI indication
    public function icon(): string
    {
        return match($this) {
            self::Pending => 'clock',
            self::Processing => 'cog',
            self::Completed => 'check-circle',
            self::Failed => 'x-circle',
            self::Cancelled => 'ban',
            self::Expired => 'clock-x',
        };
    }

    // Status descriptions
    public function description(): string
    {
        return match($this) {
            self::Pending => 'التصدير في قائمة الانتظار وسيتم معالجته قريباً',
            self::Processing => 'جاري معالجة التصدير وإنتاج الملف',
            self::Completed => 'تم إنتاج الملف بنجاح وهو جاهز للتحميل',
            self::Failed => 'فشل في إنتاج الملف بسبب خطأ تقني',
            self::Cancelled => 'تم إلغاء التصدير بناءً على طلب المستخدم',
            self::Expired => 'انتهت صلاحية الملف وتم حذفه من الخادم',
        };
    }

    // Check if status is final (no further transitions expected)
    public function isFinal(): bool
    {
        return match($this) {
            self::Completed, self::Failed, self::Cancelled, self::Expired => true,
            self::Pending, self::Processing => false,
        };
    }

    // Check if status is successful
    public function isSuccessful(): bool
    {
        return $this === self::Completed;
    }

    // Check if status indicates failure
    public function isFailure(): bool
    {
        return match($this) {
            self::Failed, self::Cancelled, self::Expired => true,
            default => false,
        };
    }

    // Check if status is in progress
    public function isInProgress(): bool
    {
        return match($this) {
            self::Pending, self::Processing => true,
            default => false,
        };
    }

    // Check if export can be cancelled
    public function canBeCancelled(): bool
    {
        return match($this) {
            self::Pending, self::Processing => true,
            default => false,
        };
    }

    // Check if export can be downloaded
    public function canBeDownloaded(): bool
    {
        return $this === self::Completed;
    }

    // Check if export can be retried
    public function canBeRetried(): bool
    {
        return match($this) {
            self::Failed, self::Cancelled, self::Expired => true,
            default => false,
        };
    }

    // Get valid next statuses
    public function getValidTransitions(): array
    {
        return match($this) {
            self::Pending => [self::Processing, self::Cancelled, self::Failed],
            self::Processing => [self::Completed, self::Failed, self::Cancelled],
            self::Completed => [self::Expired],
            self::Failed => [], // Terminal - can create new export
            self::Cancelled => [], // Terminal - can create new export
            self::Expired => [], // Terminal - can create new export
        };
    }

    // Check if transition to another status is valid
    public function canTransitionTo(self $newStatus): bool
    {
        return in_array($newStatus, $this->getValidTransitions());
    }

    // Get estimated completion percentage
    public function getProgressPercentage(): int
    {
        return match($this) {
            self::Pending => 0,
            self::Processing => 50,
            self::Completed => 100,
            self::Failed => 0,
            self::Cancelled => 0,
            self::Expired => 0,
        };
    }

    // Get all possible statuses
    public static function all(): array
    {
        return [
            self::Pending,
            self::Processing,
            self::Completed,
            self::Failed,
            self::Cancelled,
            self::Expired,
        ];
    }

    // Get statuses that indicate completion (success or failure)
    public static function completedStatuses(): array
    {
        return [
            self::Completed,
            self::Failed,
            self::Cancelled,
            self::Expired,
        ];
    }

    // Get active statuses (not final)
    public static function activeStatuses(): array
    {
        return [
            self::Pending,
            self::Processing,
        ];
    }

    // Get failure statuses
    public static function failureStatuses(): array
    {
        return [
            self::Failed,
            self::Cancelled,
            self::Expired,
        ];
    }

    // Create status from string with validation
    public static function fromString(string $status): ?self
    {
        return self::tryFrom(strtolower($status));
    }

    // Get status statistics format for analytics
    public function getAnalyticsData(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'label_en' => $this->labelEn(),
            'color' => $this->color(),
            'icon' => $this->icon(),
            'is_final' => $this->isFinal(),
            'is_successful' => $this->isSuccessful(),
            'is_failure' => $this->isFailure(),
            'is_in_progress' => $this->isInProgress(),
            'progress_percentage' => $this->getProgressPercentage(),
        ];
    }

    // Export to array for API responses
    public function toArray(): array
    {
        return [
            'status' => $this->value,
            'label' => $this->label(),
            'color' => $this->color(),
            'icon' => $this->icon(),
            'description' => $this->description(),
            'is_final' => $this->isFinal(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'can_be_downloaded' => $this->canBeDownloaded(),
            'can_be_retried' => $this->canBeRetried(),
            'progress_percentage' => $this->getProgressPercentage(),
        ];
    }
}
