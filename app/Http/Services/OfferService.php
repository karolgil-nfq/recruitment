<?php


use Sentry;
use Exception;
use Throwable;
use App\Models\User;
use App\Models\Offer;
use App\Models\Product;
use Sentry\State\Scope;
use App\Enums\OfferUnit;
use App\Models\Warehouse;
use App\Models\OfferViews;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\OfferSource;
use Illuminate\Support\Str;
use App\Enums\IncotermsName;
use App\Enums\ProductStatus;
use App\Enums\FileCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use App\Enums\OfferPriceDisplayUnit;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Laravel\Scout\Jobs\MakeSearchable;
use App\Http\Requests\OfferStoreRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\v1\OfferUpdateRequest;
use App\Http\Services\Order\OrderItemService;
use App\Http\DataTransferObjects\OfferBulkDto;
use Illuminate\Validation\ValidationException;
use App\Http\DataTransferObjects\OfferStoreDto;
use App\Http\DataTransferObjects\OfferIndexDto;
use App\Http\DataTransferObjects\OfferSearchDto;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Http\DataTransferObjects\IncotermsStoreDto;
use App\Http\DataTransferObjects\OffersBulkDeleteDto;
use App\Http\DataTransferObjects\UserOfferViewsGetDto;
use App\Http\DataTransferObjects\OfferStatusUpdateDto;
use App\Http\DataTransferObjects\CsvImportFailedOffers;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use App\Enums\UserPermission;


class OfferService
{
    public function get(OfferIndexDto $dto, User $user = null): Builder
    {
        $query = Offer::query()
            ->with([
                'product' => function ($query) {
                    $query->with('category', 'parameters', 'files');
                },
                'user.paymentMethod',
                'user.stripeAccount',
                'user.payPalIntegration',
                'user.permissions',
            ])
            ->with('warehouse.address', 'incoterms')
            ->where('status', OfferStatus::Active);

        if ($dto->name) {
            $query->where('offers.id', $dto->name)
                ->orWhereHas('product', function ($query) use ($dto) {
                    $query->where('name', 'like', '%' . $dto->name . '%');
                });
        }

        if ($dto->sortBy) {
            $query->orderBy($dto->sortBy, $dto->orderBy);
        } else {
            $query->orderBy('offers.created_at', 'desc');
        }

        if ($user && !$user->hasPermission(UserPermission::AdminOffers)) {
            $query->where('business_id', $user->business_id);
        }

        return $query;
    }

    public function getUserViewedOffers(User $user, UserOfferViewsGetDto $dto): Builder|HasMany
    {
        $query = OfferViews::query()
            ->where('user_id', $user->id)
            ->with(['offer.product', 'offer.warehouse'])
            ->withCount('offer.prices')
            ->orderBy('created_at', 'desc');

        if ($dto->sortBy) {
            $query->orderBy($dto->sortBy, $dto->orderBy);
        }

        if ($dto->perPage) {
            $query->paginate($dto->perPage);
        }

        return $query;
    }

    public function store(User $user, Product $product, OfferStoreDto $dto): Offer|null
    {
        if ($dto->price_display_unit === OfferPriceDisplayUnit::Wp && !$this->validatePriceInWp($product)) {
            abort(400, 'You can\'t set price in Wp for this product');
        }

        try {
            DB::beginTransaction();

            $offer = Offer::withoutSyncingToSearch(function () use ($user, $product, $dto) {
                return $this->createOrUpdateOffer($user, $product, $dto);
            });

            $offer->searchableUsing()->update(collect([$offer]));

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error('Offer store error', [
                'dto' => $dto,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $offer;
    }

    public function update(Offer $offer, OfferStoreDto $dto, User $user = null): Offer
    {
        if ($dto->price_display_unit === OfferPriceDisplayUnit::Wp && !$this->validatePriceInWp($offer->product)) {
            abort(400, 'You can\'t set price in Wp for this product');
        }

        try {
            DB::beginTransaction();

            $offer = Offer::withoutSyncingToSearch(function () use ($offer, $dto, $user) {
                return $this->createOrUpdateOffer($user ?? $offer->user, $offer->product, $dto, $offer);
            });

            $this->handleIncoterms($dto->incoterms, $offer, true);
            $this->handleCountries($dto->excludedCountries, $offer, true);
            $this->handlePrices($dto, $offer->product, $offer, true);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error('Offer update error', [
                'dto' => $dto,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $offer;
    }

    public function updateBulk(User $user, OfferUpdateRequest $request): EloquentCollection
    {
        $offers = $user->offers()
            ->whereIn('id', $request->offer_ids)
            ->get();

        if ($offers->isEmpty()) {
            return $offers;
        }

        $updatedOffers = new EloquentCollection();

        foreach ($offers as $offer) {
            if (Gate::allows('update', [$offer, $request])) {
                try {
                    DB::beginTransaction();

                    $offer = Offer::withoutSyncingToSearch(function () use ($offer, $request) {
                        return $this->createOrUpdateOffer($offer->user, $offer->product, OfferStoreDto::fromRequest($request), $offer);
                    });

                    $this->handleIncoterms($request->incoterms, $offer, true);
                    $this->handleCountries($request->excluded_countries, $offer, true);
                    $this->handlePrices(OfferStoreDto::fromRequest($request), $offer->product, $offer, true);

                    DB::commit();
                } catch (Throwable $e) {
                    DB::rollBack();

                    Log::error('Offer bulk update error', [
                        'offer_id' => $offer->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $updatedOffers->push($offer);
            }
        }

        return $updatedOffers;
    }

    public function validatePriceInWp(Product $product): bool
    {
        // Check if the product has a parameter for "Module Power" and if it is numeric
        $modulePower = $product->parameterValue('Module Power');
        return $modulePower !== null && is_numeric($modulePower) && (float)$modulePower > 0;
    }

    protected function createOrUpdateOffer(User $user, Product $product, OfferStoreDto $dto, Offer $offer = null, OfferStatus $offerStatus = null): Offer
    {
        if (!$offer) {
            $offer = new Offer();
            $offer->source = OfferSource::Web;
        }

        $offer->fill([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'warehouse_id' => $dto->warehouseId,
            'status' => $offerStatus ?? OfferStatus::Draft,
            'name' => $dto->name ?: Str::limit($product->name, 100),
            'description' => $dto->description,
            'availability_quantity' => $dto->availability_quantity,
            'min_order_quantity' => $dto->min_order_quantity,
            'min_order_unit' => $dto->min_order_unit,
            'price_display_unit' => $dto->price_display_unit,
            'publish_at' => $dto->publish_at,
            'expire_at' => $dto->expire_at,
        ]);

        if ($dto->promotionId) {
            $offer->promotion_id = $dto->promotionId;
        }

        if ($dto->shipping_available_from) {
            $offer->shipping_available_from = $dto->shipping_available_from;
        }

        if ($dto->availability_quantity === 0) {
            $offer->status = OfferStatus::Inactive;
        }

        if ($offerStatus === OfferStatus::Active && !$offer->publish_at) {
            $offer->publish_at = now();
        }

        if ($offerStatus === OfferStatus::Draft && !$offer->publish_at) {
            $offer->publish_at = null;
        }

        if ($offerStatus === OfferStatus::Active && !$offer->expire_at) {
            $offer->expire_at = now()->addDays(30);
        }

        if ($offerStatus === OfferStatus::Draft && !$offer->expire_at) {
            $offer->expire_at = null;
        }

        if (!$offer->lowest_price) {
            $this->updateLowestPrice($offer);
        }

        // Save the offer
        $offer->save();

        return $offer;
    }

    protected function handleIncoterms(array $incotermsData, Offer $offer, bool $clearExisting = false): void
    {
        if ($clearExisting) {
            $offer->incoterms()->delete();
        }

        $incoterms = [];

        foreach ($incotermsData as $incotermData) {
            $incoterm = IncotermsStoreDto::fromArray($incotermData);
            $incoterms[] = [
                'model_type' => Offer::class,
                'model_id' => $offer->id,
                'name' => $incoterm->name,
                'value' => $incoterm->value,
                'price' => $incoterm->price,
                'shipping_from_country' => $incoterm->shipping_from_country,
                'pickup_available_in_weeks' => $incoterm->pickup_available_in_weeks,
                'override_warehouse' => $incoterm->override_warehouse,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('incoterms')->insert($incoterms);
    }

    protected function handleCountries(array $countriesData, Offer $offer, bool $clearExisting = false): void
    {
        if ($clearExisting) {
            $offer->countries()->delete();
        }

        $countries = [];

        foreach ($countriesData as $countryCode) {
            $countries[] = [
                'model_type' => Offer::class,
                'model_id' => $offer->id,
                'country_code' => strtoupper($countryCode),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('offer_countries')->insert($countries);
    }

    private function processIncotermForExport($incoterm): float|string|null
    {
        if ($incoterm) {
            if ($incoterm->override_warehouse) {
                if ($incoterm->value) {
                    return $incoterm->price / 100;
                } else {
                    return 'DISABLED';
                }
            } else {
                return null;
            }
        }
        return null;
    }

    public function export(User $user, OfferBulkDto $dto, $returnMode = "file")
    {
        $offers = $this->applyBulkFilters($user, $dto)
            ->limit(10000)
            ->orderBy('created_at', 'desc')
            ->with('product', 'countries', 'incoterms', 'prices')
            ->with('prices', function ($query) {
                $query->whereNull('from');
            })
            ->with('product.parameters', function ($query) {
                $query->whereIn('name', ['Name']);
            })
            ->get();

        $response = [];
        $countMaxPrices = 0;

        foreach ($offers as $key => $offer) {
            $price = $offer->prices->where('from', null)->first();

            $prices = [
                $offer->price_display_unit == "X" ? $price->price_mod : $price->price / 100
            ];

            foreach ($offer->prices->whereNotNull('from')->sortBy('from') as $priceKey => $price) {
                $prices[] = $price->from;
                $prices[] = $offer->price_display_unit == "Y" ? $price->price_mod : $price->price / 100;

                $countMaxPrices = max($priceKey, $countMaxPrices);
            }
            if ($offer->created_at->lt(now()->subYear())) {
                continue;
            }

            $incotermCif = $offer->incoterms->where('name', 'CIF')->all();
            $incotermExw = $offer->incoterms->where('name', 'EXW')->all();
            $incotermFca = $offer->incoterms->where('name', 'FCA')->first();

            $incotermCifExportValue = $this->processIncotermForExport($incotermCif[0]);
            $incotermExwExportValue = $this->processIncotermForExport($incotermExw[0]);
            $incotermFcaExportValue = $this->processIncotermForExport($incotermFca[0]);


            $shippingFromCountry = strtoupper($incotermCif?->shipping_from_country ?? $incotermExw?->shipping_from_country ?? $incotermFca?->shipping_from_country);
            $pickupAvailableInWeeks = $incotermCif?->pickup_available_in_weeks ?? $incotermExw?->pickup_available_in_weeks ?? $incotermFca?->pickup_available_in_weeks;

            $data = [
                $offer->id,
                $offer->product->parameters->where('name', 'Name')->first()?->pivot?->value,
                $offer->original_name,
                $offer->description,
                $offer->availability_quantity,
                $offer->full_containers_only ? 'Containes only' : ($offer->full_pallets_only ? ' pallets only' : 'Pieces'),
                $offer->min_order_quantity,
                $offer->shipping_available_from?->format('d/m/Y'),
                $offer->expire_at?->format('d/m/Y'),
                $offer->accept_escrow ? 'YES' : 'NO',
                $offer->disable_payment_order ? 'NO' : 'YES',
                $offer->disable_wire_transfer_payment ? 'NO' : 'YES',
                $offer->disable_pay_pal_payment ? 'NO' : 'YES',
                $offer->disable_buy_now_pay_later_payment ? 'NO' : 'YES',
                $offer->allow_quick_purchase ? 'YES' : 'NO',
                $offer->shipping_time,
                $incotermCifExportValue,
                $incotermExwExportValue,
                $incotermFcaExportValue,
                $shippingFromCountry,
                $pickupAvailableInWeeks,
                implode(', ', $offer->countries->where('value', false)->pluck('country_code')->toArray()),
                $offer->price_display_unit ? ucfirst($offer->price_display_unit->value) : ucfirst(OfferPriceDisplayUnit::Piece->value),
            ];
            $offer->exported_at = now();
            $offer->save();

            $response[] = array_merge($data, $prices);
        }

        $keys = [
            'Offer id',
            'Product name',
            'Original name',
            'Min order quantity',
            'Shipping available from date',
            'Offer expiry at',
            'Offer expiry at',
            'Accept secure payment',
            'Accept PayPal payment',
            'Enable payment order generation',
            'Shipping Time (days)',
            'Shipping Time (days)',
            'CIF delivery price',
            'FCA delivery price',
            'Excluded delivery countries',
            'Excluded delivery countries',
            'Price unit',
            'price_range_amount_1',
        ];

        for ($i = 2; $i <= $countMaxPrices + 3; $i++) {
            $keys[] = "price_range_min_qty_$i";
            $keys[] = "price_range_amount_$i";
        }

        $random = Str::random();
        $collection = FileCollection::CsvExportOffers->value;
        $fullPath = "export/$collection/$random/export_offers.xlsx";

        Excel::store(
            new CsvImportFailedOffers(array_merge_recursive([$keys], $response)),
            $fullPath,
            config('filesystem.default'),
            null,
        );


        switch( $returnMode){
            case 'file':
                return $fullPath;
            case 'stream':
                return $this->streamCsv($response);
            case 'download':
                return response()->download(storage_path("app/$fullPath"), 'offers_export.csv');
            case 'debug':
                var_dump($response);
                break;
        }

    }

    protected function streamCsv($offers)
    {
        return response()->streamDownload(function () use ($offers) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['ID', 'Name', 'Price', 'Incoterm', 'Countries']);

            foreach ($offers as $row) {
                $price = $row->prices[0]->price ?? 0;
                $name = $row->product->parameters->first()?->pivot?->value ?? 'Unnamed';
                $countries = implode(';', $row->countries->pluck('country_code')->toArray());
                $incoterm = $row->incoterms->first()->name ?? 'n/a';
                fputcsv($out, [
                    $row->id,
                    $name,
                    $price,
                    strtoupper($incoterm),
                    $countries,
                ]);
            }

            fclose($out);
        }, 'offers_export.csv');
    }

    protected function handlePrices(OfferStoreDto $dto, Product $product, Offer $offer, bool $clearExisting = false): void
    {
        if ($clearExisting) {
            $offer->prices()->delete();
        }

        $prices = [];

        foreach ($dto->prices as $priceData) {
            $price = [
                'offer_id' => $offer->id,
                'price' => $priceData['price'],
                'price_wp' => $priceData['price_wp'] ?? null,
                'from' => $priceData['from'] ?? null,
                'to' => $priceData['to'] ?? null,
            ];

            if ($dto->price_display_unit === OfferPriceDisplayUnit::Wp) {
                $price['price'] = $this->calculatePriceFromPriceInWp($product, $priceData['price_wp']);
            }

            $prices[] = $price;
        }

        DB::table('offer_prices')->insert($prices);
    }

    public function calculatePriceFromPriceInWp(Product $product, float $priceWp): float
    {
        $modulePower = $product->parameterValue('Module Power') ?? 1;
        $modulePower = (int)(preg_replace('/[^0-9.]/', '', $modulePower ?? 1));

        if (!$modulePower) {
            Log::error('calculatePriceFromPriceInWp error', [
                'product_id' => $product->id,
                'module_power_formatted' => $modulePower,
                'module_power_original' => $product->parameterValue('Module Power'),
                'error' => 'Module power not found',
            ]);
            return 0;
        }

        return round($priceWp * $modulePower, 3);
    }

    public function delete(Offer $offer): bool
    {
        return $offer->delete();
    }

    public function duplicate(Offer $offer): Offer
    {
        $newOffer = $offer->replicate();
        $newOffer->name = Str::limit($offer->name, 100) . ' (Copy)';
        $newOffer->status = OfferStatus::Draft;
        $newOffer->publish_at = null;
        $newOffer->expire_at = null;
        $newOffer->lowest_price = null;
        $newOffer->save();

        // Duplicate incoterms
        foreach ($offer->incoterms as $incoterm) {
            $newIncoterm = $incoterm->replicate();
            $newIncoterm->model_id = $newOffer->id;
            $newIncoterm->save();
        }

        // Duplicate countries
        foreach ($offer->countries as $country) {
            $newCountry = $country->replicate();
            $newCountry->model_id = $newOffer->id;
            $newCountry->save();
        }

        // Duplicate prices
        foreach ($offer->prices as $price) {
            $newPrice = $price->replicate();
            $newPrice->offer_id = $newOffer->id;
            $newPrice->save();
        }

        return $newOffer;
    }


    public function validate(array $data, bool $abort = true, string $dateFormat = null): ?\Illuminate\Validation\Validator
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'availability_quantity' => 'required|integer|min:0',
            'min_order_quantity' => 'required|integer|min:1',
            'min_order_unit' => 'required|in:' . implode(',', OfferUnit::values()),
            'price_display_unit' => 'required|in:' . implode(',', OfferPriceDisplayUnit::values()),
            'publish_at' => 'nullable|date_format:' . ($dateFormat ?: config('app.date_format')),
            'expire_at' => 'nullable|date_format:' . ($dateFormat ?: config('app.date_format')),
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails() && $abort) {
            throw new ValidationException($validator);
        }

        return $validator;
    }

    public function updateStatus(Offer $offer, OfferStatusUpdateDto $dto): void
    {
        if ($dto->status === OfferStatus::Active && !$offer->publish_at) {
            $offer->publish_at = now();
        }

        if ($dto->status === OfferStatus::Draft && !$offer->publish_at) {
            $offer->publish_at = null;
        }

        if ($dto->status === OfferStatus::Active && !$offer->expire_at) {
            $offer->expire_at = now()->addDays(30);
        }

        if ($dto->status === OfferStatus::Draft && !$offer->expire_at) {
            $offer->expire_at = null;
        }

        $offer->status = $dto->status;
        $offer->save();

        dispatch_sync(new MakeSearchable(new EloquentCollection([$offer])));
    }

    public function updateBulkStatus(User $user, OfferBulkDto $dto): int
    {
        $query = $this->applyBulkFilters($user, $dto);

        if ($dto->status === OfferStatus::Active) {
            $query->whereNull('publish_at');
        } elseif ($dto->status === OfferStatus::Draft) {
            $query->whereNotNull('publish_at');
        }

        $updatedCount = $query->update(['status' => $dto->status]);

        if ($updatedCount > 0) {
            dispatch_sync(new MakeSearchable($query->get()));
        }

        return $updatedCount;
    }

    public function deleteBulkOffers(User $user, OffersBulkDeleteDto $dto): int
    {
        $query = $this->applyBulkFilters($user, $dto);

        if ($dto->status === OfferStatus::Active) {
            $query->whereNull('publish_at');
        } elseif ($dto->status === OfferStatus::Draft) {
            $query->whereNotNull('publish_at');
        }

        $deletedCount = $query->delete();

        if ($deletedCount > 0) {
            dispatch_sync(new MakeSearchable($query->get()));
        }

        return $deletedCount;
    }


    public function updateLowestPrice(Offer $offer, int $lowestPrice = null): ?float
    {
        if ($offer->prices->isEmpty()) {
            $offer->lowest_price = null;
            $offer->save();
            return null;
        }

        $lowestPrice = $lowestPrice ?? $offer->prices->min('price');

        if ($lowestPrice !== null) {
            $offer->lowest_price = $lowestPrice;
            $offer->save();
        }

        return $offer->lowest_price;
    }

    public function details(Offer $offer, User $user = null): Offer
    {
        $offer->load([
            'product.category',
            'product.parameters',
            'product.files',
            'incoterms',
            'prices',
            'countries',
            'warehouse.address',
            'user.paymentMethod',
            'user.stripeAccount',
            'user.payPalIntegration',
            'user.permissions'
        ]);

        if ($user) {
            $offer->setAttribute('is_favorite', $user->favorites()->where('offer_id', $offer->id)->exists());
        }

        $offer->setAttribute('incoterms', $this->mergeWarehouseAndOfferIncoterms($offer));
        $offer->setAttribute('countries', $this->mergeWarehouseAndOfferCountries($offer));
        $offer->setAttribute('sun_store_delivery_countries', $this->setSunStoreDeliveryCountries($offer));

        return $offer;
    }

    public function getCounters(): array
    {
        $counters = [];
        foreach (OfferStatus::cases() as $status) {
            $counters[$status->value] = Offer::where('status', $status)->count();
        }

        $counters[OfferStatus::Inactive->value] = Offer::where('status', OfferStatus::Inactive)
            ->where('availability_quantity', '>', 0)
            ->count();

        return $counters;
    }

    public function applyBulkFilters(User $user, OfferBulkDto $dto): Builder|HasMany
    {
        $query = Offer::query()
            ->with(['product', 'warehouse', 'incoterms', 'countries'])
            ->where('business_id', $user->business_id);

        if ($dto->ids) {
            $query->whereIn('id', $dto->ids);
        }

        if ($dto->status) {
            $query->where('status', $dto->status);
        }

        if ($dto->name) {
            $query->where('name', 'like', '%' . $dto->name . '%');
        }

        if ($dto->productName) {
            $query->whereHas('product', function ($q) use ($dto) {
                $q->where('name', 'like', '%' . $dto->productName . '%');
            });
        }

        if ($dto->warehouseId) {
            $query->where('warehouse_id', $dto->warehouseId);
        }

        return $query;
    }


    private function mergeWarehouseAndOfferIncoterms(Offer $offer): Collection
    {
        $excludedIncoterms = $offer->incoterms->pluck('name')->map(function ($name) {
            return strtolower($name);
        })->flip();

        return $offer->warehouse->incoterms->reject(function ($incoterm) use ($excludedIncoterms) {
            return isset($excludedIncoterms[strtolower($incoterm->name)]);
        })->values();
    }

    public function search(OfferSearchDto $dto): Builder
    {
        $query = Offer::query()
            ->with([
                'product' => function ($query) {
                    $query->with('category', 'parameters', 'files');
                },
                'user.paymentMethod',
                'user.stripeAccount',
                'user.payPalIntegration',
                'user.permissions',
            ])
            ->with('warehouse.address', 'incoterms')
            ->where('status', OfferStatus::Active);

        if ($dto->searchTerm) {
            $query->where(function ($q) use ($dto) {
                $q->where('offers.name', 'like', '%' . $dto->searchTerm . '%')
                    ->orWhereHas('product', function ($q) use ($dto) {
                        $q->where('name', 'like', '%' . $dto->searchTerm . '%');
                    });
            });
        }

        if ($dto->sortBy) {
            $query->orderBy($dto->sortBy, $dto->orderBy);
        } else {
            $query->orderBy('offers.created_at', 'desc');
        }

        return $query;
    }

    public function countOffers(User $user, bool $admin, string $user_id): array
    {
        $query = Offer::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status');

        if (!$admin) {
            $query->where('business_id', $user->business_id);
        }

        if ($user_id) {
            $query->where('user_id', $user_id);
        }

        return $query->get()->pluck('count', 'status')->toArray();
    }
}
