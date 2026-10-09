import type { ApiResult } from './api';
import type { MediaInfo } from '@/types';
import { uploadSizeError } from './mediaPolicy';

/** Upload transfer progress is measured; server validation/variant processing has no invented percentage. */
export function uploadMedia(file: File, progress?: (percent: number) => void): Promise<MediaInfo> {
    if (file.size === 0) return Promise.reject(new Error('The file is empty. Choose another image.'));
    const sizeError = uploadSizeError(file.size);
    if (sizeError) return Promise.reject(new Error(sizeError));
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        request.open('POST', '/admin/api/media');
        request.timeout = 120000;
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        const token = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
        if (token) request.setRequestHeader('X-XSRF-TOKEN', decodeURIComponent(token[1]!));
        request.upload.onprogress = (event) => {
            if (event.lengthComputable) progress?.(Math.min(100, Math.round((event.loaded / event.total) * 100)));
        };
        request.onerror = () => reject(new Error('The upload could not be confirmed. Check your connection and the library before retrying.'));
        request.ontimeout = () => reject(new Error('The upload timed out. Check the library before retrying.'));
        request.onload = () => {
            let result: ApiResult<MediaInfo & { originalName?: string }>;
            try {
                result = JSON.parse(request.responseText);
            } catch {
                reject(
                    new Error(
                        request.status === 413
                            ? 'The server rejected the upload request. Ask the administrator to check PHP and proxy upload limits.'
                            : 'The upload could not be confirmed. Refresh the library before retrying.',
                    ),
                );
                return;
            }
            if (!result || typeof result !== 'object' || typeof result.ok !== 'boolean') {
                reject(new Error('The upload could not be confirmed. Refresh the library before retrying.'));
                return;
            }
            if (!result.ok) {
                reject(new Error(result.message));
                return;
            }
            if (request.status < 200 || request.status >= 300 || !result.data?.id) {
                reject(new Error('The upload could not be confirmed. Refresh the library before retrying.'));
                return;
            }
            resolve({ ...result.data, name: result.data.originalName });
        };
        const data = new FormData();
        data.set('file', file);
        request.send(data);
    });
}
