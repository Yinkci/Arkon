<?php

namespace App\Http\Controllers\Api;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class MediaApiController extends Controller
{
    public function store(Request $request, MediaService $media, MediaSigner $signer): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw new ValidationException($file instanceof UploadedFile && $file->getError() === UPLOAD_ERR_INI_SIZE
                ? 'Images must be 5 MB or smaller'
                : 'Choose an image to upload');
        }
        $asset = $media->upload(AdminContext::of($request)->ctx(), (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());

        // New uploads are private: the canvas needs a signed URL to show them.
        return EditorApiController::ok([...$asset, 'url' => $signer->signUrl($asset['url'])]);
    }
}
