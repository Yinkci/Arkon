<?php

namespace App\Http\Controllers\Api;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaLibrary;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Media\UploadPolicy;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class MediaApiController extends Controller
{
    public function search(Request $r, MediaLibrary $library): JsonResponse
    {
        $input = $r->validate(['q' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1|max:100000']);
        $result = $library->browse(AdminContext::of($r)->ctx(), trim($input['q'] ?? ''), 'newest', (int) ($input['page'] ?? 1));
        $result['items'] = array_map(fn ($a) => [...$a, 'name' => $a['title'], 'defaultAlt' => $a['alt'], 'defaultCaption' => $a['caption']], $result['items']);

        return EditorApiController::ok($result);
    }

    public function show(Request $r, string $asset, MediaLibrary $library): JsonResponse
    {
        return EditorApiController::ok($library->detail(AdminContext::of($r)->ctx(), $asset));
    }

    public function update(Request $r, string $asset, MediaLibrary $library): JsonResponse
    {
        $input = $r->validate(['title' => 'required|string|max:200', 'alt' => 'present|nullable|string|max:300', 'caption' => 'present|nullable|string|max:300', 'description' => 'present|nullable|string|max:2000', 'version' => 'required|integer|min:1', 'requestKey' => 'required|string|max:100']);
        $input['version'] = (int) $input['version'];
        foreach (['alt', 'caption', 'description'] as $key) {
            $input[$key] ??= '';
        }

        return EditorApiController::ok($library->save(AdminContext::of($r)->ctx(), $asset, $input));
    }

    public function archive(Request $r, string $asset, MediaLibrary $library): JsonResponse
    {
        $input = $r->validate(['version' => 'required|integer|min:1']);
        $library->archive(AdminContext::of($r)->ctx(), $asset, $input['version']);

        return EditorApiController::ok(['removed' => true]);
    }

    public function store(Request $request, MediaService $media, MediaSigner $signer): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            $error = $file instanceof UploadedFile ? $file->getError() : UPLOAD_ERR_NO_FILE;
            Log::warning('media.upload.rejected', ['stage' => 'php_upload', 'upload_error' => $error, 'runtime_limit_bytes' => UploadPolicy::forRuntime()['effectiveMaxBytes']]);
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The server rejected the upload before Arkon could validate it. The PHP upload limit is '.UploadPolicy::formatBytes(UploadPolicy::iniBytes(ini_get('upload_max_filesize'))).'. Ask the administrator to check the server upload configuration.',
                UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the uploaded file. Ask the administrator to check temporary storage.',
                UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload. Ask the administrator to check the server configuration.',
                default => 'Choose an image to upload',
            };
            throw new ValidationException($message);
        }
        if ($file->getSize() > UploadPolicy::rules()['maxImageUploadBytes']) {
            Log::warning('media.upload.rejected', ['stage' => 'file_size', 'bytes' => $file->getSize(), 'limit_bytes' => UploadPolicy::rules()['maxImageUploadBytes']]);
            throw new ValidationException(UploadPolicy::sizeError($file->getSize()));
        }
        try {
            $asset = $media->upload(AdminContext::of($request)->ctx(), (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
        } catch (ValidationException $error) {
            Log::warning('media.upload.rejected', ['stage' => 'image_validation', 'bytes' => $file->getSize(), 'reason' => $error->getMessage()]);
            throw $error;
        } catch (\RuntimeException $error) {
            report($error);
            throw new ValidationException('The server could not store this image. Please try again or contact the administrator.');
        }

        // New uploads are private: the canvas needs a signed URL to show them.
        return EditorApiController::ok([...$asset, 'url' => $signer->signUrl($asset['url'])]);
    }
}
