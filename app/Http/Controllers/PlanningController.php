<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateReservationRequest;
use App\Http\Requests\CreneauIndexRequest;
use App\Http\Resources\CreneauResource;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\UserResource;
use App\Services\ExportService;
use App\Services\PlanningService;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PlanningController extends Controller
{
    public function creneaux(CreneauIndexRequest $request, PlanningService $service): AnonymousResourceCollection
    {
        return CreneauResource::collection($service->slots($request->validated()));
    }

    public function index(Request $request, PlanningService $service): AnonymousResourceCollection
    {
        return ReservationResource::collection($service->reservations($request->user()));
    }

    public function store(CreateReservationRequest $request, ReservationService $service)
    {
        return (new ReservationResource($service->create($request->user(), $request->integer('creneau_id'))))
            ->response()->setStatusCode(201);
    }

    public function destroy(Request $request, int $id, ReservationService $service): Response
    {
        $service->delete($request->user(), $id);

        return response()->noContent();
    }

    public function validatePlanning(Request $request, ReservationService $service): UserResource
    {
        return new UserResource($service->validate($request->user()));
    }

    public function pdf(Request $request, ExportService $service): Response
    {
        return response($service->pdf($request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="planning.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
