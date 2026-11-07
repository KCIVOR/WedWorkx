<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\Upload;
use App\Services\WebsiteDetailsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Logo and photo uploads for Chatbot 2. Type, size and count are enforced here, not just in the browser. */
class UploadController extends Controller
{
    public function store(string $token, Request $request, WebsiteDetailsService $details): JsonResponse
    {
        $supplier = Supplier::where('invite_token', $token)->firstOrFail();
        $details->authorizeEdit($supplier);

        $maxKb = config('uploads.max_kb');
        $request->validate([
            'kind' => ['required', 'in:logo,photo'],
            'file' => [
                'required', 'file',
                'mimes:'.implode(',', config('uploads.extensions')),
                'mimetypes:'.implode(',', config('uploads.mimetypes')),
                'max:'.$maxKb,
            ],
        ], [
            'file.max' => 'That file is larger than '.round($maxKb / 1024, 1).' MB. Please choose a smaller one.',
            'file.mimes' => 'Only JPG, PNG or WebP images are allowed.',
            'file.mimetypes' => 'Only JPG, PNG or WebP images are allowed.',
            'file.uploaded' => 'That file could not be uploaded. It may be too large.',
            'file.required' => 'Please choose an image to upload.',
        ]);

        $kind = $request->input('kind');
        $limit = $kind === 'logo' ? config('uploads.max_logo') : config('uploads.max_photos');

        $file = $request->file('file');
        $upload = null;

        // Count and insert under a row lock so two simultaneous uploads cannot slip past the limit.
        DB::transaction(function () use ($supplier, $kind, $limit, $file, &$upload) {
            Supplier::whereKey($supplier->id)->lockForUpdate()->first();

            if ($supplier->uploads()->where('kind', $kind)->count() >= $limit) {
                throw ValidationException::withMessages([
                    'file' => $kind === 'logo'
                        ? 'You can add only '.$limit.' logo. Remove the current one first.'
                        : 'You can add up to '.$limit.' photos. Remove one first.',
                ]);
            }

            $path = $file->store('uploads/'.$supplier->id, config('uploads.disk'));
            $upload = $supplier->uploads()->create([
                'kind' => $kind,
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime' => $file->getMimeType(),
                'size_kb' => (int) ceil($file->getSize() / 1024),
            ]);
        });

        return response()->json(InviteController::uploadPayload($upload), 201);
    }

    public function destroy(string $token, Upload $upload, WebsiteDetailsService $details): JsonResponse
    {
        $supplier = Supplier::where('invite_token', $token)->firstOrFail();
        $details->authorizeEdit($supplier);

        // Another supplier's file is reported as missing.
        abort_unless($upload->supplier_id === $supplier->id, 404);

        Storage::disk(config('uploads.disk'))->delete($upload->path);
        $upload->delete();

        return response()->json(['deleted' => true]);
    }
}
