<?php

namespace Controllers;

use Exception;
use Illuminate\Http\Request;
use App\Http\Services\OfferService;
use App\Http\Controllers\Controller;
use App\Http\Resources\DataArrayResource;
use App\Http\Requests\OfferBulkRequest;
use App\Http\DataTransferObjects\OfferBulkDto;
use function Sentry\captureException;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class OfferExportController extends Controller
{
    public function __construct(protected OfferService $offerService)
    {
    }

    public function export(OfferBulkRequest $request): DataArrayResource
    {
//        $user = $request->user();
        if ($request->user_id) {
//            Gate::authorize('exportImportOffers', $request->user());
//            $user = User::find($request->user_id);
        }
        try {
            $path = $this->offerService->export($user, OfferBulkDto::fromRequest($request));
        } catch (Exception $exception) {
            captureException($exception);
        }

        return DataArrayResource::make([
            'data' => [
                'path' => $path,
            ]
        ]);
    }
}
