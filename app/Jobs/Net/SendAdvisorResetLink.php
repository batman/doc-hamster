<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use App\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Point 210.3: the "Passwort vergessen?" mail of a tax advisor – a plain text with the reset link (valid 60 minutes,
 * config/auth.php). Mail leaves only from a net job (D-020); the request dispatches it after its response, so the
 * answer takes the same time whether the address exists or not.
 */
final class SendAdvisorResetLink implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $email, #[\SensitiveParameter] public string $url)
    {
        $this->onQueue(QueueName::Net->value);
    }

    public function handle(): void
    {
        Mail::raw((string) __('advisor.mail.body', ['url' => $this->url, 'minutes' => config('auth.passwords.users.expire', 60)]), function (Message $message): void {
            $message->to($this->email)->subject((string) __('advisor.mail.subject'));
        });
    }
}
