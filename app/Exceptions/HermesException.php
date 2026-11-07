<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/** A clear 4xx JSON error for the Hermes API. */
class HermesException extends Exception
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->errorCode, 'message' => $this->getMessage()], $this->status);
    }
}
