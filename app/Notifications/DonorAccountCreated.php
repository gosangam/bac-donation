<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a guest donor whose account was created for them after paying.
 *
 * It carries a password-reset link rather than a password: we never generate a
 * password a human is meant to read, and a reset token proves control of the
 * mailbox before granting access to the donation history.
 */
class DonorAccountCreated extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $org = config('payments.org');

        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject('Set a password for your '.$org['name'].' account')
            ->greeting('Thank you, '.$notifiable->name.'.')
            ->line('We\'ve created an account for you so you can see your donations and download receipts at any time.')
            ->action('Set your password', $url)
            ->line('This link expires in '.config('auth.passwords.users.expire', 60).' minutes. '
                .'If it does, use "Reset password" on the sign-in page.')
            ->line('You do not need an account to give again — this is purely for your records.')
            ->salutation($org['name']);
    }
}
