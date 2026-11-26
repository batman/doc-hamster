<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use App\Domain\Mail\MailFetch;
use App\Domain\Mail\MailInbox;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A128: the cron fetch of one Mail-Eingang (MailFetch::schedule hands it out every few minutes). It works as the person
 * who set the mail intake up, in their organisation – like a click on "Postfach abrufen".
 */
final class FetchMailInbox implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $inboxId)
    {
        $this->onQueue(QueueName::Net->value);
    }

    public function handle(CurrentOrg $org): void
    {
        $inbox = MailInbox::query()->withoutGlobalScope(OrgScope::class)->find($this->inboxId);
        if ($inbox === null || ! $inbox->ready()) {
            return;
        }
        $membership = Membership::query()->withoutGlobalScopes()->where('org_id', $inbox->org_id)->where('user_id', $inbox->user_id)->whereNull('revoked_at')->first();
        if ($membership === null || $org->isSet()) {
            return;
        }
        $org->set($membership);
        try {
            MailFetch::run($inbox);
        } finally {
            $org->clear();
        }
    }
}
