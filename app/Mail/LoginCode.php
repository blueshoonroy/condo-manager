<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class LoginCode extends Mailable
{
    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your 1262 Bryn Mawr sign-in code');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.login-code');
    }
}
