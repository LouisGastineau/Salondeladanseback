<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminCreateReservationRequest;
use App\Http\Requests\AdminPlanningIndexRequest;
use App\Http\Requests\AdminUpdateUserRequest;
use App\Http\Requests\AdminUserIndexRequest;
use App\Http\Requests\ChangeUserRoleRequest;
use App\Http\Requests\CreateInvitationCodesRequest;
use App\Http\Requests\CreneauIndexRequest;
use App\Http\Requests\HistoryIndexRequest;
use App\Http\Requests\ImportInvitationsRequest;
use App\Http\Requests\InvitationIndexRequest;
use App\Http\Requests\SendInvitationRequest;
use App\Http\Requests\StoreCreneauRequest;
use App\Http\Requests\StoreEditionRequest;
use App\Http\Requests\StoreMissionRequest;
use App\Http\Requests\UpdateCreneauRequest;
use App\Http\Requests\UpdateEditionRequest;
use App\Http\Requests\UpdateMissionRequest;
use App\Http\Requests\ValidationDecisionRequest;
use App\Http\Requests\ValidationIndexRequest;
use App\Http\Resources\AdminHistoryResource;
use App\Http\Resources\AdminPlanningResource;
use App\Http\Resources\AdminValidationResource;
use App\Http\Resources\CreneauParticipantResource;
use App\Http\Resources\CreneauResource;
use App\Http\Resources\EditionResource;
use App\Http\Resources\InvitationCodeResource;
use App\Http\Resources\InvitationImportResource;
use App\Http\Resources\MissionResource;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AdminCatalogService;
use App\Services\AdminHistoryService;
use App\Services\AdminPlanningService;
use App\Services\AdminService;
use App\Services\ExportService;
use App\Services\InvitationCsvService;
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
    public function mission(Request $request, int $id, AdminCatalogService $service): MissionResource
    {
        return new MissionResource($service->mission($request->user(), $id));
    }

    public function creneau(Request $request, int $id, AdminCatalogService $service): CreneauResource
    {
        return new CreneauResource($service->creneau($request->user(), $id));
    }

    public function validations(ValidationIndexRequest $request, ReservationService $service): AnonymousResourceCollection
    {
        return AdminValidationResource::collection($service->validations($request->user(), $request->validated()));
    }

    public function decideValidation(ValidationDecisionRequest $request, int $id, ReservationService $service)
    {
        $reservation = $service->decide($request->user(), $id, $request->validated('decision'));

        return $reservation ? new ReservationResource($reservation) : response()->json(['message' => 'Demande refusée. La place a été libérée et le planning est à nouveau modifiable.']);
    }

    public function history(HistoryIndexRequest $request, AdminHistoryService $service)
    {
        return AdminHistoryResource::collection($service->index($request->user(), $request->validated()));
    }

    public function editions(Request $request, AdminCatalogService $service): AnonymousResourceCollection
    {
        return EditionResource::collection($service->editions($request->user()));
    }

    public function edition(Request $request, int $id, AdminCatalogService $service): EditionResource
    {
        return new EditionResource($service->edition($request->user(), $id));
    }

    public function storeEdition(StoreEditionRequest $request, AdminCatalogService $service)
    {
        return (new EditionResource($service->saveEdition($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function updateEdition(UpdateEditionRequest $request, int $id, AdminCatalogService $service): EditionResource
    {
        return new EditionResource($service->saveEdition($request->user(), $request->validated(), $id));
    }

    public function deleteEdition(Request $request, int $id, AdminCatalogService $service): Response
    {
        $service->deleteEdition($request->user(), $id);

        return response()->noContent();
    }

    public function missions(Request $request, AdminCatalogService $service): AnonymousResourceCollection
    {
        return MissionResource::collection($service->missions($request->user(), $request->integer('edition_id') ?: null));
    }

    public function storeMission(StoreMissionRequest $request, AdminCatalogService $service)
    {
        return (new MissionResource($service->saveMission($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function updateMission(UpdateMissionRequest $request, int $id, AdminCatalogService $service): MissionResource
    {
        return new MissionResource($service->saveMission($request->user(), $request->validated(), $id));
    }

    public function deleteMission(Request $request, int $id, AdminCatalogService $service): Response
    {
        $service->deleteMission($request->user(), $id);

        return response()->noContent();
    }

    public function adminCreneaux(Request $request, AdminCatalogService $service): AnonymousResourceCollection
    {
        return CreneauResource::collection($service->creneaux(
            $request->user(),
            $request->integer('mission_id') ?: null,
            $request->integer('edition_id') ?: null,
        ));
    }

    public function storeCreneau(StoreCreneauRequest $request, AdminCatalogService $service)
    {
        return (new CreneauResource($service->saveCreneau($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function updateCreneau(UpdateCreneauRequest $request, int $id, AdminCatalogService $service): CreneauResource
    {
        return new CreneauResource($service->saveCreneau($request->user(), $request->validated(), $id));
    }

    public function deleteCreneau(Request $request, int $id, AdminCatalogService $service): Response
    {
        $service->deleteCreneau($request->user(), $id);

        return response()->noContent();
    }

    public function user(Request $request, int $id, AdminService $service): UserResource
    {
        return new UserResource($service->user($request->user(), $id));
    }

    public function plannings(AdminPlanningIndexRequest $request, AdminPlanningService $service): AnonymousResourceCollection
    {
        $result = $service->index($request->user(), $request->validated());

        return AdminPlanningResource::collection($result['users'])
            ->additional(['meta' => ['edition_id' => $result['edition_id']]]);
    }

    public function participants(Request $request, int $id, AdminPlanningService $service): AnonymousResourceCollection
    {
        $slot = $service->participants($request->user(), $id);

        return CreneauParticipantResource::collection($slot->reservations)
            ->additional(['meta' => ['creneau' => new CreneauResource($slot)]]);
    }

    public function users(AdminUserIndexRequest $request, AdminService $service): AnonymousResourceCollection
    {
        return UserResource::collection($service->users($request->user(), $request->validated())
            ->paginate($request->integer('per_page', 50))->withQueryString());
    }

    public function updateUser(AdminUpdateUserRequest $request, int $id, AdminService $service): UserResource
    {
        return new UserResource($service->update($request->user(), $id, $request->validated(), $request->file('photo')));
    }

    public function changeRole(ChangeUserRoleRequest $request, int $id, AdminService $service): UserResource
    {
        return new UserResource($service->changeRole($request->user(), $id, $request->validated('role')));
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

    public function invitationIndex(InvitationIndexRequest $request, InvitationService $service): AnonymousResourceCollection
    {
        return InvitationCodeResource::collection($service->index($request->user(), $request->validated()));
    }

    public function importInvitations(ImportInvitationsRequest $request, InvitationCsvService $service): AnonymousResourceCollection
    {
        $result = $service->import($request->user(), $request->file('file'), $request->integer('offset', 0), $request->integer('limit', 20));

        return InvitationImportResource::collection($result['rows'])->additional(['meta' => $result['meta']]);
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
