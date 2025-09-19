<?php
// app/Jobs/SendNotificationJob.php
namespace App\Jobs;

use App\Models\User;
use App\Notifications\BaseNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public User $user;
    public BaseNotification $notification;
    public int $timeout = 30;
    public int $tries = 3;

    public function __construct(User $user, BaseNotification $notification)
    {
        $this->user = $user;
        $this->notification = $notification;
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        try {
            $this->user->notify($this->notification);

            Log::info("Notification sent successfully", [
                'user_id' => $this->user->id,
                'notification_type' => get_class($this->notification),
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to send notification", [
                'user_id' => $this->user->id,
                'notification_type' => get_class($this->notification),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
