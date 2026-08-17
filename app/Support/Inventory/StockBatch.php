<?php

namespace App\Support\Inventory;

use App\Models\Instance;
use App\Support\Sales\TransactionNumberGenerator;
use App\Support\Sync\OnlineWrite;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * The two names of one stock batch (docs/glossary.md §4).
 *
 * A batch is many stock movements asked for as one act, and it has needed two
 * names since somebody first had to talk about one out loud:
 *
 *  - `id` — a uuid, the IDENTITY. It groups a Pending Stocks card, pairs a
 *    transfer's legs, and is what every join and every grouping key uses.
 *  - `reference` — `MNL-B-000001`, the NAME. It is what a person reads off a
 *    card, writes on a delivery note, and repeats down a phone, and what Stock
 *    History filters by.
 *
 * They are one object because they are two halves of one fact, minted at the two
 * different moments each can be known: the uuid the instant the batch is
 * assembled, the reference only once a Postgres connection is open, since the
 * serial is read from the queue. A caller holds this across
 * {@see OnlineWrite::run()} and reads the reference back
 * afterwards — that is why `reference` is not readonly.
 *
 * ## The format, and why it is stored
 *
 * The branch code, `-B-`, then a zero-padded running serial. It is the receipt
 * number's shape ({@see TransactionNumberGenerator}) for the
 * receipt number's reason: branches mint independently, so the branch prefix is
 * the only thing keeping two branches' serials apart. `-B-` exists so a batch
 * cannot be misread as a receipt — `MNL-B-000001` and `MNL-0000000001` appear in
 * the same app, to the same people.
 *
 * The prefix names the branch that ASKED, not the branch that must answer. An
 * Owner at head office can arrange a transfer between two branches that are
 * neither of them theirs, and the serial belongs to whoever issued it.
 *
 * Stored, never composed at print time — one value with one spelling on the
 * card, on the ledger row, in the audit log and in a URL.
 */
final class StockBatch
{
    /** Zero-pad width of the running serial. */
    private const PAD_WIDTH = 6;

    /** What separates the branch code from the serial. See the class docblock. */
    private const MARKER = '-B-';

    /** The identity. Known immediately; nothing has to be open to mint it. */
    public readonly string $id;

    /**
     * The readable name, or null until {@see mint()} has run.
     *
     * Null is also the permanent state of every movement issued on its own and
     * every row written before schema 24, which is what every surface printing
     * it falls back from.
     */
    public ?string $reference = null;

    public function __construct(?string $id = null)
    {
        $this->id = $id ?? (string) Str::uuid();
    }

    /**
     * Take the next serial for this branch, on the connection already open.
     *
     * Called from inside the online write's own cycle: the queue is Postgres-only
     * and a batch can only be issued online, so the serial is read from the same
     * transaction that is about to insert it.
     *
     * The sequence continues from a MAX rather than a count, for
     * TransactionNumberGenerator's reason — a count collides forever if a row is
     * ever lost, and a MAX self-heals. Gaps are fine here in a way they are not
     * for a receipt: a rolled-back batch simply never claims its number, and
     * nothing audits a batch series.
     */
    public function mint(Connection $remote): void
    {
        $prefix = $this->prefix();

        $this->lock($remote, $prefix);

        // Fixed-width zero padding makes the lexicographic MAX the numeric MAX,
        // and every candidate carries the same prefix, so comparing the strings
        // is comparing the serials.
        $highest = (string) $remote->table('inventory_update_queue')
            ->where('batch_reference', 'like', $prefix.'%')
            ->max('batch_reference');

        $this->reference = $prefix.sprintf('%0'.self::PAD_WIDTH.'d', $this->serialOf($highest, $prefix) + 1);
    }

    /**
     * Intended to serialize minting per branch, for the length of this
     * transaction.
     *
     * Two tills at one branch issuing a batch at the same instant would otherwise
     * both read the same MAX and print one name on two batches — which does not
     * fail anywhere, it just makes the Stock History batch filter quietly return
     * two deliveries as one. A transaction-level lock is released at commit, so
     * it is safe under Supavisor's transaction pooling in a way a session-level
     * one would not be.
     *
     * ## It does not actually serialize anything yet
     *
     * `pg_advisory_xact_lock` is transaction-scoped, and this runs from an
     * `afterMirroring` closure that {@see BranchCycle} opens **no remote
     * transaction** for — so in autocommit the implicit single-statement
     * transaction releases the lock before the `max()` on the next line runs. The
     * paragraph above describes the intent, not the behaviour, and said so as
     * though it were fact until the pre-release audit.
     *
     * Left as-is deliberately: the fix is `$remote->transaction()` around the
     * mint, which is the same change as the accepted "the remote half of an
     * online write is not transactional" risk in `docs/glossary.md` §9 and
     * belongs with it. The exposure is bounded — `batch_id` is the identity,
     * nothing keys off the reference — and latent while one device serves one
     * branch.
     *
     * Skipped on anything but Postgres, which means the SQLite stand-in the
     * offline suite runs against: the function does not exist there, and SQLite
     * serializes its writers anyway.
     */
    private function lock(Connection $remote, string $prefix): void
    {
        if ($remote->getDriverName() !== 'pgsql') {
            return;
        }

        $remote->select('select pg_advisory_xact_lock(hashtext(?))', ['stock_batch:'.$prefix]);
    }

    /**
     * This device's branch code and the marker, or just the marker.
     *
     * A device with no identity cannot reach a mint at all — the online write
     * needs the sealed credential the wizard writes beside `instance` — but a
     * prefix of `-B-` is a defensible reading of "no branch" rather than a crash,
     * and it keeps the value parseable if one ever appears.
     */
    private function prefix(): string
    {
        // `??` takes isset semantics over the whole left side, so this covers a
        // device with no identity as well as an identity with no code.
        return (Instance::current()->branch_code ?? '').self::MARKER;
    }

    /**
     * The numeric part of a stored reference.
     *
     * `(int) "MNL-B-000009"` is 0, so a mint that fed the prefixed max straight
     * to a cast would hand out serial 1 forever — the trap
     * TransactionNumberGenerator documents, and the reason the prefix is stripped
     * by length rather than parsed.
     */
    private function serialOf(string $reference, string $prefix): int
    {
        if ($reference === '') {
            return 0;
        }

        if (str_starts_with($reference, $prefix)) {
            return (int) substr($reference, strlen($prefix));
        }

        // A reference whose prefix is not this device's: the branch was renamed,
        // or somebody moved rows by hand. Its trailing digits are still a serial
        // that has been handed out, and reading it as "none yet" would hand out
        // a name that is already taken.
        return (int) (preg_match('/(\d+)$/', $reference, $matches) === 1 ? $matches[1] : '0');
    }
}
