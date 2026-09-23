<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminCreateReservationRequest;
use App\Http\Requests\AdminUpdateUserRequest;
use App\Http\Requests\AdminUserIndexRequest;
use App\Http\Requests\CreateInvitationCodesRequest;
use App\Http\Requests\CreneauIndexRequest;
use App\Http\Requests\SendInvitationRequest;
use App\Http\Resources\CreneauResource;
use App\Http\Resources\InvitationCodeResource;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AdminService;
use App\Services\ExportService;
use App\Services\InvitationService;
use App\Services\PlanningService;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    public function users(AdminUserIndexRequest $request, AdminService $service): AnonymousResourceCollection
    {
        return UserResource::collection($service->users($request->user(), $request->validated())
            ->paginate($request->integer('per_page', 50))->withQueryString());
    }

    public function updateUser(AdminUpdateUserRequest $request, int $id, AdminService $service): UserResource
    {
        return new UserResource($service->update($request->user(), $id, $request->validated(), $request->file('photo')));
    }

    public function slots(CreneauIndexRequest $request, PlanningService $service): AnonymousResourceCollection
    {
        return CreneauResource::collection($service->slots($request->validated(), true));
    }

    public function planning(Request $request, int $id, PlanningService $service): AnonymousResourceCollection
    {
        return ReservationResource::collection($service->reservations(User::findOrFail($id), true));
    }

    public function storeReservation(AdminCreateReservationRequest $request, ReservationService $service)
    {
        return (new ReservationResource($service->create($request->user(), $request->integer('creneau_id'), $request->integer('user_id'))))
            ->response()->setStatusCode(201);
    }

    public function deleteReservation(Request $request, int $id, ReservationService $service): Response
    {
        $service->delete($request->user(), $id, true);

        return response()->noContent();
    }

    public function validatePlanning(Request $request, int $id, ReservationService $service): UserResource
    {
        return new UserResource($service->validate($request->user(), $id));
    }

    public function unlockPlanning(Request $request, int $id, ReservationService $service): UserResource
    {
        return new UserResource($service->unlock($request->user(), $id));
    }

    public function invitations(CreateInvitationCodesRequest $request, AdminService $service)
    {
        return InvitationCodeResource::collection($service->invitations($request->user(), $request->integer('nombre')))
            ->response()->setStatusCode(201);
    }

    public function sendInvitation(SendInvitationRequest $request, InvitationService $service)
    {
        return (new InvitationCodeResource($service->send($request->user(), $request->validated('email'))))
            ->additional(['message' => 'Invitation transmise au service d’envoi.'])
            ->response()->setStatusCode(201);
    }

    public function export(AdminUserIndexRequest $request, ExportService $service): StreamedResponse
    {
        return response()->streamDownload($service->csv($request->user(), $request->validated()), 'benevoles.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function photo(Request $request, AdminService $service, ?int $id = null): StreamedResponse
    {
        return Storage::disk('local')->response($service->photo($request->user(), $id), null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
