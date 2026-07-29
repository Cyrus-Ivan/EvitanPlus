<?php

namespace App\Models\Concerns;

use App\Support\Sync\Outbox;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks a LOCAL-WRITE table (MULTIBRANCH-BRAINSTORM.md §5.2, docs/glossary.md
 * §3): written only on the till, append-only, pushed insert-only and keyed by
 * UUID. Creating a row enqueues it in the outbox.
 *
 * ## Why a model hook and not a call at each write site
 *
 * The write sites today are PosController::persistSale, ProcessSaleReturn,
 * StockLedger::record, AuditRecorder and LoginHistoryRecorder — five places, and
 * a sixth is one feature away. A path that forgets to enqueue does not fail;
 * it sells, prints a receipt, hands over change, and the sale never reaches
 * Postgres. Nothing would surface that until a consolidated report came up
 * short, months later, with no way to reconstruct which sales were missed.
 *
 * So the enqueue hangs off `created`, where the only way to skip it is to
 * bypass Eloquent entirely — which none of the sale paths do, and which the
 * hydrator does on purpose because it writes PULLED tables, not these.
 *
 * ## Overriding shouldPush()
 *
 * `inventory_ledger` is the one table BOTH sides insert into: `type = 'sale'`
 * rows originate here and push up, while `type = 'stock_adjustment'` rows are
 * online-authoritative and come down (glossary §4). It overrides
 * {@see shouldPush()} to say so. Nothing else needs to.
 */
trait PushedUpward
{
    protected static function bootPushedUpward(): void
    {
        static::created(function (Model $model): void {
            app(Outbox::class)->enqueue($model);
        });
    }

    /**
     * Does this particular row belong in the outbox?
     *
     * Table membership is decided by using this trait; per-ROW membership needs
     * this hook, and only inventory_ledger has that distinction to make.
     */
    public function shouldPush(): bool
    {
        return true;
    }
}
