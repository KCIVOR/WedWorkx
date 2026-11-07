<?php

namespace App\Services\Ai;

/** One AI backend. Swap it by changing AI_PROVIDER and the binding in AppServiceProvider. */
interface WordingProvider
{
    /**
     * Ask the model for a short piece of text.
     *
     * @return array{text:string,truncated:bool}|null  null on any error, timeout or empty reply
     */
    public function complete(string $system, string $user, int $maxTokens, int $timeoutSeconds): ?array;
}
