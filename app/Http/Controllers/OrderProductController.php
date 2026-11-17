<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Subcontractor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderProductController extends Controller
{
    private const PRODUCT_STATUSES = ['None', 'Produksi', 'Proses Kirim', 'Distribusi', 'Dalam Perjalanan', 'Finishing', 'Packing', 'Ready'];

    public function index(Request $request): View
    {
        $user = $request->user();
        $statuses = $this->queueStatusesFor($user);
        $productStatuses = $this->editableStatusesFor($user);
        $factoryUser = $user->isFactoryWorkflow();
        $canAssignSubcontractors = $user->isOwner()
            || ($factoryUser && $user->canAccessMenu('subcontractors'));

        abort_if($statuses === [], 403);

        $search = trim((string) $request->query('search', ''));
        $statusFilter = (string) $request->query('status', '');
        $stagnantCutoff = now()->subDays(7);

        if (! in_array($statusFilter, $statuses, true)) {
            $statusFilter = '';
        }

        $itemsQuery = OrderItem::query()
            ->with([
                'category:id,name',
                'activeSubcontractorAssignments.subcontractor:id,name,is_active',
                'pieceworkAssignments' => fn ($query) => $query
                    ->withSum('pieceworkItems as produced_quantity', 'quantity'),
                'order:id,order_id,deadline,status,last_edit',
            ])
            ->withSum('activeSubcontractorAssignments as active_subcontracted_quantity', 'assigned_quantity')
            ->when(
                $factoryUser,
                fn (Builder $query) => $query
                    ->whereIn('factory_status', $statuses)
                    ->whereNotNull('factory_drawing_image')
                    ->where('factory_drawing_image', '!=', '')
                    ->whereRaw(
                        'order_items.qty > COALESCE((SELECT SUM(subcontractor_assignments.assigned_quantity) FROM subcontractor_assignments WHERE subcontractor_assignments.order_item_id = order_items.id AND subcontractor_assignments.cancelled_at IS NULL), 0)',
                    ),
                fn (Builder $query) => $query->whereIn('status', $statuses),
            )
            ->when($statusFilter !== '', fn (Builder $query) => $query->where($factoryUser ? 'factory_status' : 'status', $statusFilter))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('product', 'like', "%{$search}%")
                    ->orWhereHas('order', fn (Builder $orderQuery) => $orderQuery->where('order_id', 'like', "%{$search}%"));
            }));

        $factoryQuantitySql = 'order_items.qty - COALESCE((SELECT SUM(subcontractor_assignments.assigned_quantity) FROM subcontractor_assignments WHERE subcontractor_assignments.order_item_id = order_items.id AND subcontractor_assignments.cancelled_at IS NULL), 0)';
        $incompleteFactoryWorkSql = "{$factoryQuantitySql} > order_items.qty_completed";
        $stagnantItemId = null;
        if ($factoryUser) {
            $stagnantItemId = (clone $itemsQuery)
                ->whereRaw($incompleteFactoryWorkSql)
                ->where('factory_status', '!=', 'Distribusi')
                ->where('qty_progress_updated_at', '<=', $stagnantCutoff)
                ->orderBy('qty_progress_updated_at')
                ->orderBy('id')
                ->value('id');
        }

        $items = $itemsQuery
            ->when(
                $factoryUser,
                fn (Builder $query) => $query->orderByRaw(
                    "CASE WHEN {$incompleteFactoryWorkSql} AND factory_status != 'Distribusi' AND qty_progress_updated_at <= ? THEN 0 ELSE 1 END",
                    [$stagnantCutoff],
                ),
                fn (Builder $query) => $query->orderByRaw(
                    "CASE WHEN qty_completed < qty AND status != 'Distribusi' AND qty_progress_updated_at <= ? THEN 0 ELSE 1 END",
                    [$stagnantCutoff],
                ),
            )
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$stagnantItemId ?? 0])
            ->orderByRaw('CASE WHEN '.($factoryUser ? 'factory_status' : 'status')." = 'Distribusi' THEN 1 ELSE 0 END")
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
        $subcontractors = $canAssignSubcontractors
            ? Subcontractor::query()->orderBy('name')->get(['id', 'name', 'is_active'])
            : collect();
        $factoryEmployees = $factoryUser
            ? Employee::query()
                ->where('business_unit', User::UNIT_FACTORY)
                ->where('status', 'Aktif')
                ->where('employee_type', 'Borongan')
                ->orderBy('full_name')
                ->get(['id', 'full_name'])
            : collect();

        return view('order-products.index', compact(
            'items',
            'search',
            'statusFilter',
            'statuses',
            'productStatuses',
            'factoryUser',
            'canAssignSubcontractors',
            'subcontractors',
            'factoryEmployees',
            'stagnantItemId',
        ));
    }

    public function update(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->isFactoryWorkflow(), 403);
        $queueStatuses = $this->queueStatusesFor($user);
        $factoryWorkflow = $user->isFactoryWorkflow();
        $queuedStatus = $factoryWorkflow ? $orderItem->factory_status : $orderItem->status;
        $maximumQuantity = $factoryWorkflow ? $orderItem->factory_quantity : (int) $orderItem->qty;

        abort_unless(in_array($queuedStatus, $queueStatuses, true), 403);
        abort_if($factoryWorkflow && $maximumQuantity === 0, 403);

        $rules = [
            "items.{$orderItem->id}.status" => [
                'required',
                Rule::in($this->editableStatusesFor($user)),
            ],
            "items.{$orderItem->id}.qty_completed" => [
                'required',
                'integer',
                'min:0',
                'max:'.$maximumQuantity,
            ],
            "items.{$orderItem->id}.comment" => [
                $queuedStatus === 'Distribusi'
                    && $request->input("items.{$orderItem->id}.status") === 'Produksi'
                    ? 'required'
                    : 'nullable',
                'string',
                'max:2000',
            ],
            "items.{$orderItem->id}.status_image" => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
            "items.{$orderItem->id}.status_images" => [
                'nullable',
                'array',
                'min:1',
                'max:10',
            ],
            "items.{$orderItem->id}.status_images.*" => [
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ];

        $validated = $request->validate($rules);
        $itemInput = $validated['items'][$orderItem->id];

        DB::transaction(function () use ($request, $orderItem, $queueStatuses, $itemInput, $factoryWorkflow): void {
            $order = Order::query()->lockForUpdate()->findOrFail($orderItem->order_id);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($orderItem->id);
            $queuedStatus = $factoryWorkflow ? $item->factory_status : $item->status;

            abort_unless(in_array($queuedStatus, $queueStatuses, true), 403);

            if ($factoryWorkflow && $order->status === 'Cancel') {
                throw ValidationException::withMessages([
                    "items.{$item->id}.status" => 'Order yang dibatalkan tidak dapat diubah oleh Pilar Factory.',
                ]);
            }

            $completed = (int) $itemInput['qty_completed'];
            $maximumQuantity = $factoryWorkflow ? $item->factory_quantity : (int) $item->qty;
            if ($completed > $maximumQuantity) {
                throw ValidationException::withMessages([
                    "items.{$item->id}.qty_completed" => 'Jumlah yang sudah dikerjakan tidak boleh melebihi jumlah yang tersedia untuk unit ini.',
                ]);
            }

            $previousOverallStatus = $item->status;
            $previousStatus = $factoryWorkflow ? $item->factory_status : $item->status;
            $previousCompleted = (int) $item->qty_completed;
            $status = $itemInput['status'];
            $statusPhotos = $this->statusPhotosFromRequest($request, "items.{$item->id}");
            $reworkComment = trim((string) ($itemInput['comment'] ?? ''));
            $isReturningToProduction = $previousStatus === 'Distribusi' && $status === 'Produksi';

            if ($isReturningToProduction) {
                if ($reworkComment === '') {
                    throw ValidationException::withMessages([
                        "items.{$item->id}.comment" => 'Komentar wajib diisi saat produk dikembalikan ke Produksi.',
                    ]);
                }

                $completed = 0;
            }

            if ($factoryWorkflow && ! $isReturningToProduction) {
                if ($status === 'Produksi' && $completed === $maximumQuantity) {
                    $status = 'Proses Kirim';
                } elseif (
                    $status === 'Proses Kirim'
                    && $completed < $maximumQuantity
                    && $previousStatus === 'Proses Kirim'
                ) {
                    $status = 'Produksi';
                }

                if (
                    in_array($status, ['Proses Kirim', 'Distribusi'], true)
                    && $completed < $maximumQuantity
                ) {
                    throw ValidationException::withMessages([
                        "items.{$item->id}.status" => 'Selesaikan semua jumlah produk sebelum melanjutkan ke proses kirim.',
                    ]);
                }

                if (
                    $status === 'Distribusi'
                    && $status !== $previousStatus
                    && $previousStatus !== 'Proses Kirim'
                ) {
                    throw ValidationException::withMessages([
                        "items.{$item->id}.status" => 'Ubah status ke Proses Kirim sebelum melanjutkan ke Distribusi.',
                    ]);
                }
            }

            if (
                in_array($status, OrderItem::STATUSES_REQUIRING_PHOTO, true)
                && $status !== $previousStatus
                && $statusPhotos === []
            ) {
                throw ValidationException::withMessages([
                    "items.{$item->id}.status_images" => 'Mockup produk wajib diunggah saat status diubah ke Produksi atau Distribusi.',
                ]);
            }

            if ($previousStatus === $status && $previousCompleted === $completed && $statusPhotos === []) {
                return;
            }

            $statusImagePaths = $item->status_evidence_images;
            if ($statusPhotos !== []) {
                $this->deleteStatusImages($statusImagePaths);
                $statusImagePaths = $this->storeStatusPhotos($statusPhotos);
            }
            $statusImagePath = $statusImagePaths[0] ?? null;

            $progressUpdatedAt = $item->qty_progress_updated_at;
            if ($status === 'Produksi' && $previousStatus !== 'Produksi' && $previousCompleted === $completed) {
                $progressUpdatedAt = now();
            }

            $item->qty_progress_updated_at = $progressUpdatedAt;
            $item->update([
                'factory_status' => $factoryWorkflow ? $status : $item->factory_status,
                'status' => $factoryWorkflow ? $item->status : $status,
                'qty_completed' => $completed,
                'status_image' => $statusImagePath,
                'status_images' => $statusImagePaths ?: null,
            ]);
            if ($factoryWorkflow) {
                $item->synchronizeStatusFromWorkAllocations();
            } elseif (! $item->activeSubcontractorAssignments()->exists()) {
                $item->update(['factory_status' => $status]);
            }
            $item->refresh();
            $order->update(['last_edit' => now()]);

            $currentWorkflowStatus = $factoryWorkflow ? $item->factory_status : $item->status;
            if ($previousStatus !== $currentWorkflowStatus) {
                $order->events()->create([
                    'order_item_id' => $item->id,
                    'type' => $factoryWorkflow && $previousOverallStatus !== $item->status
                        ? 'item_factory_status_changed'
                        : 'item_status_changed',
                    'title' => 'Status produk berubah',
                    'description' => "{$order->order_id} - {$item->product}: {$previousStatus} -> {$currentWorkflowStatus}",
                    'occurred_at' => now(),
                ]);
            }

            if ($previousCompleted !== $completed) {
                $order->events()->create([
                    'order_item_id' => $item->id,
                    'type' => 'item_progress_changed',
                    'title' => 'Progres produk diperbarui',
                    'description' => "{$order->order_id} - {$item->product}: {$previousCompleted} -> {$completed} dari {$maximumQuantity} dikerjakan",
                    'occurred_at' => now(),
                ]);
            }

            if ($previousOverallStatus !== $item->status) {
                $order->events()->create([
                    'order_item_id' => $item->id,
                    'type' => 'item_status_changed',
                    'title' => 'Status keseluruhan produk berubah',
                    'description' => "{$order->order_id} - {$item->product}: {$previousOverallStatus} -> {$item->status}",
                    'occurred_at' => now(),
                ]);
            }

            if ($isReturningToProduction) {
                $order->events()->create([
                    'order_item_id' => $item->id,
                    'type' => 'item_rework_requested',
                    'title' => 'Produk dikembalikan ke Produksi',
                    'description' => $reworkComment,
                    'occurred_at' => now(),
                ]);
            }

            $order->synchronizeProductionStatusFromItems();
        });

        return back()->with('success', 'Status dan progres produk berhasil diperbarui.');
    }

    public function updateFactoryDrawing(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isFactoryWorkflow() && $user->canAccessMenu('order_products'), 403);
        $validated = $request->validate([
            'factory_drawing_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $previousPath = null;
        $newPath = null;

        DB::transaction(function () use ($validated, $orderItem, &$previousPath, &$newPath): void {
            $order = Order::query()->lockForUpdate()->findOrFail($orderItem->order_id);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($orderItem->id);

            if ($order->status === 'Cancel' || $item->factory_status !== 'Produksi') {
                throw ValidationException::withMessages([
                    'factory_drawing_image' => 'Gambar kerja hanya dapat diunggah untuk produk berstatus Produksi pada order aktif.',
                ]);
            }

            $previousPath = $item->factory_drawing_image;
            $newPath = $validated['factory_drawing_image']->store('orders/factory-drawings', 'public');
            $item->update(['factory_drawing_image' => $newPath]);
            $order->events()->create([
                'order_item_id' => $item->id,
                'type' => 'factory_drawing_uploaded',
                'title' => 'Gambar kerja Pilar Factory diperbarui',
                'description' => "{$order->order_id} - {$item->product}",
                'occurred_at' => now(),
            ]);
            $order->synchronizeProductionStatusFromItems();
        });

        if ($previousPath !== null && $previousPath !== $newPath) {
            Storage::disk('public')->delete($previousPath);
        }

        return back()->with('success', 'Gambar kerja Pilar Factory berhasil diperbarui.');
    }

    public function recordQuality(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAccessMenu('order_products') && $user->canAccessUnit(User::UNIT_BALI), 403);
        abort_unless(! $user->isOwner() || $user->activeBusinessUnit() === User::UNIT_BALI, 404);
        $validated = $request->validate([
            'result' => ['required', Rule::in(['Bagus', 'Rusak', 'Kurang'])],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($orderItem, $validated): void {
            $order = Order::query()->lockForUpdate()->findOrFail($orderItem->order_id);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($orderItem->id);
            $quantity = (int) $validated['quantity'];
            $result = $validated['result'];

            if ($order->status === 'Cancel' || $item->status !== 'Dalam Perjalanan') {
                throw ValidationException::withMessages([
                    'result' => 'Pemeriksaan kualitas hanya tersedia untuk produk yang sedang dalam perjalanan.',
                ]);
            }

            if (in_array($result, ['Bagus', 'Rusak'], true) && $quantity > (int) $item->packed_quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'Jumlah yang diperiksa melebihi produk yang sudah masuk ke Packing.',
                ]);
            }

            if ($result === 'Kurang') {
                $missingQuantity = $item->quality_shortage_available;

                if ($quantity > $missingQuantity) {
                    throw ValidationException::withMessages([
                        'quantity' => "Jumlah kurang melebihi produk yang tidak sedang dikerjakan ({$missingQuantity}).",
                    ]);
                }
            }

            if ($result === 'Bagus') {
                $item->packed_quantity = (int) $item->packed_quantity - $quantity;
                $item->quality_good_quantity = (int) $item->quality_good_quantity + $quantity;
            } elseif ($result === 'Rusak') {
                $item->packed_quantity = (int) $item->packed_quantity - $quantity;
                $item->quality_damaged_quantity = (int) $item->quality_damaged_quantity + $quantity;
            } else {
                $item->packed_quantity = max(0, (int) $item->packed_quantity - $quantity);
                $item->quality_short_quantity = (int) $item->quality_short_quantity + $quantity;
            }

            $item->save();
            $item->synchronizeWorkflowStatuses();
            $order->synchronizeProductionStatusFromItems();
            $order->events()->create([
                'order_item_id' => $item->id,
                'type' => 'item_quality_checked',
                'title' => 'Hasil pemeriksaan kualitas dicatat',
                'description' => "{$order->order_id} - {$item->product}: {$quantity} {$result}"
                    .(filled($validated['note'] ?? null) ? ' · '.$validated['note'] : ''),
                'occurred_at' => now(),
            ]);
        });

        return back()->with('success', 'Hasil pemeriksaan kualitas berhasil dicatat.');
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

    /**
     * @return list<string>
     */
    private function queueStatusesFor(User $user): array
    {
        if ($user->isOwner()) {
            return $user->activeBusinessUnit() === User::UNIT_FACTORY
                ? ['Produksi', 'Finishing', 'Packing']
                : ['Dalam Perjalanan', 'Distribusi'];
        }

        $statuses = [];

        if ($user->canAccessUnit(User::UNIT_BALI)) {
            $statuses = array_merge($statuses, ['Dalam Perjalanan', 'Distribusi']);
        }

        if ($user->canAccessUnit(User::UNIT_FACTORY)) {
            $statuses = array_merge($statuses, ['Produksi', 'Finishing', 'Packing']);
        }

        return array_values(array_unique($statuses));
    }

    /**
     * @return list<string>
     */
    private function editableStatusesFor(User $user): array
    {
        if ($user->isFactoryWorkflow()) {
            return ['Produksi', 'Finishing', 'Packing'];
        }

        if ($user->isOwner()) {
            return ['None', 'Produksi', 'Proses Kirim', 'Distribusi'];
        }

        if ($user->isSalesWorkflow() && ! $user->isOwner()) {
            return ['Dalam Perjalanan', 'Distribusi', 'Ready'];
        }

        return self::PRODUCT_STATUSES;
    }
}
