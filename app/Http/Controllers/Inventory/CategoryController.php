<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreCategoryRequest;
use App\Http\Requests\Inventory\UpdateCategoryRequest;
use App\Models\Category;
use App\Support\Audit\AuditRecorder;
use App\Support\Sync\OnlineWrite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Categories are SHARED business data (§5.1): every branch reads them, so a
 * change has to reach the business database or it lives on one till and nowhere
 * else. Every write here therefore runs as an ONLINE WRITE and is refused when
 * this device has no connection — see {@see OnlineWrite}, and M6.5 in
 * MULTIBRANCH-ROADMAP.md for why these screens wrote only locally until then.
 *
 * Reading stays entirely offline. Browsing the catalog with the internet down
 * works exactly as it always has.
 */
class CategoryController extends Controller
{
    public function __construct(private readonly OnlineWrite $online) {}

    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['active', 'archived', 'all'], true)
            ? $request->query('status')
            : 'active';

        $categories = Category::query()
            ->withCount('items')
            ->when($status === 'active', fn ($query) => $query->whereNull('archived_at'))
            ->when($status === 'archived', fn ($query) => $query->whereNotNull('archived_at'))
            ->orderBy('name')
            ->get();

        return Inertia::render('items/categories', [
            'categories' => $categories,
            'filters' => ['status' => $status],
            // Told to the screen so it can say so before a form is filled in
            // rather than after it is submitted.
            'online' => $this->online->possible(),
        ]);
    }

    public function store(StoreCategoryRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $this->online->run(function () use ($request, $audit): void {
            $category = Category::create($request->validated());

            $this->online->mirror($category);

            $audit->record(
                action: 'inventory.category.created',
                auditable: $category,
                description: "Created category \"{$category->name}\".",
                new: ['name' => $category->name],
            );
        }, 'A category');

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Category created.',
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category, AuditRecorder $audit): RedirectResponse
    {
        $oldName = $category->name;

        $this->online->run(function () use ($request, $category, $audit, $oldName): void {
            $category->update($request->validated());

            $this->online->mirror($category);

            $audit->record(
                action: 'inventory.category.updated',
                auditable: $category,
                description: "Renamed category \"{$oldName}\" to \"{$category->name}\".",
                old: ['name' => $oldName],
                new: ['name' => $category->name],
            );
        }, 'A category');

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Category updated.',
        ]);
    }

    public function archive(Category $category, AuditRecorder $audit): RedirectResponse
    {
        Gate::authorize('manage-inventory');

        // Archiving is an UPDATE, not a delete — which is why supporting all of
        // this needs no DELETE privilege anywhere in the grant matrix.
        $this->online->run(function () use ($category, $audit): void {
            $category->update(['archived_at' => now()]);

            $this->online->mirror($category);

            $audit->record(
                action: 'inventory.category.archived',
                auditable: $category,
                description: "Archived category \"{$category->name}\".",
            );
        }, 'A category');

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Category archived.',
        ]);
    }

    public function restore(Category $category, AuditRecorder $audit): RedirectResponse
    {
        Gate::authorize('manage-inventory');

        $this->online->run(function () use ($category, $audit): void {
            $category->update(['archived_at' => null]);

            $this->online->mirror($category);

            $audit->record(
                action: 'inventory.category.restored',
                auditable: $category,
                description: "Restored category \"{$category->name}\".",
            );
        }, 'A category');

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Category restored.',
        ]);
    }
}
