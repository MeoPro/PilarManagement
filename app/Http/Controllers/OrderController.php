<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Color;
use App\Models\Employee;
use App\Models\Guest;
use App\Models\GuestPhone;
use App\Models\Order;
use App\Models\OrderCommentRecipient;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Subcontractor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    private const FACTORY_ORDER_ITEM_STATUSES = [
        'Produksi',
        'Proses Kirim',
        'Distribusi',
        'Dalam Perjalanan',
        'Finishing',
        'Packing',
        'Ready',
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        $canManageSales = $user->isSalesWorkflow();
        $factoryWorkflow = $user->isFactoryWorkflow();
        $factoryItemStatuses = self::FACTORY_ORDER_ITEM_STATUSES;
        $productionSearchItemStatuses = $factoryWorkflow ? $factoryItemStatuses : null;
        $search = trim((string) $request->query('search', ''));
        $orderType = (string) $request->query('order_type', '');
        $orderTypes = ['Bali', 'Luar Bali', 'Ekspor'];
        $statusOptions = ['None', 'Proses Produksi', 'Finishing', 'Packing', 'Distribusi', 'Service', 'Selesai', 'Cancel'];
        $statusFilter = (string) $request->query('status', '');

        if (! in_array($orderType, $orderTypes, true)) {
            $orderType = '';
        }
        if (! in_array($statusFilter, $statusOptions, true)) {
            $statusFilter = '';
        }

        $sortOptions = [
            'oldest' => 'Order terlama',
            'most' => 'Produk terbanyak',
            'remaining_more' => 'Waktu tersisa paling banyak',
            'remaining_less' => 'Waktu tersisa paling sedikit',
        ];
        $sort = $request->query('sort', 'remaining_less');

        if (! array_key_exists($sort, $sortOptions)) {
            $sort = 'remaining_less';
        }
        $deadlineSortExpression = $factoryWorkflow ? 'COALESCE(factory_deadline, deadline)' : 'deadline';

        $orderQuery = Order::query();

        if (! $canManageSales) {
            $orderQuery->select([
                'id',
                'order_id',
                'status',
                'deadline',
                'factory_deadline',
                'factory_started_at',
                'last_edit',
                'created_at',
                'updated_at',
            ]);

            if ($factoryWorkflow) {
                $orderQuery->whereHas('items', fn (Builder $query) => $this->applyFactoryItemVisibility($query));
            }
        }

        $orders = $orderQuery
            ->with($canManageSales
                ? [
                    'items' => fn ($query) => $query
                        ->withSum('activeSubcontractorAssignments as active_subcontracted_quantity', 'assigned_quantity')
                        ->withSum('activeSubcontractorAssignments as active_subcontractor_completed_quantity', 'qty_completed')
                        ->orderByRaw("CASE WHEN status = 'Distribusi' THEN 1 ELSE 0 END")
                        ->orderBy('id'),
                    'items.category',
                    'guest',
                ]
                : [
                    'items' => fn ($query) => $query
                        ->when($factoryWorkflow, fn (Builder $query) => $this->applyFactoryItemVisibility($query))
                        ->select('id', 'order_id', 'product', 'category_id', 'qty', 'qty_completed', 'factory_status', 'color', 'source', 'status', 'image')
                        ->withSum('activeSubcontractorAssignments as active_subcontracted_quantity', 'assigned_quantity')
                        ->withSum('activeSubcontractorAssignments as active_subcontractor_completed_quantity', 'qty_completed')
                        ->orderByRaw("CASE WHEN status = 'Distribusi' THEN 1 ELSE 0 END")
                        ->orderBy('id'),
                    'items.category',
                ])
            ->withCount('items')
            ->withSum('items', 'qty')
            ->withSum(['items as ready_units' => fn (Builder $query) => $query->where('status', 'Ready')], 'qty')
            ->when($search !== '', fn (Builder $query) => $canManageSales
                ? $this->applySearch($query, $search)
                : $this->applyProductionSearch($query, $search, $productionSearchItemStatuses))
            ->when($orderType !== '', fn (Builder $query) => $query->where('order_type', $orderType))
            ->when($statusFilter !== '', fn (Builder $query) => $query->where('status', $statusFilter))
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', ['Selesai'])
            ->orderByRaw(
                'CASE WHEN status NOT IN (?, ?) AND COALESCE(last_edit, updated_at, created_at) <= ? THEN 0 ELSE 1 END',
                ['Selesai', 'Cancel', now()->subDays(7)],
            );

        match ($sort) {
            'oldest' => $orders->oldest('created_at')->oldest('id'),
            'most' => $orders->orderByDesc('items_sum_qty')
                ->orderByRaw("CASE WHEN {$deadlineSortExpression} IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw($deadlineSortExpression)
                ->oldest('created_at'),
            'remaining_more' => $orders->orderByRaw("CASE WHEN {$deadlineSortExpression} IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw("{$deadlineSortExpression} DESC")
                ->oldest('created_at'),
            default => $orders->orderByRaw("CASE WHEN {$deadlineSortExpression} IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw($deadlineSortExpression)
                ->oldest('created_at'),
        };

        $orders = $orders->oldest('id')->paginate(10)->withQueryString();

        if ($canManageSales) {
            foreach ($orders as $order) {
                $order->synchronizeProductionStatusFromItems($order->items);
            }
        }

        $orderStats = Order::query()->selectRaw(
            'COUNT(*) AS total_orders, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_orders, SUM(CASE WHEN status IN (?, ?, ?, ?, ?, ?) THEN 1 ELSE 0 END) AS active_orders, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_orders',
            ['None', 'None', 'Proses Produksi', 'Finishing', 'Packing', 'Distribusi', 'Service', 'Selesai'],
        )->first();

        if ($canManageSales) {
            $orderStats->total_revenue = Order::query()
                ->where('status', '!=', 'Cancel')
                ->sum('total_order');
        }

        $statusCountsQuery = Order::query()
            ->when($search !== '', fn (Builder $query) => $canManageSales
                ? $this->applySearch($query, $search)
                : $this->applyProductionSearch($query, $search, $productionSearchItemStatuses))
            ->when(
                $factoryWorkflow && ! $canManageSales,
                fn (Builder $query) => $query->whereHas('items', fn (Builder $itemQuery) => $this->applyFactoryItemVisibility($itemQuery)),
            )
            ->when($orderType !== '', fn (Builder $query) => $query->where('order_type', $orderType));
        $statusCounts = $statusCountsQuery
            ->selectRaw('status, COUNT(*) AS status_count')
            ->groupBy('status')
            ->pluck('status_count', 'status');
        $statusTabs = [
            ['value' => '', 'label' => 'Semua', 'class' => 'all'],
            ['value' => 'None', 'label' => 'New', 'class' => 'pending'],
            ['value' => 'Proses Produksi', 'label' => 'Produksi', 'class' => 'processing'],
            ['value' => 'Finishing', 'label' => 'Finishing', 'class' => 'finishing'],
            ['value' => 'Packing', 'label' => 'Packing', 'class' => 'packing'],
            ['value' => 'Distribusi', 'label' => 'Distribusi', 'class' => 'distribution'],
            ['value' => 'Service', 'label' => 'Service', 'class' => 'service'],
            ['value' => 'Selesai', 'label' => 'Selesai', 'class' => 'completed'],
            ['value' => 'Cancel', 'label' => 'Batal', 'class' => 'cancelled'],
        ];

        return view('orders.index', compact(
            'orders',
            'search',
            'sort',
            'sortOptions',
            'orderType',
            'orderTypes',
            'statusFilter',
            'statusOptions',
            'statusCounts',
            'statusTabs',
            'orderStats',
            'canManageSales',
            'factoryWorkflow',
        ))->fragmentIf($request->ajax(), 'order-results');
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'order_type' => ['nullable', Rule::in(['Bali', 'Luar Bali', 'Ekspor'])],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $orderType = $validated['order_type'] ?? null;
        $orders = Order::query()
            ->with(['guest', 'items'])
            ->when($search !== '', fn (Builder $query) => $this->applySearch($query, $search))
            ->when($orderType !== null, fn (Builder $query) => $query->where('order_type', $orderType))
            ->orderBy('id');

        return response()->streamDownload(function () use ($orders): void {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['SPK', 'Nama Tamu', 'Alamat', 'Telepon', 'Sumber Tamu', 'Lokasi Tujuan', 'Produk', 'Total', 'Dibayar', 'Sisa', 'DP (%)', 'Deadline', 'Status', 'Catatan']);

            $orders->chunkById(500, function ($orderBatch) use ($file): void {
                foreach ($orderBatch as $order) {
                    $products = $order->items
                        ->map(fn ($item): string => $item->product.' x'.$item->qty)
                        ->implode('; ');

                    fputcsv($file, [
                        $this->safeCsvValue($order->order_id),
                        $this->safeCsvValue($order->guest_name),
                        $this->safeCsvValue($order->guest?->address),
                        $this->safeCsvValue($order->phone),
                        $this->safeCsvValue($order->guest_source),
                        $this->safeCsvValue($order->order_type),
                        $this->safeCsvValue($products),
                        $order->total_order,
                        $order->paid_amount,
                        $order->amount_remaining,
                        $order->paid_percentage,
                        $order->deadline?->format('Y-m-d'),
                        $this->safeCsvValue($order->status),
                        $this->safeCsvValue($order->note),
                    ]);
                }
            });

            fclose($file);
        }, 'daftar-order.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create()
    {
        $categories = Category::orderBy('name')->get();
        $colors = Color::orderBy('name')->pluck('name');
        $guests = $this->guestOptions();
        $productOptions = $this->productOptions();
        $catalogProducts = $this->catalogProductOptions();

        return view('orders.create', compact('categories', 'colors', 'guests', 'productOptions', 'catalogProducts'));
    }

    public function checkOrderId(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|string|max:255',
        ]);

        $isAvailable = ! Order::where('order_id', $validated['order_id'])->exists();

        return response()->json([
            'available' => $isAvailable,
            'message' => $isAvailable ? null : 'SPK sudah digunakan.',
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateOrder($request);
        $validated['paid_amount'] = $this->validatedPaidAmount($validated);
        $catalogProducts = $this->catalogProductsForItems($validated['items']);

        DB::transaction(function () use ($request, $validated, $catalogProducts) {
            $guest = $this->resolveGuest($validated);
            $guest->update(['guest_source' => $validated['guest_source']]);

            $total = 0;

            $order = Order::create([
                'order_id' => $validated['order_id'],
                'guest_id' => $guest->id,
                'guest_name' => $guest->name,
                'guest_source' => $validated['guest_source'],
                'order_type' => $validated['order_type'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'total_order' => 0,
                'paid_amount' => $validated['paid_amount'],
                'deadline' => $validated['deadline'] ?? null,
                'last_edit' => now(),
                'status' => 'None',
                'note' => $validated['note'] ?? null,
            ]);

            $this->syncGuestAddress($guest, $validated);
            $this->rememberGuestPhone($guest->id, $validated['phone'] ?? null);

            foreach ($request->items as $index => $item) {
                $catalogProduct = $this->resolveCatalogProduct($item, $index, $catalogProducts);
                if ($catalogProduct === null) {
                    $catalogProduct = $this->createCatalogProduct($item);
                    $catalogProducts->put($catalogProduct->id, $catalogProduct);
                }

                $imagePath = null;

                if ($request->hasFile("items.$index.image")) {
                    $imagePath = $request
                        ->file("items.$index.image")
                        ->store('orders/products', 'public');
                } elseif ($catalogProduct !== null) {
                    $imagePath = $this->copyCatalogImage($catalogProduct);
                }

                $subtotal = $item['qty'] * $item['price'];

                $total += $subtotal;

                if ($catalogProduct !== null && (float) $item['price'] < (float) $catalogProduct->price) {
                    $catalogProduct->update(['price' => $item['price']]);
                }

                $statusImagePaths = [];
                if ($catalogProduct instanceof Product && $catalogProduct->mockupPaths() !== []) {
                    $statusImagePaths = $this->copyCatalogMockups($catalogProduct);
                }
                $hasCatalogMockup = $statusImagePaths !== [];
                $initialStatus = $item['source'] === 'Stock'
                    ? 'Distribusi'
                    : ($hasCatalogMockup ? 'Produksi' : 'None');

                $order->items()->create([
                    'product' => $item['product'],
                    'product_id' => $catalogProduct?->id,
                    'sku' => $this->orderItemSku($item, $catalogProduct),
                    'category_id' => $item['category_id'] ?? $catalogProduct?->category_id,
                    'qty' => $item['qty'],
                    'price' => $item['price'],
                    'dimensions' => $item['dimensions'] ?? null,
                    'color' => $this->catalogColor($item, $catalogProduct),
                    'source' => $item['source'],
                    'status' => $initialStatus,
                    'factory_status' => 'None',
                    'image' => $imagePath,
                    'status_image' => $statusImagePaths[0] ?? null,
                    'status_images' => $statusImagePaths ?: null,
                ]);
            }

            $order->update([
                'total_order' => $total,
            ]);
            $order->synchronizeProductionStatusFromItems();
        });

        return redirect()
            ->route('orders.index')
            ->with('success', 'Order berhasil dibuat.');
    }

    public function show(Order $order)
    {
        $user = request()->user();

        $salesWorkflow = $user->isSalesWorkflow();
        $factoryWorkflow = $user->isFactoryWorkflow();
        $canAssignSubcontractors = $factoryWorkflow && $user->canAccessMenu('subcontractors');
        $canAssignPiecework = $factoryWorkflow && $user->canAccessMenu('order_products');

        if ($salesWorkflow) {
            $order->load([
                'items' => fn ($query) => $query
                    ->withSum('activeSubcontractorAssignments as active_subcontracted_quantity', 'assigned_quantity')
                    ->withSum('activeSubcontractorAssignments as active_subcontractor_completed_quantity', 'qty_completed')
                    ->orderByRaw("CASE WHEN status = 'Distribusi' THEN 1 ELSE 0 END")
                    ->orderBy('id'),
                'items.category',
                'items.services',
                'guest',
                'items.pieceworkAssignments' => fn ($query) => $query
                    ->with('employee')
                    ->withSum('pieceworkItems as produced_quantity', 'quantity'),
                'pendingDeadlineRequestedBy:id,name',
                'events',
            ]);
            $order->synchronizeProductionStatusFromItems($order->items);
        } else {
            abort_unless($factoryWorkflow, 403);
            $hasVisibleFactoryItems = $this->applyFactoryItemVisibility($order->items()->getQuery())->exists();
            abort_unless($hasVisibleFactoryItems || $order->pending_deadline !== null, 404);
            $order = Order::query()
                ->select([
                    'id',
                    'order_id',
                    'status',
                    'deadline',
                    'factory_deadline',
                    'factory_started_at',
                    'pending_deadline',
                    'pending_deadline_reason',
                    'pending_deadline_requested_by',
                    'pending_deadline_requested_at',
                    'last_edit',
                ])
                ->findOrFail($order->id);
            $order->load([
                'items' => fn ($query) => $this->applyFactoryItemVisibility($query->getQuery())
                    ->select(
                        'id',
                        'order_id',
                        'product',
                        'category_id',
                        'dimensions',
                        'qty',
                        'qty_completed',
                        'factory_status',
                        'factory_drawing_image',
                        'packed_quantity',
                        'quality_good_quantity',
                        'quality_damaged_quantity',
                        'quality_short_quantity',
                        'color',
                        'source',
                        'status',
                        'image',
                        'status_image',
                        'status_images',
                    )
                    ->withSum('activeSubcontractorAssignments as active_subcontracted_quantity', 'assigned_quantity')
                    ->withSum('activeSubcontractorAssignments as active_subcontractor_completed_quantity', 'qty_completed')
                    ->orderByRaw("CASE WHEN status = 'Distribusi' THEN 1 ELSE 0 END")
                    ->orderBy('id'),
                'items.category',
                'items.services',
                'items.pieceworkItems.employee',
                'items.pieceworkItems.attendance.employee',
                'items.pieceworkAssignments' => fn ($query) => $query
                    ->with('employee')
                    ->withSum('pieceworkItems as produced_quantity', 'quantity'),
                'items.activeSubcontractorAssignments.subcontractor',
                'pendingDeadlineRequestedBy:id,name',
                'events',
            ]);
        }

        $subcontractors = $canAssignSubcontractors
            ? Subcontractor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();
        $factoryEmployees = $canAssignPiecework
            ? Employee::query()
                ->where('business_unit', User::UNIT_FACTORY)
                ->where('status', 'Aktif')
                ->where('employee_type', 'Borongan')
                ->orderBy('full_name')
                ->get(['id', 'full_name'])
            : collect();

        if ($salesWorkflow) {
            $order->load([
                'items.pieceworkItems.employee',
                'items.pieceworkItems.attendance.employee',
                'items.pieceworkAssignments' => fn ($query) => $query
                    ->with('employee')
                    ->withSum('pieceworkItems as produced_quantity', 'quantity'),
                'items.activeSubcontractorAssignments.subcontractor',
            ]);
        }

        OrderCommentRecipient::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereHas('comment', fn (Builder $query) => $query->where('order_id', $order->id))
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        $order->load([
            'comments' => fn ($query) => $query
                ->whereNull('parent_id')
                ->with('author')
                ->withCount(['readRecipients', 'replies']),
            'comments.replies' => fn ($query) => $query
                ->with('author')
                ->withCount('readRecipients'),
        ]);

        $productStatusCounts = [
            'Selesai' => $order->items->sum(fn (OrderItem $item): int => $item->completed_quantity),
            'Produksi' => $order->items->sum(fn (OrderItem $item): int => $item->production_quantity),
            'Service' => $order->items->sum(fn (OrderItem $item): int => $item->service_quantity),
        ];
        $canReviewDeadlineChange = $factoryWorkflow && $order->pending_deadline !== null;

        return view('orders.show', compact(
            'order',
            'salesWorkflow',
            'factoryWorkflow',
            'canAssignSubcontractors',
            'canAssignPiecework',
            'productStatusCounts',
            'canReviewDeadlineChange',
            'factoryEmployees',
            'subcontractors',
        ));
    }

    public function updateProduction(Request $request, Order $order)
    {
        $user = $request->user();
        $factoryWorkflow = $user->isFactoryWorkflow();
        $salesWorkflow = $user->isSalesWorkflow();
        abort_unless($salesWorkflow && ! $factoryWorkflow, 403);

        $itemStatuses = ['None', 'Produksi'];

        $validated = $request->validate([
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.status' => ['required', Rule::in($itemStatuses)],
            'items.*.comment' => ['nullable', 'string', 'max:2000'],
            'items.*.status_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'items.*.status_images' => ['nullable', 'array', 'min:1', 'max:10'],
            'items.*.status_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if (empty($validated['items'])) {
            throw ValidationException::withMessages([
                'items' => 'Pilih perubahan produk terlebih dahulu.',
            ]);
        }

        DB::transaction(function () use ($request, $validated, $order): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status === 'Cancel') {
                throw ValidationException::withMessages([
                    'items' => 'Order yang dibatalkan tidak dapat diubah.',
                ]);
            }

            foreach ($validated['items'] ?? [] as $itemIndex => $itemData) {
                $item = $order->items()->lockForUpdate()->findOrFail($itemData['id']);
                $previousOverallStatus = $item->status;
                $previousStatus = $item->status;
                $nextStatus = $itemData['status'];
                $statusPhotos = $this->statusPhotosFromRequest($request, "items.$itemIndex");

                if ($item->source === 'Stock' && ($nextStatus !== $previousStatus || $statusPhotos !== [])) {
                    throw ValidationException::withMessages([
                        "items.{$itemIndex}.status" => 'Status produk Stock hanya dapat diperbarui melalui pemeriksaan kualitas PB.',
                    ]);
                }

                if ($previousStatus !== 'None'
                    && ($nextStatus !== $previousStatus || $statusPhotos !== [])) {
                    throw ValidationException::withMessages([
                        "items.{$itemIndex}.status" => 'Status dan mockup produk tidak dapat diubah setelah produk masuk Produksi.',
                    ]);
                }

                if ($previousStatus === 'None' && $nextStatus === 'None' && $statusPhotos !== []) {
                    $nextStatus = 'Produksi';
                }

                if (
                    in_array($nextStatus, OrderItem::STATUSES_REQUIRING_PHOTO, true)
                    && $nextStatus !== $previousStatus
                    && $statusPhotos === []
                ) {
                    throw ValidationException::withMessages([
                        "items.{$itemIndex}.status_images" => 'Mockup produk wajib diunggah saat status diubah ke Produksi atau Distribusi.',
                    ]);
                }

                if ($previousStatus === $nextStatus && $statusPhotos === []) {
                    continue;
                }

                $statusImagePaths = $item->status_evidence_images;
                if ($statusPhotos !== []) {
                    $this->deleteStatusImages($statusImagePaths);
                    $statusImagePaths = $this->storeStatusPhotos($statusPhotos);
                }
                $catalogProduct = $item->catalogProduct;
                if (
                    $nextStatus === 'Produksi'
                    && $statusPhotos !== []
                    && $item->source !== 'Stock'
                    && $catalogProduct !== null
                ) {
                    $this->replaceCatalogMockups($catalogProduct, $statusPhotos);
                }
                $statusImagePath = $statusImagePaths[0] ?? null;

                if ($nextStatus === 'Produksi' && $previousStatus !== 'Produksi') {
                    $item->qty_progress_updated_at = now();
                }

                $item->update([
                    'status' => $nextStatus,
                    'factory_status' => $item->activeSubcontractorAssignments()->exists()
                        ? $item->factory_status
                        : ($nextStatus === 'Produksi'
                            && $item->source !== 'Stock'
                            && blank($item->factory_drawing_image)
                                ? 'None'
                                : $nextStatus),
                    'status_image' => $statusImagePath,
                    'status_images' => $statusImagePaths ?: null,
                ]);
                $item->refresh();
                $order->update(['last_edit' => now()]);
                $currentWorkflowStatus = $item->status;
                if ($previousStatus !== $currentWorkflowStatus) {
                    $this->recordOrderEvent(
                        $order,
                        'item_status_changed',
                        'Status produk berubah',
                        "{$order->order_id} - {$item->product}: {$previousStatus} -> {$currentWorkflowStatus}",
                        $item->id,
                    );
                }

                if ($previousOverallStatus !== $item->status) {
                    $this->recordOrderEvent(
                        $order,
                        'item_status_changed',
                        'Status keseluruhan produk berubah',
                        "{$order->order_id} - {$item->product}: {$previousOverallStatus} -> {$item->status}",
                        $item->id,
                    );
                }

            }

            $order->synchronizeProductionStatusFromItems();
        });

        return back()->with('success', 'Status produksi berhasil diperbarui.');
    }

    public function edit(Order $order)
    {
        $order->load(['items.category', 'items.catalogProduct', 'guest']);
        $order->synchronizeProductionStatusFromItems($order->items);
        $hasProductionWorkItems = $order->items->contains(
            fn (OrderItem $item): bool => in_array('Produksi', [$item->status, $item->factory_status], true),
        );

        $categories = Category::orderBy('name')->get();
        $colors = Color::orderBy('name')->pluck('name');
        $existingColors = $order->items->pluck('color')->filter()->unique();
        $colors = $colors->merge($existingColors)->unique()->sort()->values();
        $guests = $this->guestOptions();
        $productOptions = $this->productOptions();
        $catalogProducts = $this->catalogProductOptions();

        return view('orders.edit', compact(
            'order',
            'categories',
            'colors',
            'guests',
            'productOptions',
            'catalogProducts',
            'hasProductionWorkItems',
        ));
    }

    public function update(Request $request, Order $order)
    {
        $validated = $this->validateOrder($request, $order);
        $validated['paid_amount'] = $this->validatedPaidAmount($validated, $order);
        $catalogProducts = $this->catalogProductsForItems($validated['items']);

        DB::transaction(function () use ($request, $validated, $order, $catalogProducts) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $guest = $this->resolveGuest($validated);
            $guest->update(['guest_source' => $validated['guest_source']]);

            $total = 0;
            $previousDeadline = $order->deadline?->toDateString();
            $previousPendingDeadline = $order->pending_deadline?->toDateString();
            $previousItems = $order->items()
                ->with('services:id,order_item_id')
                ->get()
                ->keyBy('id');
            $newDeadline = $validated['deadline'] ?? null;
            $deadlineChanged = $previousDeadline !== $newDeadline;
            $hasProductionWorkItems = $previousItems->contains(
                fn (OrderItem $item): bool => in_array('Produksi', [$item->status, $item->factory_status], true),
            );
            $requiresDeadlineApproval = $deadlineChanged
                && $previousDeadline !== null
                && $newDeadline !== null
                && $newDeadline < $previousDeadline
                && $hasProductionWorkItems;

            if ($requiresDeadlineApproval && blank($validated['deadline_change_reason'] ?? null)) {
                throw ValidationException::withMessages([
                    'deadline_change_reason' => 'Alasan wajib diisi untuk pengajuan percepatan deadline.',
                ]);
            }

            $submittedItemIds = collect($validated['items'])
                ->pluck('id')
                ->filter()
                ->map(fn ($itemId): int => (int) $itemId)
                ->all();
            $completedItemWouldBeRemoved = $order->items()
                ->where(function (Builder $query): void {
                    $query->where('qty_completed', '>', 0)
                        ->orWhereIn('status', ['Produksi', 'Proses Kirim', 'Distribusi', 'Finishing', 'Dalam Perjalanan', 'Packing', 'Ready'])
                        ->orWhereIn('factory_status', ['Produksi', 'Proses Kirim', 'Distribusi', 'Finishing', 'Packing', 'Ready'])
                        ->orWhereHas('activeSubcontractorAssignments')
                        ->orWhereHas('services');
                })
                ->when($submittedItemIds !== [], fn (Builder $query): Builder => $query->whereNotIn('id', $submittedItemIds))
                ->lockForUpdate()
                ->exists();

            if ($completedItemWouldBeRemoved) {
                throw ValidationException::withMessages([
                    'items' => 'Produk yang sudah memiliki progres tidak dapat dihapus dari order.',
                ]);
            }

            $lockedStatuses = ['Produksi', 'Proses Kirim', 'Distribusi', 'Finishing', 'Dalam Perjalanan', 'Packing', 'Ready'];
            foreach ($validated['items'] as $index => $submittedItem) {
                if (blank($submittedItem['id'] ?? null)) {
                    continue;
                }

                $previousItem = $previousItems->get((int) $submittedItem['id']);
                if (! $previousItem || (
                    ! in_array($previousItem->status, $lockedStatuses, true)
                    && ! in_array($previousItem->factory_status, $lockedStatuses, true)
                    && (int) $previousItem->qty_completed === 0
                    && $previousItem->services->isEmpty()
                )) {
                    continue;
                }

                $changedFields = [
                    'product' => $submittedItem['product'] !== $previousItem->product,
                    'qty' => (int) $submittedItem['qty'] !== (int) $previousItem->qty,
                    'price' => round((float) $submittedItem['price'], 2) !== round((float) $previousItem->price, 2),
                    'color' => ($submittedItem['color'] ?? null) !== $previousItem->color,
                    'source' => $submittedItem['source'] !== $previousItem->source,
                    'status' => $submittedItem['status'] !== $previousItem->status,
                    'category_id' => array_key_exists('category_id', $submittedItem)
                        && (int) ($submittedItem['category_id'] ?? 0) !== (int) ($previousItem->category_id ?? 0),
                    'product_id' => array_key_exists('product_id', $submittedItem)
                        && (int) ($submittedItem['product_id'] ?? 0) !== (int) ($previousItem->product_id ?? 0),
                    'dimensions' => array_key_exists('dimensions', $submittedItem)
                        && $submittedItem['dimensions'] !== $previousItem->dimensions,
                    'image' => $request->hasFile("items.{$index}.image"),
                    'status_images' => $this->statusPhotosFromRequest($request, "items.{$index}") !== [],
                ];
                $changedField = array_search(true, $changedFields, true);

                if ($changedField !== false) {
                    throw ValidationException::withMessages([
                        "items.{$index}.{$changedField}" => 'Produk tidak dapat diubah setelah masuk Produksi.',
                    ]);
                }
            }

            $deadlineAttributes = [
                'deadline' => $requiresDeadlineApproval ? $previousDeadline : $newDeadline,
            ];
            if ($requiresDeadlineApproval) {
                $deadlineAttributes = array_merge($deadlineAttributes, [
                    'pending_deadline' => $newDeadline,
                    'pending_deadline_reason' => trim($validated['deadline_change_reason']),
                    'pending_deadline_requested_by' => $request->user()->id,
                    'pending_deadline_requested_at' => now(),
                ]);
            } elseif ($deadlineChanged) {
                $deadlineAttributes = array_merge($deadlineAttributes, [
                    'pending_deadline' => null,
                    'pending_deadline_reason' => null,
                    'pending_deadline_requested_by' => null,
                    'pending_deadline_requested_at' => null,
                ]);
            }

            $order->update(array_merge([
                'order_id' => $validated['order_id'],
                'guest_id' => $guest->id,
                'guest_name' => $guest->name,
                'guest_source' => $validated['guest_source'],
                'order_type' => $validated['order_type'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'paid_amount' => $validated['paid_amount'],
                'last_edit' => now(),
                'note' => $validated['note'] ?? null,
            ], $deadlineAttributes));

            if ($requiresDeadlineApproval) {
                $this->recordOrderEvent(
                    $order,
                    'deadline_change_requested',
                    $previousPendingDeadline === null
                        ? 'Percepatan deadline diajukan'
                        : 'Pengajuan percepatan deadline diperbarui',
                    "{$order->order_id}: {$previousDeadline} -> {$newDeadline}. "
                        .'Alasan: '.$validated['deadline_change_reason'],
                );
            } elseif ($deadlineChanged) {
                if ($previousPendingDeadline !== null) {
                    $this->recordOrderEvent(
                        $order,
                        'deadline_change_cancelled',
                        'Pengajuan percepatan deadline dibatalkan',
                        "{$order->order_id}: pengajuan {$previousPendingDeadline} dibatalkan karena deadline aktif diubah.",
                    );
                }

                $this->recordOrderEvent(
                    $order,
                    'deadline_changed',
                    'Target pengiriman diubah',
                    "{$order->order_id}: ".($previousDeadline ?? 'Belum ada').' -> '.($newDeadline ?? 'Dihapus'),
                );
            }

            $this->syncGuestAddress($guest, $validated);
            $this->rememberGuestPhone($guest->id, $validated['phone'] ?? null);

            $existingItemIds = [];
            $hasNewFactoryWorkItems = false;

            foreach ($request->items as $index => $item) {
                $itemId = $item['id'] ?? null;
                $previousItem = filled($itemId)
                    ? $previousItems->get((int) $itemId)
                    : null;
                if (blank($item['dimensions'] ?? null)) {
                    $item['dimensions'] = $previousItem?->dimensions;
                }

                $catalogProduct = $this->resolveCatalogProduct($item, $index, $catalogProducts);
                if (blank($item['dimensions'] ?? null)) {
                    $item['dimensions'] = $catalogProduct?->dimensions;
                }

                if ($catalogProduct === null) {
                    $catalogProduct = $this->createCatalogProduct($item);
                    $catalogProducts->put($catalogProduct->id, $catalogProduct);
                }

                $subtotal = $item['qty'] * $item['price'];

                $total += $subtotal;

                if ($itemId) {

                    $orderItem = $order->items()->findOrFail($itemId);
                    $isLockedProduct = $previousItem !== null && (
                        in_array($previousItem->status, $lockedStatuses, true)
                        || in_array($previousItem->factory_status, $lockedStatuses, true)
                        || (int) $previousItem->qty_completed > 0
                    );

                    if ($isLockedProduct) {
                        $existingItemIds[] = $orderItem->id;

                        continue;
                    }

                    $reworkComment = trim((string) ($item['comment'] ?? ''));
                    $isReturningToProduction = $previousItem?->status === 'Distribusi'
                        && $item['status'] === 'Produksi';

                    if ($isReturningToProduction && $reworkComment === '') {
                        throw ValidationException::withMessages([
                            "items.$index.comment" => 'Komentar wajib diisi saat produk dikembalikan ke Produksi.',
                        ]);
                    }

                    if ((int) $item['qty'] < (int) $orderItem->qty_completed) {
                        throw ValidationException::withMessages([
                            "items.$index.qty" => 'Jumlah produk tidak boleh kurang dari jumlah yang sudah dikerjakan.',
                        ]);
                    }

                    $hasActiveSubcontractorAssignments = $orderItem->activeSubcontractorAssignments()->exists();
                    if ((int) $item['qty'] < $orderItem->activeSubcontractedQuantity() + (int) $orderItem->qty_completed) {
                        throw ValidationException::withMessages([
                            "items.$index.qty" => 'Jumlah produk tidak boleh kurang dari jumlah yang sudah dikerjakan atau dialokasikan ke subcon.',
                        ]);
                    }

                    if (
                        $hasActiveSubcontractorAssignments
                        && $item['status'] !== $orderItem->status
                        && ! ($item['status'] === 'Ready' && $orderItem->status === 'Distribusi')
                    ) {
                        throw ValidationException::withMessages([
                            "items.$index.status" => 'Status keseluruhan produk mengikuti progres Factory dan subcon.',
                        ]);
                    }

                    if ($hasActiveSubcontractorAssignments && $item['status'] === 'Ready') {
                        $allAssignmentsDistributed = $orderItem->activeSubcontractorAssignments()
                            ->where('status', '!=', 'Distribusi')
                            ->doesntExist();
                        $factoryWorkDistributed = $orderItem->factory_quantity === 0
                            || $orderItem->factory_status === 'Distribusi';

                        if (! $allAssignmentsDistributed || ! $factoryWorkDistributed) {
                            throw ValidationException::withMessages([
                                "items.$index.status" => 'Produk hanya dapat berstatus Ready setelah seluruh pekerjaan Factory dan subcon selesai.',
                            ]);
                        }
                    }

                    $imagePath = $orderItem->image;
                    $catalogProductChanged = (int) ($orderItem->product_id ?? 0) !== (int) ($catalogProduct?->id ?? 0);

                    if ($request->hasFile("items.$index.image")) {

                        if ($imagePath) {
                            Storage::disk('public')->delete($imagePath);
                        }

                        $imagePath = $request
                            ->file("items.$index.image")
                            ->store('orders/products', 'public');
                    } elseif ($catalogProductChanged) {
                        if ($imagePath) {
                            Storage::disk('public')->delete($imagePath);
                        }

                        $imagePath = $catalogProduct !== null
                            ? $this->copyCatalogImage($catalogProduct)
                            : null;
                    }

                    $statusImagePaths = $orderItem->status_evidence_images;
                    $statusPhotos = $this->statusPhotosFromRequest($request, "items.$index");
                    if ($statusPhotos !== []) {
                        $this->deleteStatusImages($statusImagePaths);
                        $statusImagePaths = $this->storeStatusPhotos($statusPhotos);
                    } elseif ($catalogProductChanged) {
                        $this->deleteStatusImages($statusImagePaths);
                        $statusImagePaths = [];
                    }
                    if (
                        $statusImagePaths === []
                        && $catalogProduct instanceof Product
                        && $catalogProduct->mockupPaths() !== []
                    ) {
                        $statusImagePaths = $this->copyCatalogMockups($catalogProduct);
                    }
                    $statusImagePath = $statusImagePaths[0] ?? null;
                    $itemStatus = $statusImagePaths !== []
                        && $item['status'] === 'None'
                        && $item['source'] !== 'Stock'
                        ? 'Produksi'
                        : $item['status'];
                    if ($item['source'] === 'Stock' && $itemStatus === 'None') {
                        $itemStatus = 'Distribusi';
                    }
                    if (
                        $statusPhotos !== []
                        && $itemStatus === 'Produksi'
                        && $item['source'] !== 'Stock'
                        && $catalogProduct !== null
                    ) {
                        $this->replaceCatalogMockups($catalogProduct, $statusPhotos);
                    }

                    $previousCompleted = (int) $orderItem->qty_completed;
                    $completedQuantity = $isReturningToProduction ? 0 : $previousCompleted;
                    if (
                        $item['status'] === 'Produksi'
                        && $orderItem->status !== 'Produksi'
                        && $previousCompleted === $completedQuantity
                    ) {
                        $orderItem->qty_progress_updated_at = now();
                    }

                    $orderItem->update([
                        'product' => $item['product'],
                        'product_id' => $catalogProduct?->id,
                        'sku' => array_key_exists('sku', $item)
                            ? $this->orderItemSku($item, $catalogProduct)
                            : ($orderItem->sku ?? $catalogProduct?->sku),
                        'category_id' => $item['category_id'] ?? $catalogProduct?->category_id,
                        'qty' => $item['qty'],
                        'price' => $item['price'],
                        'dimensions' => $item['dimensions'] ?? $orderItem->dimensions,
                        'color' => $this->catalogColor($item, $catalogProduct),
                        'source' => $item['source'],
                        'status' => $hasActiveSubcontractorAssignments ? $orderItem->status : $itemStatus,
                        'factory_status' => $hasActiveSubcontractorAssignments
                            ? ($itemStatus === 'Ready' ? 'Ready' : $orderItem->factory_status)
                            : ($item['source'] === 'Stock' && $itemStatus === 'Distribusi'
                                ? 'None'
                                : ($itemStatus === 'Produksi' && blank($orderItem->factory_drawing_image)
                                    ? 'None'
                                    : $itemStatus)),
                        'qty_completed' => $completedQuantity,
                        'image' => $imagePath,
                        'status_image' => $statusImagePath,
                        'status_images' => $statusImagePaths ?: null,
                    ]);
                    if ($hasActiveSubcontractorAssignments && $item['status'] === 'Ready') {
                        $orderItem->update(['status' => 'Ready']);
                    } elseif ($hasActiveSubcontractorAssignments) {
                        $orderItem->synchronizeStatusFromWorkAllocations();
                    }

                    if ($catalogProduct !== null && (float) $item['price'] < (float) $catalogProduct->price) {
                        $catalogProduct->update(['price' => $item['price']]);
                    }

                    if ($previousItem && $previousItem->status !== $orderItem->status) {
                        $this->recordOrderEvent(
                            $order,
                            'item_status_changed',
                            'Status produk berubah',
                            "{$order->order_id} - {$previousItem->product}: {$previousItem->status} -> {$orderItem->status}",
                            $orderItem->id,
                        );
                    }

                    if ($isReturningToProduction) {
                        $this->recordOrderEvent(
                            $order,
                            'item_rework_requested',
                            'Produk dikembalikan ke Produksi',
                            $reworkComment,
                            $orderItem->id,
                        );
                    }

                    $existingItemIds[] = $orderItem->id;

                } else {

                    $imagePath = null;

                    if ($request->hasFile("items.$index.image")) {
                        $imagePath = $request
                            ->file("items.$index.image")
                            ->store('orders/products', 'public');
                    } elseif ($catalogProduct !== null) {
                        $imagePath = $this->copyCatalogImage($catalogProduct);
                    }

                    if ($catalogProduct !== null && (float) $item['price'] < (float) $catalogProduct->price) {
                        $catalogProduct->update(['price' => $item['price']]);
                    }
                    $statusImagePaths = [];
                    if ($catalogProduct instanceof Product && $catalogProduct->mockupPaths() !== []) {
                        $statusImagePaths = $this->copyCatalogMockups($catalogProduct);
                    }
                    $hasCatalogMockup = $statusImagePaths !== [];
                    $initialStatus = $item['source'] === 'Stock'
                        ? 'Distribusi'
                        : ($hasCatalogMockup ? 'Produksi' : 'None');
                    $hasNewFactoryWorkItems = $hasNewFactoryWorkItems || $item['source'] !== 'Stock';
                    $newItem = $order->items()->create([
                        'product' => $item['product'],
                        'product_id' => $catalogProduct?->id,
                        'sku' => $this->orderItemSku($item, $catalogProduct),
                        'category_id' => $item['category_id'] ?? $catalogProduct?->category_id,
                        'qty' => $item['qty'],
                        'price' => $item['price'],
                        'dimensions' => $item['dimensions'] ?? null,
                        'color' => $this->catalogColor($item, $catalogProduct),
                        'source' => $item['source'],
                        'status' => $initialStatus,
                        'factory_status' => 'None',
                        'image' => $imagePath,
                        'status_image' => $statusImagePaths[0] ?? null,
                        'status_images' => $statusImagePaths ?: null,
                    ]);

                    $this->recordOrderEvent(
                        $order,
                        'item_added',
                        'Produk ditambahkan',
                        "{$order->order_id} - {$newItem->product} (status awal: {$initialStatus})",
                        $newItem->id,
                    );

                    $existingItemIds[] = $newItem->id;
                }
            }

            $itemsToDelete = $order->items()
                ->whereNotIn('id', $existingItemIds)
                ->get();

            foreach ($itemsToDelete as $item) {

                if ($item->image) {
                    Storage::disk('public')->delete($item->image);
                }
                $this->deleteStatusImages($item->status_evidence_images);

                $item->delete();
            }

            $order->update([
                'total_order' => $total,
            ]);

            if ($hasNewFactoryWorkItems && ! in_array($order->status, ['None', 'Cancel'], true)) {
                $previousStatus = $order->status;
                $order->update([
                    'status' => 'None',
                    'last_edit' => now(),
                ]);
                $this->recordOrderEvent(
                    $order,
                    'order_status_changed',
                    'Status order berubah otomatis',
                    "{$order->order_id}: {$previousStatus} -> None (menunggu mockup PB dan gambar kerja PF untuk produk baru)",
                );
            } else {
                $order->synchronizeProductionStatusFromItems();
            }
        });

        return redirect()
            ->route('orders.index')
            ->with('success', 'Order berhasil diperbarui.');
    }

    public function approveDeadlineChange(Request $request, Order $order): RedirectResponse
    {
        abort_unless($request->user()->isFactoryWorkflow(), 403);

        DB::transaction(function () use ($order): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $pendingDeadline = $order->pending_deadline;

            if ($pendingDeadline === null) {
                throw ValidationException::withMessages([
                    'pending_deadline' => 'Tidak ada pengajuan deadline yang menunggu persetujuan.',
                ]);
            }

            if ($pendingDeadline->lessThanOrEqualTo(today())) {
                throw ValidationException::withMessages([
                    'pending_deadline' => 'Tanggal pengajuan sudah hari ini atau terlewat. Minta PB mengajukan tanggal baru.',
                ]);
            }

            $pendingReason = $order->pending_deadline_reason;
            $order->update([
                'deadline' => $pendingDeadline->toDateString(),
                'pending_deadline' => null,
                'pending_deadline_reason' => null,
                'pending_deadline_requested_by' => null,
                'pending_deadline_requested_at' => null,
                'last_edit' => now(),
            ]);

            $this->recordOrderEvent(
                $order,
                'deadline_change_approved',
                'Percepatan deadline disetujui PF',
                "{$order->order_id}: deadline diubah menjadi {$pendingDeadline->toDateString()}. "
                    .'Alasan pengajuan: '.($pendingReason ?? '-'),
            );
        });

        return back()->with('success', 'Pengajuan percepatan deadline berhasil disetujui.');
    }

    public function rejectDeadlineChange(Request $request, Order $order): RedirectResponse
    {
        abort_unless($request->user()->isFactoryWorkflow(), 403);

        $validated = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($order, $validated): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $pendingDeadline = $order->pending_deadline?->toDateString();

            if ($pendingDeadline === null) {
                throw ValidationException::withMessages([
                    'pending_deadline' => 'Tidak ada pengajuan deadline yang menunggu persetujuan.',
                ]);
            }

            $reason = $order->pending_deadline_reason;
            $order->update([
                'pending_deadline' => null,
                'pending_deadline_reason' => null,
                'pending_deadline_requested_by' => null,
                'pending_deadline_requested_at' => null,
                'last_edit' => now(),
            ]);

            $description = "{$order->order_id}: pengajuan {$pendingDeadline} ditolak PF. Alasan PB: ".($reason ?? '-');
            if (filled($validated['decision_note'] ?? null)) {
                $description .= ' Catatan PF: '.trim($validated['decision_note']);
            }

            $this->recordOrderEvent(
                $order,
                'deadline_change_rejected',
                'Percepatan deadline ditolak PF',
                $description,
            );
        });

        return back()->with('success', 'Pengajuan percepatan deadline ditolak.');
    }

    public function destroy(Order $order)
    {
        $order->loadMissing('items.activeSubcontractorAssignments');

        if (! $order->canBeDeleted()) {
            return redirect()
                ->route('orders.index')
                ->with('error', 'Order tidak dapat dihapus setelah proses produksi dimulai.');
        }

        foreach ($order->items as $item) {

            if ($item->image) {
                Storage::disk('public')->delete($item->image);
            }
            $this->deleteStatusImages($item->status_evidence_images);
        }

        $order->delete();

        return redirect()
            ->route('orders.index')
            ->with('success', 'Order berhasil dihapus.');
    }

    /**
     * @return list<UploadedFile>
     */
    private function statusPhotosFromRequest(Request $request, string $itemKey): array
    {
        $statusPhotos = $request->file("{$itemKey}.status_images", []);
        $statusPhotos = is_array($statusPhotos) ? $statusPhotos : ($statusPhotos ? [$statusPhotos] : []);
        $legacyStatusPhoto = $request->file("{$itemKey}.status_image");

        if ($legacyStatusPhoto !== null) {
            $statusPhotos[] = $legacyStatusPhoto;
        }

        return array_values(array_filter($statusPhotos));
    }

    /**
     * @param  list<UploadedFile>  $statusPhotos
     * @return list<string>
     */
    private function storeStatusPhotos(array $statusPhotos): array
    {
        return array_values(array_map(
            fn ($statusPhoto): string => $statusPhoto->store('orders/status', 'public'),
            $statusPhotos,
        ));
    }

    /**
     * @param  list<string>  $statusImages
     */
    private function deleteStatusImages(array $statusImages): void
    {
        foreach ($statusImages as $statusImage) {
            Storage::disk('public')->delete($statusImage);
        }
    }

    private function validateOrder(Request $request, ?Order $order = null): array
    {
        $this->normalizeItemPrices($request);
        $this->normalizeItemDimensions($request);
        $this->normalizePaidAmount($request);

        $validated = $request->validate([
            'order_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('orders', 'order_id')->ignore($order),
            ],
            'guest_id' => 'nullable|integer|exists:guests,id',
            'guest_name' => 'required|string|max:255',
            'guest_source' => 'required|string|max:255',
            'guest_source_custom' => ['nullable', 'required_if:guest_source,__custom', 'string', 'max:255'],
            'guest_address' => 'nullable|string|max:2000',
            'order_type' => ['required', Rule::in(['Bali', 'Luar Bali', 'Ekspor'])],
            'phone' => 'nullable|string|max:50',
            'paid_amount' => ['nullable', 'numeric', 'min:0'],

            'deadline' => 'nullable|date|after:today',
            'deadline_change_reason' => ['nullable', 'string', 'max:1000'],

            'note' => 'nullable|string',

            'items' => 'required|array|min:1',

            'items.*.id' => 'nullable|integer',

            'items.*.product' => 'required|string|max:255',

            'items.*.product_id' => [
                'nullable',
                'integer',
                'exists:products,id',
            ],

            'items.*.sku' => ['nullable', 'string', 'max:100'],

            'items.*.dimensions' => ['nullable', 'string', 'max:100', 'regex:'.Product::DIMENSIONS_PATTERN],

            'items.*.category_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
            ],

            'items.*.qty' => 'required|integer|min:1',

            'items.*.price' => 'required|numeric|min:0',

            'items.*.color' => 'nullable|string|max:255',

            'items.*.source' => 'required|string|max:255',

            'items.*.status' => $order === null
                ? ['sometimes', Rule::in(['None'])]
                : ['required', Rule::in(['None', 'Produksi', 'Proses Kirim', 'Distribusi', 'Dalam Perjalanan', 'Finishing', 'Packing', 'Ready'])],

            'items.*.comment' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'items.*.image' => [
                'nullable',
                'image',
                'max:5120',
            ],
            'items.*.status_image' => $order === null
                ? ['prohibited']
                : ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'items.*.status_images' => $order === null
                ? ['prohibited']
                : ['nullable', 'array', 'min:1', 'max:10'],
            'items.*.status_images.*' => $order === null
                ? ['prohibited']
                : ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'order_id.unique' => 'SPK sudah digunakan.',
        ]);
        $this->validateProductSources($validated['items'], $order);

        foreach ($validated['items'] as $index => $item) {
            if (($order === null || blank($item['id'] ?? null)) && blank($item['dimensions'] ?? null)) {
                throw ValidationException::withMessages([
                    "items.{$index}.dimensions" => 'Ukuran produk wajib diisi dengan format P x L x T.',
                ]);
            }
        }

        if ($validated['guest_source'] === '__custom') {
            $validated['guest_source'] = trim($validated['guest_source_custom']);
        }
        unset($validated['guest_source_custom']);

        $existingStatuses = collect();
        if ($order !== null) {
            $itemIds = collect($validated['items'])
                ->pluck('id')
                ->filter()
                ->map(fn ($itemId): int => (int) $itemId);
            $existingStatuses = $order->items()
                ->whereIn('id', $itemIds)
                ->pluck('status', 'id');
        }

        foreach ($validated['items'] as $index => $item) {
            if ($order === null) {
                continue;
            }

            if (blank($item['id'] ?? null)) {
                if ($item['status'] !== 'None') {
                    throw ValidationException::withMessages([
                        "items.{$index}.status" => 'Produk baru harus dimulai dengan status New.',
                    ]);
                }

                if ($this->statusPhotosFromRequest($request, "items.$index") !== []) {
                    throw ValidationException::withMessages([
                        "items.{$index}.status_images" => 'Simpan produk terlebih dahulu sebelum mengunggah mockup.',
                    ]);
                }

                continue;
            }

            $previousStatus = isset($item['id'])
                ? $existingStatuses->get((int) $item['id'])
                : null;

            if (($previousStatus === null || $previousStatus === 'None')
                && ! in_array($item['status'], ['None', 'Produksi'], true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.status" => 'Produk baru hanya dapat berstatus None atau masuk Produksi setelah mockup diunggah.',
                ]);
            }

            if (
                in_array($item['status'], OrderItem::STATUSES_REQUIRING_PHOTO, true)
                && $item['status'] !== $previousStatus
                && $this->statusPhotosFromRequest($request, "items.$index") === []
            ) {
                throw ValidationException::withMessages([
                    "items.{$index}.status_images" => 'Mockup produk wajib diunggah saat status diubah ke Produksi atau Distribusi.',
                ]);
            }
        }

        return $validated;
    }

    private function validateProductSources(array $items, ?Order $order): void
    {
        $itemIds = collect($items)
            ->pluck('id')
            ->filter()
            ->map(fn ($itemId): int => (int) $itemId);
        $existingItems = $order === null
            ? collect()
            : $order->items()->whereIn('id', $itemIds)->get()->keyBy('id');

        foreach ($items as $index => $item) {
            $source = $item['source'];
            $existingItem = $existingItems->get((int) ($item['id'] ?? 0));

            if (in_array($source, ['Stock', 'Order'], true)) {
                $submittedStatus = $item['status'] ?? 'None';
                $unchangedStockStatus = $existingItem?->source === 'Stock'
                    && $existingItem->status === $submittedStatus;

                if ($source === 'Stock' && $submittedStatus !== 'None' && ! $unchangedStockStatus) {
                    throw ValidationException::withMessages([
                        "items.{$index}.status" => 'Produk Stock langsung masuk antrean PB dan tidak dapat diproses di Factory.',
                    ]);
                }

                continue;
            }

            if (
                in_array($source, ['Mix', 'Service'], true)
                && $existingItem?->source === $source
            ) {
                continue;
            }

            throw ValidationException::withMessages([
                "items.{$index}.source" => 'Sumber produk hanya dapat berupa Stock atau Order.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveGuest(array $validated): Guest
    {
        if (! empty($validated['guest_id'])) {
            return Guest::findOrFail($validated['guest_id']);
        }

        $guestName = trim($validated['guest_name']);
        $guest = Guest::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($guestName)])
            ->first();

        return $guest ?? Guest::create(['name' => $guestName]);
    }

    private function validatedPaidAmount(array $validated, ?Order $order = null): float
    {
        $paidAmount = (float) ($validated['paid_amount'] ?? $order?->paid_amount ?? 0);
        $orderTotal = array_sum(array_map(
            fn (array $item): float => (float) $item['qty'] * (float) $item['price'],
            $validated['items'],
        ));

        if ($paidAmount > round($orderTotal, 2)) {
            throw ValidationException::withMessages([
                'paid_amount' => 'Total pembayaran tidak boleh melebihi total order.',
            ]);
        }

        return $paidAmount;
    }

    private function normalizePaidAmount(Request $request): void
    {
        $paidAmount = $request->input('paid_amount');

        if (! is_string($paidAmount)) {
            return;
        }

        $paidAmount = trim($paidAmount);

        if (preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?\z/', $paidAmount) === 1) {
            $request->merge(['paid_amount' => str_replace(',', '', $paidAmount)]);
        }
    }

    private function normalizeItemPrices(Request $request): void
    {
        $items = $request->input('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item) || ! is_string($item['price'] ?? null)) {
                continue;
            }

            $price = trim($item['price']);

            if (preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d+)?\z/', $price) === 1) {
                $items[$index]['price'] = str_replace(',', '', $price);
            }
        }

        $request->merge(['items' => $items]);
    }

    private function normalizeItemDimensions(Request $request): void
    {
        $items = $request->input('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item) || ! is_string($item['dimensions'] ?? null)) {
                continue;
            }

            $items[$index]['dimensions'] = Product::normalizeDimensions($item['dimensions']);
        }

        $request->merge(['items' => $items]);
    }

    private function recordOrderEvent(
        Order $order,
        string $type,
        string $title,
        string $description,
        ?int $orderItemId = null,
    ): void {
        $order->events()->create([
            'order_item_id' => $orderItemId,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'occurred_at' => now(),
        ]);
    }

    private function guestOptions()
    {
        $guests = Guest::query()
            ->with('phones')
            ->orderByRaw('LOWER(name)')
            ->get(['id', 'name', 'guest_source', 'address']);
        $guestsWithoutSource = $guests
            ->filter(fn (Guest $guest): bool => blank($guest->guest_source))
            ->modelKeys();

        $latestOrdersByGuest = Order::query()
            ->whereIn('guest_id', $guests->modelKeys())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['guest_id', 'guest_source', 'phone'])
            ->unique('guest_id')
            ->keyBy('guest_id');
        $latestSourcesByGuest = Order::query()
            ->whereIn('guest_id', $guestsWithoutSource)
            ->whereNotNull('guest_source')
            ->where('guest_source', '!=', '')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['guest_id', 'guest_source'])
            ->unique('guest_id')
            ->keyBy('guest_id');

        return $guests->each(function (Guest $guest) use ($latestOrdersByGuest, $latestSourcesByGuest): void {
            $latestOrder = $latestOrdersByGuest->get($guest->id);

            if (blank($guest->guest_source)) {
                $guest->setAttribute(
                    'guest_source',
                    $latestSourcesByGuest->get($guest->id)?->guest_source,
                );
            }

            $guest->setAttribute('phone', $latestOrder?->phone);
        });
    }

    private function rememberGuestPhone(int $guestId, ?string $phone): void
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return;
        }

        GuestPhone::firstOrCreate([
            'guest_id' => $guestId,
            'phone' => $phone,
        ]);
    }

    /**
     * @return list<array{name: string, sku: string|null, color: string|null, category_id: int|null, category_name: string|null, dimensions: string|null}>
     */
    private function productOptions(): array
    {
        $latestItemIds = OrderItem::query()
            ->selectRaw('MAX(id) AS id')
            ->groupBy('product', 'color')
            ->orderByDesc('id')
            ->pluck('id');
        $latestDimensionItemIds = OrderItem::query()
            ->whereNotNull('dimensions')
            ->where('dimensions', '!=', '')
            ->selectRaw('MAX(id) AS id')
            ->groupBy('product', 'color')
            ->pluck('id');
        $latestDimensions = OrderItem::query()
            ->whereIn('id', $latestDimensionItemIds)
            ->get(['product', 'color', 'dimensions'])
            ->mapWithKeys(fn (OrderItem $item): array => [
                serialize([$item->product, $item->color]) => $item->dimensions,
            ]);

        return OrderItem::query()
            ->with('category:id,name')
            ->whereIn('id', $latestItemIds)
            ->orderByDesc('id')
            ->get()
            ->map(fn (OrderItem $item): array => [
                'name' => $item->product,
                'sku' => $item->sku,
                'color' => $item->color,
                'category_id' => $item->category_id,
                'category_name' => $item->category?->name,
                'dimensions' => $item->dimensions
                    ?: $latestDimensions->get(serialize([$item->product, $item->color])),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, sku: string|null, category_id: int|null, category_name: string|null, price: float, dimensions: string|null, colors: list<string>, mockup_urls: list<string>}>
     */
    private function catalogProductOptions(): array
    {
        return Product::query()
            ->with('category:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category_id' => $product->category_id,
                'category_name' => $product->category?->name,
                'price' => (float) $product->price,
                'dimensions' => $product->dimensions,
                'colors' => array_values(array_filter($product->colors ?? [], 'is_string')),
                'mockup_urls' => array_map(
                    fn (string $mockupPath): string => Storage::disk('public')->url($mockupPath),
                    $product->mockupPaths(),
                ),
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return EloquentCollection<int, Product>
     */
    private function catalogProductsForItems(array $items): EloquentCollection
    {
        $productIds = collect($items)
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values();
        $productNames = collect($items)
            ->pluck('product')
            ->filter()
            ->map(fn (string $name): string => mb_strtolower(trim($name)))
            ->unique()
            ->values();

        return Product::query()
            ->where(function (Builder $query) use ($productIds, $productNames): void {
                if ($productIds->isNotEmpty()) {
                    $query->whereIn('id', $productIds);
                }

                if ($productNames->isNotEmpty()) {
                    $method = $productIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $query->{$method}(DB::raw('LOWER(name)'), $productNames);
                }
            })
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  EloquentCollection<int, Product>  $catalogProducts
     */
    private function resolveCatalogProduct(array $item, int|string $index, EloquentCollection $catalogProducts): ?Product
    {
        $productName = mb_strtolower(trim($item['product']));
        $matchingProducts = $catalogProducts->filter(
            fn (Product $product): bool => mb_strtolower($product->name) === $productName,
        );
        $requestedColor = trim((string) ($item['color'] ?? ''));
        $catalogProduct = null;

        if (filled($item['product_id'] ?? null)) {
            $catalogProduct = $catalogProducts->get((int) $item['product_id']);
            if (! $catalogProduct instanceof Product) {
                throw ValidationException::withMessages([
                    "items.$index.product_id" => 'Produk katalog tidak ditemukan.',
                ]);
            }

            if ($productName !== mb_strtolower($catalogProduct->name)) {
                throw ValidationException::withMessages([
                    "items.$index.product" => 'Nama produk tidak cocok dengan produk katalog yang dipilih.',
                ]);
            }
        } else {
            $catalogProduct = $matchingProducts->first(
                fn (Product $product): bool => $this->catalogProductHasColor($product, $requestedColor),
            ) ?? $matchingProducts->first();
        }

        if (! $catalogProduct instanceof Product || $requestedColor === '') {
            return $catalogProduct;
        }

        $matchingVariant = $matchingProducts->first(
            fn (Product $product): bool => $this->catalogProductHasColor($product, $requestedColor),
        );
        if ($matchingVariant instanceof Product) {
            return $matchingVariant;
        }

        if ($this->catalogProductHasColor($catalogProduct, $requestedColor)) {
            return $catalogProduct;
        }

        $variant = $this->createCatalogVariant($catalogProduct, $item, $requestedColor);
        $catalogProducts->put($variant->id, $variant);

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createCatalogProduct(array $item): Product
    {
        $color = trim((string) ($item['color'] ?? ''));

        return Product::create([
            'name' => trim($item['product']),
            'sku' => $this->normalizeSku($item),
            'category_id' => $item['category_id'] ?? null,
            'price' => $item['price'],
            'dimensions' => $item['dimensions'] ?? null,
            'colors' => $color === '' ? [] : [$color],
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createCatalogVariant(Product $catalogProduct, array $item, string $color): Product
    {
        return Product::create([
            'name' => $catalogProduct->name,
            'sku' => $this->normalizeSku($item),
            'category_id' => $item['category_id'] ?? $catalogProduct->category_id,
            'price' => $item['price'],
            'dimensions' => filled($item['dimensions'] ?? null)
                ? $item['dimensions']
                : $catalogProduct->dimensions,
            'colors' => [$color],
            'image' => null,
            'mockup' => null,
            'mockups' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function orderItemSku(array $item, ?Product $catalogProduct): ?string
    {
        return $this->normalizeSku($item) ?? $catalogProduct?->sku;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function normalizeSku(array $item): ?string
    {
        $sku = trim((string) ($item['sku'] ?? ''));

        return $sku === '' ? null : $sku;
    }

    private function catalogProductHasColor(Product $product, string $color): bool
    {
        $normalizedColor = mb_strtolower(trim($color));
        if ($normalizedColor === '') {
            return false;
        }

        foreach ($product->colors ?? [] as $productColor) {
            if (is_string($productColor) && mb_strtolower(trim($productColor)) === $normalizedColor) {
                return true;
            }
        }

        return false;
    }

    private function catalogColor(array $item, ?Product $catalogProduct): ?string
    {
        $color = trim((string) ($item['color'] ?? ''));
        if ($color !== '') {
            return $color;
        }

        $colors = $catalogProduct?->colors ?? [];

        return count($colors) === 1 ? $colors[0] : null;
    }

    private function copyCatalogImage(Product $product): ?string
    {
        return $this->copyCatalogAsset(
            $product->image,
            'Foto produk katalog tidak ditemukan di penyimpanan.',
            'Foto produk katalog gagal disalin ke order.',
        );
    }

    /**
     * @return list<string>
     */
    private function copyCatalogMockups(Product $product): array
    {
        $mockupPaths = [];

        foreach ($product->mockupPaths() as $mockupPath) {
            $orderMockupPath = $this->copyCatalogAsset(
                $mockupPath,
                'Mockup produk katalog tidak ditemukan di penyimpanan.',
                'Mockup produk katalog gagal disalin ke order.',
            );
            if ($orderMockupPath !== null) {
                $mockupPaths[] = $orderMockupPath;
            }
        }

        return $mockupPaths;
    }

    private function copyCatalogAsset(?string $catalogAssetPath, string $missingMessage, string $copyErrorMessage): ?string
    {
        if ($catalogAssetPath === null) {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($catalogAssetPath)) {
            throw new RuntimeException($missingMessage);
        }

        $extension = pathinfo($catalogAssetPath, PATHINFO_EXTENSION);
        $orderAssetPath = 'orders/products/'.Str::uuid().($extension !== '' ? '.'.$extension : '');

        if (! $disk->copy($catalogAssetPath, $orderAssetPath)) {
            throw new RuntimeException($copyErrorMessage);
        }

        return $orderAssetPath;
    }

    /**
     * @param  list<UploadedFile>  $mockups
     */
    private function replaceCatalogMockups(Product $product, array $mockups): void
    {
        $mockupPaths = [];

        foreach ($mockups as $mockup) {
            $mockupPath = $mockup->store('catalog/mockups', 'public');
            if (! is_string($mockupPath)) {
                throw new RuntimeException('Mockup produk gagal disimpan ke katalog.');
            }

            $mockupPaths[] = $mockupPath;
        }

        $previousMockupPaths = $product->mockupPaths();
        $product->update([
            'mockup' => $mockupPaths[0] ?? null,
            'mockups' => $mockupPaths ?: null,
        ]);

        foreach ($previousMockupPaths as $previousMockupPath) {
            if (! in_array($previousMockupPath, $mockupPaths, true)) {
                Storage::disk('public')->delete($previousMockupPath);
            }
        }
    }

    private function syncGuestAddress(Guest $guest, array $validated): void
    {
        if (array_key_exists('guest_address', $validated)) {
            $guest->update([
                'address' => $validated['guest_address'],
            ]);
        }
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search): void {
            $query->where('order_id', 'like', "%{$search}%")
                ->orWhere('guest_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhereHas('guest', fn (Builder $guestQuery) => $guestQuery->where('address', 'like', "%{$search}%"))
                ->orWhereHas('items', function (Builder $itemQuery) use ($search): void {
                    $itemQuery->where('product', 'like', "%{$search}%")
                        ->orWhereHas('category', fn (Builder $categoryQuery) => $categoryQuery->where('name', 'like', "%{$search}%"));
                });
        });
    }

    private function applyProductionSearch(Builder $query, string $search, ?array $factoryItemStatuses = null): Builder
    {
        return $query->where(function (Builder $query) use ($search, $factoryItemStatuses): void {
            $query->where('order_id', 'like', "%{$search}%")
                ->orWhereHas('items', fn (Builder $itemQuery) => $itemQuery
                    ->where('product', 'like', "%{$search}%")
                    ->when(
                        $factoryItemStatuses !== null,
                        fn (Builder $itemQuery) => $this->applyFactoryItemVisibility($itemQuery),
                    ));
        });
    }

    private function applyFactoryItemVisibility(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereIn('factory_status', self::FACTORY_ORDER_ITEM_STATUSES)
                ->orWhere(function (Builder $query): void {
                    $query->where(function (Builder $workQuery): void {
                        $workQuery->where(function (Builder $nonStockQuery): void {
                            $nonStockQuery->where('source', '!=', 'Stock')
                                ->where('status', 'Produksi');
                        })->orWhere(function (Builder $stockQuery): void {
                            $stockQuery->where('source', 'Stock')
                                ->where('quality_damaged_quantity', '>', 0)
                                ->whereIn('status', ['Distribusi', 'Produksi']);
                        });
                    })->where(function (Builder $drawingQuery): void {
                        $drawingQuery->whereNull('factory_drawing_image')
                            ->orWhere('factory_drawing_image', '');
                    });
                });
        });
    }

    private function safeCsvValue(?string $value): string
    {
        $value = (string) $value;

        return preg_match('/^[\t\r ]*[=+\-@]/', $value) === 1
            ? "'".$value
            : $value;
    }
}
