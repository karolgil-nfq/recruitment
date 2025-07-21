<?php

namespace Controllers;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentType;
use App\Enums\UserPermission;
use App\Http\Controllers\Controller;
use App\Http\DataTransferObjects\OfferIndexDto;
use App\Http\DataTransferObjects\OfferSearchDto;
use App\Http\DataTransferObjects\OfferStoreDto;
use App\Http\DataTransferObjects\UserOfferViewsGetDto;
use App\Http\Requests\OfferStoreRequest;
use App\Http\Requests\PaginationRequest;
use App\Http\Resources\Basic\BasicOfferResource;
use App\Http\Resources\DataArrayResource;
use App\Http\Resources\OfferResource;
use App\Http\Resources\OfferSearchResource;
use App\Http\Resources\UserOfferViewResource;
use App\Http\Services\OfferService;
use App\Http\Services\OfferViewService;
use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;


class OfferController extends Controller
{
    public function __construct(protected OfferService $offerService) {}

    public function index(PaginationRequest $request): AnonymousResourceCollection
    {
        return BasicOfferResource::collection(
            $this->offerService->get(OfferIndexDto::fromRequest($request), $request->user())
                ->with('warehouse', 'basicUser', 'product', 'product.files', 'product.parameters', 'product.category', 'incoterms')
                ->paginate($request->perPage())
        );
    }

    public function indexUserOffers(User $user, PaginationRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('checkUserHistory', $user);

        return UserOfferViewResource::collection(
            $this->offerService->getUserViewedOffers($user, UserOfferViewsGetDto::fromRequest($request))
                ->paginate($request->perPage())
        );
    }

    public function count(): DataArrayResource
    {
        return DataArrayResource::make(
            $this->offerService->getCounters()
        );
    }

    public function show(Offer $offer, Request $request): OfferResource|BasicOfferResource
    {
        $userFromRequest = $request->user() ? $request->user() : null;

        app(OfferViewService::class)->view($userFromRequest, $offer, $request->ip());

        if ($offer->user == $request->user()) {
            return OfferResource::make($this->offerService->details(
                $offer,
                auth('sanctum')->user()
            ));
        }

        if ($request->user()?->hasPermissionTo(UserPermission::SeeOwnerInOffer->value) || $request->user()?->hasPermissionTo(UserPermission::SeeProductWarehouse->value)) {
            if ($request->query('user')) {
                $user = User::where('id', $request->query('user'))->first();
                return OfferResource::make($this->offerService->details($offer, $user));
            }
            return OfferResource::make(
                $this->offerService->details(
                    $offer,
                    auth('sanctum')->user()
                )
            );
        }

        return BasicOfferResource::make(
            $this->offerService->details(
                $offer,
                auth('sanctum')->user()
            )
        );
    }

    public function store(Product $product, OfferStoreRequest $request): OfferResource
    {

        Gate::authorize('canSellLimited', $request->user());


        $offer = $this->offerService->store(
            $request->user(),
            $product,
            OfferStoreDto::fromRequest($request)
        );

        return OfferResource::make(
            $offer
                ->fresh()
                ->load('product.category', 'product.parameters', 'product.files', 'incoterms', 'prices', 'countries')
        );
    }

    public function update(Offer $offer, OfferStoreRequest $request): OfferResource
    {
        Gate::authorize('update', [$offer, $request]);

        if ($offer->status === OfferStatus::Active) Gate::authorize('canSellLimited', $request->user());

        return OfferResource::make(
            $this->offerService->update($offer, OfferStoreDto::fromRequest($request), $request->user())
                ->fresh()
                ->load('product.category', 'product.parameters', 'product.files', 'incoterms', 'prices', 'countries')
        );
    }

    public function destroy(Offer $offer): Response
    {
        Gate::authorize('update', $offer);

        $this->offerService->delete($offer);

        return response()->noContent();
    }

    public function paymentMethods(Offer $offer): DataArrayResource
    {
        $securePayment = ($offer->user->stripeAccount?->accept_escrow && $offer->user->stripeAccount?->is_completed) ?? false;

        return DataArrayResource::make([
            OrderPaymentType::WireTransfer->value => !$offer->user->user2pAccount && ($offer->user->paymentMethod?->enabled ?? false),
            OrderPaymentType::SecurePayment->value => $offer->user->user2pAccount || ($securePayment && $offer->accept_escrow),
            OrderPaymentType::PayPal->value => !$offer->user->user2pAccount && $offer->user->payPalIntegration?->enabled && !$offer->disable_pay_pal_payment,
            OrderPaymentType::BuyNowPayLater->value => !$offer->user->user2pAccount && ($offer->user->ariaSeller?->is_active ?? false) && ($offer->user->ariaSeller?->is_completed ?? false) && !$offer->disable_buy_now_pay_later_payment,
        ]);
    }

    public function search(PaginationRequest $request): AnonymousResourceCollection
    {
        $query = $this->offerService->search(OfferSearchDto::fromRequest($request));

        return OfferSearchResource::collection(
            $query->paginate($request->perPage())
        );
    }

    public function counters(Request $request): DataArrayResource
    {
        return DataArrayResource::make(
            $this->offerService->countOffers($request->user(), $request->boolean('admin'), $request->string('user_id'))
        );
    }
}
