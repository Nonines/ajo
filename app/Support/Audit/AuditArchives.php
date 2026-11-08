<?php

namespace App\Support\Audit;

use App\Contracts\Clock;
use App\Models\AuditEvent;
use App\Support\Ledger\FinancialTransaction;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Archives verified audit prefixes and preserves sealed chain boundaries after retention expiry.
 */
class AuditArchives
{
    public function __construct(private AuditTrail $audit, private FinancialTransaction $transactions, private Clock $clock) {}

    /**
     * During maintenance, verifies and archives an old prefix, then marks expired archives for purge.
     */
    public function maintain(): int
    {
        if (! app()->isDownForMaintenance()) {
            throw new DomainException('Audit removal is available only during maintenance.');
        }

        return $this->transactions->run([], function (): int {
            $this->audit->verify();
            $rows = [];
            $cutoff = $this->clock->now()->subDays(config('audit.online_days'));

            foreach (AuditEvent::orderBy('id')->limit(5000)->get() as $event) {
                if ($event->created_at->greaterThanOrEqualTo($cutoff)) {
                    break;
                }

                $rows[] = $event->getAttributes();
            }

            // Verify the encrypted archive before advancing the online retention boundary.
            if ($rows !== []) {
                $first = $rows[0];
                $last = $rows[array_key_last($rows)];
                $path = 'audit/'.$first['id'].'-'.$last['id'].'.enc';
                $disk = Storage::disk(config('audit.archive_disk'));
                $encrypted = Crypt::encryptString(json_encode(['version' => 1, 'events' => $rows], JSON_THROW_ON_ERROR));

                if (! $disk->put($path, $encrypted)) {
                    throw new DomainException('Audit archive could not be stored.');
                }

                $manifest = ['first_sequence' => $first['id'], 'last_sequence' => $last['id'],
                    'previous_hash' => $first['previous_hash'], 'last_hash' => $last['hash'], 'path' => $path,
                    'file_hash' => hash('sha256', $encrypted), 'created_at' => $this->clock->now()->toDateTimeString(),
                    'expires_at' => CarbonImmutable::parse($last['created_at'], 'UTC')->addYears(config('audit.retention_years'))->toDateTimeString(),
                    'purged_at' => null, 'key_version' => config('audit.key_version')];
                $manifest['seal'] = $this->seal($manifest);
                $this->restore($manifest);
                DB::table('audit_archives')->insert($manifest);

                // Enable deletion only for this verified prefix and restore trigger protection immediately.
                $pdo = DB::connection()->getPdo();
                $pdo->sqliteCreateFunction('audit_maintenance', static fn (): int => 1);

                try {
                    DB::table('activity_log')->where('id', '<=', $last['id'])->delete();
                } finally {
                    $pdo->sqliteCreateFunction('audit_maintenance', static fn (): int => 0);
                }

                DB::table('audit_head')->where('id', 1)->update(['floor_sequence' => $last['id'], 'floor_hash' => $last['hash']]);
            }

            // Record expiry durably before a separate pass removes archive files.
            foreach (DB::table('audit_archives')->whereNull('purged_at')->where('expires_at', '<=', $this->clock->now())->orderBy('id')->get() as $stored) {
                $manifest = (array) $stored;
                // Preserve the file until the durable purge boundary commits; a later pass can remove an orphan.
                $manifest['purged_at'] = $this->clock->now()->toDateTimeString();
                DB::table('audit_archives')->where('id', $stored->id)->update(['purged_at' => $manifest['purged_at'], 'seal' => $this->seal($manifest)]);
            }

            return count($rows);
        });
    }

    /**
     * Deletes files only after durable purge markers exist and prunes references through the purged prefix.
     */
    public function purgeFiles(): void
    {
        if (! app()->isDownForMaintenance()) {
            throw new DomainException('Archive purge requires maintenance.');
        }

        $this->verify();

        foreach (DB::table('audit_archives')->whereNotNull('purged_at')->get() as $archive) {
            if (! Storage::disk(config('audit.archive_disk'))->delete($archive->path)) {
                throw new DomainException('Expired archive could not be removed; retry maintenance.');
            }
        }

        $prefix = 0;

        foreach (DB::table('audit_archives')->orderBy('first_sequence')->get() as $archive) {
            if ($archive->purged_at === null) {
                break;
            }

            $prefix = $archive->last_sequence;
        }

        DB::table('audit_customer_references')->where('last_sequence', '<=', $prefix)->delete();
    }

    /**
     * Returns authenticated archive rows without inserting them into the online audit table.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    public function restore(array $manifest): array
    {
        if (! hash_equals($manifest['seal'], $this->seal($manifest))) {
            throw new DomainException('Audit archive manifest has been altered.');
        }

        $encrypted = Storage::disk(config('audit.archive_disk'))->get($manifest['path']);

        if (! is_string($encrypted) || ! hash_equals($manifest['file_hash'], hash('sha256', $encrypted))) {
            throw new DomainException('Audit archive is missing or altered.');
        }

        $payload = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);

        if (($payload['version'] ?? null) !== 1 || ! is_array($payload['events'] ?? null) || $payload['events'] === [] || ! array_is_list($payload['events'])) {
            throw new DomainException('Unsupported or empty audit archive.');
        }

        // Authenticate every archived event as well as the signed archive boundaries.
        $sequence = $manifest['first_sequence'];
        $hash = $manifest['previous_hash'];

        foreach ($payload['events'] as $row) {
            if (! is_array($row) || array_filter(array_keys($row), is_string(...)) !== array_keys($row)) {
                throw new DomainException('Malformed audit archive event.');
            }

            $hash = $this->audit->verifyEvent((new AuditEvent)->newFromBuilder($row), $sequence++, $hash);
        }

        if ($sequence - 1 !== $manifest['last_sequence'] || ! hash_equals($manifest['last_hash'], $hash)) {
            throw new DomainException('Audit archive boundary is inconsistent.');
        }

        return $payload['events'];
    }

    /**
     * Verifies contiguous archive boundaries and checks unpurged contents against the online floor.
     */
    public function verify(): void
    {
        $sequence = 0;
        $hash = str_repeat('0', 64);

        foreach (DB::table('audit_archives')->orderBy('first_sequence')->get() as $stored) {
            $manifest = (array) $stored;

            if ($manifest['first_sequence'] !== $sequence + 1 || ! hash_equals($hash, $manifest['previous_hash'])
                || ! hash_equals($manifest['seal'], $this->seal($manifest))) {
                throw new DomainException('Audit archive continuity or signature is invalid.');
            }

            if ($manifest['purged_at'] === null) {
                $this->restore($manifest);
            } elseif (CarbonImmutable::parse($manifest['expires_at'], 'UTC')->greaterThan($this->clock->now())) {
                throw new DomainException('Audit evidence was purged before expiry.');
            }

            $sequence = $manifest['last_sequence'];
            $hash = $manifest['last_hash'];
        }

        $head = DB::table('audit_head')->where('id', 1)->sole();

        if ($sequence !== $head->floor_sequence || ! hash_equals($hash, $head->floor_hash)) {
            throw new DomainException('Audit archive boundary does not match online evidence.');
        }
    }

    /**
     * Signs manifest metadata while excluding its database ID and the seal itself.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function seal(array $manifest): string
    {
        unset($manifest['id'], $manifest['seal']);

        return hash_hmac('sha256', $this->audit->canonical($manifest), $this->audit->key($manifest['key_version']));
    }
}
