<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Billing e-mail + in-app notice. $type: invoice_issued | past_due | suspended | expired | paid */
class BillingNotice extends Notification
{
    public function __construct(public string $type, public array $data = [])
    {
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    private function subject(): string
    {
        return match ($this->type) {
            'invoice_issued' => __('New invoice available'),
            'past_due' => __('Payment overdue: pay to keep full access'),
            'suspended' => __('Your account was blocked for non-payment'),
            'expired' => __('Your subscription ended'),
            'paid' => __('Payment received, thank you!'),
            'pix_ready' => __('Your Pix for the next renewal is ready'),
            'recurrence_canceled' => __('Automatic renewal was canceled'),
            'payment_failed' => __('We could not charge your card'),
            default => __('Billing notice'),
        };
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject())->greeting(__('Hello, :name', ['name' => $notifiable->name]));

        if (!empty($this->data['invoice'])) {
            $mail->line(__('Invoice :number, amount :amount, due :date', [
                'number' => $this->data['invoice'],
                'amount' => $this->data['amount'] ?? '',
                'date' => $this->data['due'] ?? '',
            ]));
        }

        if (!empty($this->data['grace_ends'])) {
            $mail->line(__('Full access continues until :date.', ['date' => $this->data['grace_ends']]));
        }

        if (!empty($this->data['pix_code'])) {
            $mail->line(__('Pix copy and paste code:'))->line($this->data['pix_code']);
        }

        if ($this->type === 'recurrence_canceled') {
            $mail->line(__('Your next invoice will need to be paid manually to keep your plan.'));
        }

        if ($this->type === 'payment_failed') {
            $mail->line(__('The card issuer refused the automatic charge. We will try again; you can also pay the invoice now with another card or Pix.'));
        }

        if ($this->type === 'suspended') {
            $mail->line(__('Sending is paused and your data is read-only until the open invoice is paid.'));
        }

        return $mail->action(__('Open billing'), url('/billing'));
    }

    public function toArray($notifiable): array
    {
        $message = !empty($this->data['invoice'])
            ? __('Invoice :number, amount :amount, due :date', [
                'number' => $this->data['invoice'], 'amount' => $this->data['amount'] ?? '', 'date' => $this->data['due'] ?? '',
            ])
            : $this->subject();

        return ['title' => $this->subject(), 'message' => $message, 'type' => 'billing', 'billing_type' => $this->type] + $this->data;
    }
}
