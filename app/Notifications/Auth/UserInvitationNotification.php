<?php

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $acceptUrl)
    {
        $this->afterCommit();
    }

    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You are invited to the School Management Portal')
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line('An administrator has invited you to access the School Management Portal.')
            ->action('Review invitation', $this->acceptUrl)
            ->line('This one-time invitation link expires in 48 hours.');
    }
}
