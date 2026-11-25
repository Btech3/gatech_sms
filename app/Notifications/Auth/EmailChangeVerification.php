<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailChangeVerification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $verificationUrl) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your new email address')
            ->greeting('Confirm your new email address')
            ->line('A request was made to change the email address on your School Management Portal account.')
            ->action('Confirm email address', $this->verificationUrl)
            ->line('This link expires in 30 minutes. If you did not request this change, you can ignore this message.');
    }
}
