<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Auth\User;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Documents\Folder;
use App\Domain\Documents\TextDocument;
use App\Domain\Notes\Note;
use App\Domain\Notes\NoteColumn;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\Role;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The local demo (F48, `php artisan dochamster:demo`): one organisation with folders, synthetic receipts (drawn here,
 * never real ones), a giro account with bookings – some linked –, suggested and confirmed properties and a few notes,
 * so every screen can be checked in the browser without a real installation. Sign-in: USERNAME / PASSWORD below –
 * test values for this local demo only.
 */
final class DemoSeeder extends Seeder
{
    public const USERNAME = 'demo';

    public const PASSWORD = 'demo-hamster-2026';

    /** Sender, amount in cents, days ago, folder key. */
    private const RECEIPTS = [
        ['Muster Bürobedarf GmbH', 4_990, 3, 'office'],
        ['Beispiel Tankstelle', 7_312, 5, 'travel'],
        ['Example Hosting Inc.', 1_200, 9, 'office'],
        ['Hotel Musterhof', 23_800, 12, 'travel'],
        ['Café Beispiel', 870, 14, null],
        ['Muster Telekom AG', 3_999, 20, 'office'],
        ['Baumarkt Beispiel', 15_640, 26, 'project'],
        ['Bahn Beispiel', 6_490, 31, 'travel'],
        ['Druckerei Muster', 18_900, 40, 'project'],
        ['Buchhandlung Beispiel', 2_450, 45, 'private'],
    ];

    public function run(): void
    {
        $org = Organization::query()->create(['name' => 'Muster GmbH']);
        $user = User::query()->create(['username' => self::USERNAME, 'display_name' => 'Max Mustermann', 'locale' => 'de', 'password_hash' => self::PASSWORD]);
        $membership = Membership::query()->create(['org_id' => $org->id, 'user_id' => $user->id, 'role' => Role::Owner]);
        app(CurrentOrg::class)->set($membership);

        $company = Folder::query()->create(['name' => 'Firma']);
        $folders = [
            'office' => Folder::query()->create(['name' => 'Büro', 'parent_id' => $company->id])->id,
            'project' => Folder::query()->create(['name' => 'Projekt Nord', 'parent_id' => $company->id])->id,
            'travel' => Folder::query()->create(['name' => 'Reisen', 'parent_id' => $company->id])->id,
            'private' => Folder::query()->create(['name' => 'Privat'])->id,
        ];

        $giro = BankAccount::query()->create(['name' => 'Giro', 'currency' => 'EUR', 'iban' => 'DE00000000000000000000']);
        $properties = Property::query()->pluck('id', 'key');

        foreach (self::RECEIPTS as $n => [$sender, $cents, $daysAgo, $folder]) {
            $date = now()->subDays($daysAgo)->startOfDay();
            $document = $this->receipt($sender, $cents, $date->format('d.m.Y'), $org->id, $user->id, $folder === null ? null : $folders[$folder]);
            $document->forceFill(['doc_date' => $date->toDateString()])->save();
            // Every other receipt confirmed, the rest suggested ("vorgeschlagen").
            $state = $n % 2 === 0 ? 'confirmed' : 'suggested';
            $this->property($document, $properties['invoice_date'] ?? null, ['value_date' => $date->toDateString()], $state);
            $this->property($document, $properties['invoice_amount'] ?? null, ['value_int' => $cents, 'currency' => 'EUR'], $state);
            $this->property($document, $properties['sender'] ?? null, ['value_text' => $sender], 'suggested');

            $booking = $this->booking($giro, $date->copy()->addDays(1)->toDateString(), -$cents, $sender);
            if ($n < 6) {
                DB::table('matches')->insert(['id' => Str::lower((string) Str::ulid()), 'org_id' => $org->id, 'transaction_id' => $booking->id,
                    'document_id' => $document->id, 'state' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        // Bookings without a receipt yet, and an incoming one.
        $this->booking($giro, now()->subDays(2)->toDateString(), -5_400, 'Muster Versicherung');
        $this->booking($giro, now()->subDays(7)->toDateString(), -1_999, 'Beispiel Software');
        $this->booking($giro, now()->subDays(10)->toDateString(), 250_000, 'Kunde Beispiel KG');

        TextDocument::create($org->id, $user->id, 'Notiz zur Steuer', false, $folders['office']);

        $column = NoteColumn::query()->create(['name' => 'Zu erledigen', 'sort' => 0]);
        foreach (['Belege März prüfen', 'Hotelrechnung nachfordern'] as $i => $title) {
            Note::query()->create(['user_id' => $user->id, 'title' => $title, 'body' => 'Beispieltext', 'column_id' => $column->id, 'sort' => $i, 'shared' => true]);
        }
    }

    /** A drawn receipt (JPEG), uploaded like a photo from the phone. */
    private function receipt(string $sender, int $cents, string $date, string $orgId, string $userId, ?string $folderId): Document
    {
        $image = imagecreatetruecolor(620, 880);
        if ($image === false) {
            throw new RuntimeException('GD cannot draw.');
        }
        $paper = (int) imagecolorallocate($image, 250, 249, 244);
        $ink = (int) imagecolorallocate($image, 30, 30, 30);
        imagefill($image, 0, 0, $paper);
        $amount = number_format($cents / 100, 2, ',', '.');
        $lines = [[5, $sender], [3, 'Musterstrasse 1, 12345 Musterstadt'], [3, ''], [4, 'Rechnung Nr. R-'.random_int(1000, 9999)], [4, 'Rechnungsdatum: '.$date],
            [3, ''], [3, 'Artikel 1 ............ '.$amount.' EUR'], [3, ''], [4, 'MwSt. 19 % enthalten'], [5, 'Gesamtbetrag: '.$amount.' EUR'], [3, ''],
            [3, 'IBAN DE00 0000 0000 0000 0000 00'], [3, 'Vielen Dank fuer Ihren Einkauf!']];
        $y = 60;
        foreach ($lines as [$font, $text]) {
            imagestring($image, $font, 50, $y, $text, $ink);
            $y += 48;
        }
        $path = tempnam(sys_get_temp_dir(), 'demo').'.jpg';
        imagejpeg($image, $path, 85);
        imagedestroy($image);

        $upload = new UploadedFile($path, Str::slug($sender).'.jpg', 'image/jpeg', null, true);

        return DocumentIntake::store($upload, 'image/jpeg', 'jpg', $orgId, $userId, $sender, folderId: $folderId);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function property(Document $document, ?string $propertyId, array $value, string $state): void
    {
        if ($propertyId !== null) {
            DocumentProperty::query()->create(['org_id' => $document->org_id, 'document_id' => $document->id, 'property_id' => $propertyId, 'state' => $state, ...$value]);
        }
    }

    private function booking(BankAccount $account, string $date, int $cents, string $counterparty): Transaction
    {
        return Transaction::query()->create(['bank_account_id' => $account->id, 'booking_date' => $date, 'amount_minor' => $cents, 'currency' => 'EUR',
            'counterparty' => $counterparty, 'purpose' => 'Beispielbuchung', 'fingerprint' => Str::random(64)]);
    }
}
