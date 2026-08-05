<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BusinessSettingsController;
use App\Http\Controllers\Inventory\CategoryController;
use App\Http\Controllers\Inventory\InventoryExportController;
use App\Http\Controllers\Inventory\InventoryImportController;
use App\Http\Controllers\Inventory\InventoryQueueController;
use App\Http\Controllers\Inventory\ItemController;
use App\Http\Controllers\Inventory\StockAdjustmentController;
use App\Http\Controllers\Inventory\StockHistoryController;
use App\Http\Controllers\LoginHistoryController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PrinterSettingsController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Desktop app entry point: no public marketing page. Send everyone to the
// POS screen; the auth middleware bounces guests to the login screen.
Route::redirect('/', '/pos')->name('home');

// Setup wizard (fresh device only): import the branch credentials, connect,
// and hydrate. Pre-auth by design — the Owner exists in Postgres, not yet on
// this device. RequireInitialSetup funnels every request here until the device
// is hydrated and redirects these routes to login once it is.
Route::get('setup', [SetupController::class, 'create'])->name('setup');
Route::post('setup', [SetupController::class, 'store'])->name('setup.store');
Route::post('setup/browse', [SetupController::class, 'browse'])->name('setup.browse');
// OS print-queue detection for the wizard's printer step.
Route::get('setup/printers', [PrinterSettingsController::class, 'printers'])->name('setup.printers');

// Run one sync cycle (M6). It answers JSON and never throws — a sync failure
// must not disturb a completed sale, which is why the POS page can fire it after
// every checkout.
//
// PRE-AUTH from M11.5, and not merely as a convenience. The cycle's PULL is what
// brings `users` down, so while this sat behind `auth` a staff member created at
// another branch could not log in on this device until somebody ELSE logged in
// first and a cycle happened to run — a genuine lock-out on a product whose
// whole point is that any user may work at any branch. Nothing here needed a
// user to begin with: SyncCycle::run() takes no arguments and reads its identity
// from `instance` and the sealed credential, and SyncController never touches
// the request. RequireInitialSetup still applies, so this is reachable only on a
// device that already holds a branch credential.
Route::post('sync', SyncController::class)->name('sync');

Route::middleware(['auth'])->group(function () {
    // Cashier-level: available to every authenticated user.
    // Point of Sale (Phase 8). Cashier-level — every authenticated user may sell.
    Route::get('pos', [PosController::class, 'index'])->name('pos');
    Route::get('pos/lookup', [PosController::class, 'lookup'])->name('pos.lookup');
    Route::post('pos/checkout', [PosController::class, 'checkout'])->name('pos.checkout');
    // Sales history (Phase 9). Cashier-level: every user may browse all sales
    // (needed to look up a transaction for reprint/return). Item cost in the
    // detail view is Owner-only, gated inside the controller.
    Route::get('sales', [SalesController::class, 'index'])->name('sales');
    Route::get('sales/{transaction}', [SalesController::class, 'show'])->name('sales.show');
    // Receipt reprint (Phase 11). Cashier-level: anyone who can view a sale may
    // reprint it. Printing never mutates the sale.
    Route::post('sales/{transaction}/print', [SalesController::class, 'print'])->name('sales.print');
    // Returns / defects (Phase 10). Cashier-level for returns; the controller
    // elevates defect to Admin+ by the submitted type.
    Route::post('sales/{transaction}/returns', [SaleReturnController::class, 'store'])->name('sales.returns.store');
    Route::get('login-history', [LoginHistoryController::class, 'index'])->name('login-history');  // Phase 14

    // Inventory (Phase 5). Viewing is Cashier-level (read-only); every write is
    // gated to Admin+ below. Item Cost is further restricted to the Owner inside
    // the request/model layer, not here.
    Route::get('inventory', [ItemController::class, 'index'])->name('inventory');
    Route::get('inventory/categories', [CategoryController::class, 'index'])->name('categories.index');
    // Stock history (Phase 6) — read-only ledger view; the ledger carries no
    // cost, so it is Cashier-level. Literal segment, declared before the
    // {item} wildcard writes below.
    Route::get('inventory/stock-history', [StockHistoryController::class, 'index'])->name('stock-history.index');

    // What was ASKED for behind one ledger row, fetched when its dialog opens
    // (M11.8). A separate online read rather than a prop, for the same reason as
    // stock-updates.branches: Stock History renders offline and must not put a
    // Postgres connection behind every page view. Literal segment, and declared
    // ahead of the {item} wildcard along with the page itself.
    Route::get('inventory/stock-history/{entry}/request', [StockHistoryController::class, 'request'])
        ->name('stock-history.request');

    // Pending stock adjustments, and answering one. Cashier-level ON PURPOSE:
    // whether stock actually arrived is known by whoever is standing in the
    // shop, not by a role, and a floor here would leave deliveries waiting on
    // somebody who is not there. What bounds it is the branch, not the role —
    // AdjustmentResolver re-reads every row filtered to this device's own.
    // Issuing one is still Admin+, below. Literal segment, ahead of the {item}
    // wildcard.
    Route::get('inventory/stock-updates', [InventoryQueueController::class, 'index'])->name('stock-updates.index');
    // Approve all comes through the same route, as a batch of ordinary
    // approvals: the page filters now, so "all" can only honestly mean "all of
    // what is on screen", which is a list only the client has.
    Route::post('inventory/stock-updates/resolve', [InventoryQueueController::class, 'resolve'])->name('stock-updates.resolve');

    // Admin-level: Admins and the Owner.
    Route::middleware('role:admin')->group(function () {
        Route::get('reports', [ReportsController::class, 'index'])->name('reports');     // Phase 12
        Route::get('reports/export', [ReportsController::class, 'export'])->name('reports.export');
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs'); // Phase 15

        // User management (Phase 13). Which roles an actor may create/assign and
        // which users they may touch is enforced per-action by UserPolicy.
        Route::get('users', [UserController::class, 'index'])->name('users');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
        Route::patch('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::patch('users/{user}/archive', [UserController::class, 'archive'])->name('users.archive');
        Route::patch('users/{user}/restore', [UserController::class, 'restore'])->name('users.restore')->withTrashed();

        // Inventory writes (Phase 5). Literal segments are declared before the
        // {item} wildcard so create/categories are never captured as an id.
        Route::get('inventory/create', [ItemController::class, 'create'])->name('items.create');
        Route::post('inventory', [ItemController::class, 'store'])->name('items.store');

        // Inventory CSV import / export (Phase 7). Literal segments, declared
        // before the {item} wildcard so they are never captured as an item id.
        Route::get('inventory/import', [InventoryImportController::class, 'index'])->name('inventory.import');
        Route::post('inventory/import', [InventoryImportController::class, 'store'])->name('inventory.import.store');
        Route::get('inventory/import/template', [InventoryImportController::class, 'template'])->name('inventory.import.template');
        Route::get('inventory/export', InventoryExportController::class)->name('inventory.export');

        // ASKING for a stock adjustment at another branch. Online-only:
        // everything here lives in Postgres and is deliberately not mirrored.
        // Answering one is Cashier-level and sits above. Literal segments, ahead
        // of the {item} wildcard.
        Route::get('inventory/stock-updates/branches', [InventoryQueueController::class, 'branches'])->name('stock-updates.branches');
        Route::post('inventory/stock-updates', [InventoryQueueController::class, 'store'])->name('stock-updates.store');
        Route::post('inventory/stock-updates/{queued}/reissue', [InventoryQueueController::class, 'reissue'])->name('stock-updates.reissue');

        // A branch-to-branch transfer, which is one request and TWO pending
        // rows: the sending branch answers first, and confirming it is what
        // creates the receiving branch's row.
        Route::post('inventory/stock-transfers', [InventoryQueueController::class, 'transfer'])->name('stock-transfers.store');

        Route::get('inventory/{item}/edit', [ItemController::class, 'edit'])->name('items.edit');
        Route::patch('inventory/{item}', [ItemController::class, 'update'])->name('items.update');
        Route::patch('inventory/{item}/archive', [ItemController::class, 'archive'])->name('items.archive');
        Route::patch('inventory/{item}/restore', [ItemController::class, 'restore'])->name('items.restore');

        // Manual stock adjustment (Phase 6). Writes an inventory_ledger entry.
        // Online-only since M7: stock is shared with the business, and a
        // recount that reached only this device would be reverted by the next
        // cycle's baseline. Refused offline by OnlineWrite, not by this route.
        Route::post('inventory/variants/{variant}/stock', [StockAdjustmentController::class, 'store'])->name('stock.adjust');

        Route::post('inventory/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('inventory/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::patch('inventory/categories/{category}/archive', [CategoryController::class, 'archive'])->name('categories.archive');
        Route::patch('inventory/categories/{category}/restore', [CategoryController::class, 'restore'])->name('categories.restore');
    });

    // Owner-only.
    Route::middleware('role:owner')->group(function () {
        Route::get('business-settings', [BusinessSettingsController::class, 'edit'])->name('business-settings');      // Phase 4
        Route::patch('business-settings', [BusinessSettingsController::class, 'update'])->name('business-settings.update');
        // Receipt-printer configuration (Phase 11). Surfaced on the same page.
        Route::patch('printer-settings', [PrinterSettingsController::class, 'update'])->name('printer-settings.update');
        Route::post('printer-settings/test', [PrinterSettingsController::class, 'test'])->name('printer-settings.test');
        Route::get('printer-settings/printers', [PrinterSettingsController::class, 'printers'])->name('printer-settings.printers');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
