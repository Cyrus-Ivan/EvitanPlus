<?php

namespace Provisioning\Console\Commands;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Provisioning\Support\BranchDirectory;
use Provisioning\Support\BranchState;
use Provisioning\Support\BranchStatusDirectory;
use Provisioning\Support\BusinessDirectory;
use stdClass;
use Throwable;

use function Laravel\Prompts\table;

/**
 * What every branch is doing right now (§8.5, §14, M10).
 *
 * Read-only, and therefore not audited — the trail records changes, and an entry
 * per listing would bury them. Same reasoning as `branch:list`, and the same
 * split of responsibilities: {@see BranchStatusDirectory} answers the question,
 * this only decides how it looks.
 *
 * ## The age is the signal, not the word
 *
 * `connection_state` is written BY the branch, so the two states that mean it
 * cannot reach Postgres — `offline` and `unauthorized` — can never be written by
 * the branch they describe. A till whose internet died last Tuesday still says
 * `online`, because that was true when it last managed to say anything. So the
 * column that answers "is anything wrong" is **Last seen**, and it is the one
 * this command colours. The state word explains a branch that IS reporting:
 * above all `version_blocked`, which is the row M9 could not surface and the
 * reason this table exists at all.
 *
 * A branch whose credential the console disabled goes silent in exactly the same
 * way as one behind a dead router, which is why the credential state is printed
 * beneath any row that is not `active`. Two halves, one screen: without it the
 * most common cause of silence is invisible on the one screen that reports it.
 *
 * ## Not `branch:list`
 *
 * That command reports what the console DID — roles, credential generations. This
 * reports what a branch is DOING, from rows the branches upsert themselves.
 */
class MonitorCommand extends BranchCommand
{
    protected $signature = 'monitor
        {--business= : Business UUID; the schema name is derived from it}
        {--schema= : Target an explicit schema name instead}';

    protected $description = 'Show every branch of every business with its live version and sync health';

    /**
     * How long a branch may stay silent before the age is coloured.
     *
     * A till syncs on a 5-minute backstop timer whenever it is online (§11.4),
     * so three missed intervals is the first duration that means something
     * rather than a slow shop. It colours a cell; it decides nothing.
     */
    private const STALE_AFTER_MINUTES = 15;

    public function handle(): int
    {
        try {
            $schemas = $this->targets();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        } catch (Throwable $e) {
            $this->components->error('Could not list businesses: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($schemas === []) {
            $this->components->warn('This project holds no businesses yet.');

            return self::SUCCESS;
        }

        foreach ($schemas as $schema) {
            $this->business($schema);
        }

        return self::SUCCESS;
    }

    /**
     * The schemas this invocation covers.
     *
     * No target means every business, which is the point of a monitor: the
     * branch you have not thought about is the one worth seeing. Naming one is
     * for when you already know which business you are worried about.
     *
     * @return list<string>
     */
    private function targets(): array
    {
        if ($this->stringOption('business') !== null || $this->stringOption('schema') !== null) {
            return [$this->targetSchema()];
        }

        return array_values(array_map(
            fn (array $business): string => $business['schema'],
            array_filter(BusinessDirectory::all(), fn (array $business): bool => $business['provisioned']),
        ));
    }

    /** One business's branches, or a sentence saying why there are none to show. */
    private function business(string $schema): void
    {
        $this->newLine();

        $problem = $this->schemaProblem($schema);

        if ($problem !== null) {
            $this->components->warn($problem);

            return;
        }

        try {
            $rows = BranchStatusDirectory::forSchema($schema, $this->issuance());
            $name = BranchDirectory::businessName($schema);
        } catch (Throwable $e) {
            $this->components->error("Could not read [{$schema}]: ".$e->getMessage());

            return;
        }

        $this->components->info("{$name} — {$schema}");

        if ($rows === []) {
            $this->components->warn("[{$schema}] has no branches.");

            return;
        }

        table(
            headers: ['Code', 'Branch', 'App', 'Schema', 'Unsynced', 'Last sync', 'Last seen', 'State'],
            rows: array_map($this->row(...), $rows),
        );

        $this->notes($rows);
    }

    /**
     * @param  array{state: BranchState, heartbeat: stdClass|null}  $row
     * @return list<string>
     */
    private function row(array $row): array
    {
        $beat = $row['heartbeat'];

        return [
            $row['state']->code(),
            $row['state']->name(),
            $beat === null ? '—' : (string) ($beat->app_version ?? '—'),
            $beat === null ? '—' : (string) ($beat->schema_version ?? '—'),
            $beat === null ? '—' : $this->backlog((int) $beat->unsynced_count),
            $beat === null ? '—' : $this->age($beat->last_sync_at),
            $beat === null
                // Never a heartbeat: a till still in its box, or one that has
                // never once been online. The most important row here.
                ? '<fg=red>never</>'
                : $this->seen($beat->last_seen_at),
            $beat === null ? '—' : $this->coloured((string) ($beat->connection_state ?? '—')),
        ];
    }

    /**
     * The lines a table cell cannot carry: why a branch may be unable to report,
     * and what it said when it last failed.
     *
     * Beneath the table rather than in it because `last_error` is a `text`
     * column holding a driver's sentence — in a cell it would either be
     * truncated to uselessness or wrap the whole table into unreadability.
     *
     * @param  list<array{state: BranchState, heartbeat: stdClass|null}>  $rows
     */
    private function notes(array $rows): void
    {
        foreach ($rows as $row) {
            $code = $row['state']->code();

            if ($row['state']->credential !== 'active') {
                $this->components->warn(
                    "{$code} — credential is [{$row['state']->credential}], so this branch cannot sync or report."
                );
            }

            $error = $row['heartbeat']?->last_error;

            if ($error !== null && $error !== '') {
                $this->components->bulletList(["{$code} — ".$error]);
            }
        }
    }

    /**
     * A backlog is worth seeing at a glance, and zero is the normal case.
     */
    private function backlog(int $count): string
    {
        return $count === 0 ? '0' : "<fg=yellow>{$count}</>";
    }

    /**
     * Relative, because the question at this screen is always "how long has it
     * been" and never "at what time". Absolute timestamps would also invite the
     * timezone question that console/README §5 exists to keep answered.
     */
    private function age(mixed $timestamp): string
    {
        $moment = $this->moment($timestamp);

        return $moment === null ? 'never' : $moment->diffForHumans(short: true);
    }

    /** The one cell that reports a fact the branch did not choose to send. */
    private function seen(mixed $timestamp): string
    {
        $moment = $this->moment($timestamp);

        if ($moment === null) {
            return '<fg=red>never</>';
        }

        $age = $moment->diffForHumans(short: true);

        // abs(): Carbon 3 signs the difference, and a clock skewed a minute
        // into the future must not read as fresher than fresh.
        return abs($moment->diffInMinutes()) >= self::STALE_AFTER_MINUTES
            ? "<fg=red>{$age}</>"
            : "<fg=green>{$age}</>";
    }

    private function moment(mixed $timestamp): ?CarbonInterface
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $timestamp);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The four words a branch may write, in the colour each deserves. The
     * vocabulary is the till's own (§11.6) and is not re-spelled here — a fifth
     * value is refused by the schema, and anything unexpected stays uncoloured
     * rather than being quietly normalised into one of the four.
     */
    private function coloured(string $state): string
    {
        return match ($state) {
            'online' => "<fg=green>{$state}</>",
            'version_blocked' => "<fg=yellow>{$state}</>",
            'unauthorized' => "<fg=red>{$state}</>",
            'offline' => "<fg=red>{$state}</>",
            default => $state,
        };
    }
}
