<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class InvitationDeliveryException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 503);
    }

    public function report(): bool
    {
        // Transport errors may contain addresses, credentials or message content.
        return true;
    }
}
