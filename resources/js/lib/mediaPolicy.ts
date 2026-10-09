import rules from '../../arkon/media.json';

export function formatBytes(n: number): string {
    const units = ['B', 'KiB', 'MiB', 'GiB'];
    const index = n < 1024 ? 0 : Math.min(3, Math.floor(Math.log(n) / Math.log(1024)));
    return `${Number((n / 1024 ** index).toFixed(1))} ${units[index]}`;
}
export function uploadPolicy() {
    let effectiveMaxBytes = rules.maxImageUploadBytes;
    const content = typeof document !== 'undefined' ? document.querySelector?.('meta[name="arkon-upload-policy"]')?.getAttribute('content') : null;
    if (content) {
        try {
            const runtime = JSON.parse(content);
            if (Number.isSafeInteger(runtime.effectiveMaxBytes) && runtime.effectiveMaxBytes >= 0)
                effectiveMaxBytes = Math.min(effectiveMaxBytes, runtime.effectiveMaxBytes);
        } catch {
            /* Backend validation remains authoritative if metadata is unavailable. */
        }
    }
    return { ...rules, effectiveMaxBytes, runtimeLimited: effectiveMaxBytes < rules.maxImageUploadBytes };
}
export function uploadHelp() {
    const policy = uploadPolicy();
    return `JPEG, PNG, WebP, AVIF or GIF · maximum ${formatBytes(policy.effectiveMaxBytes)}${policy.runtimeLimited ? ' (server limit; administrator configuration needs attention)' : ''}`;
}
export function uploadSizeError(bytes: number): string | null {
    const policy = uploadPolicy();
    if (bytes > policy.maxImageUploadBytes)
        return `This image is ${formatBytes(bytes)} (${bytes} bytes). The maximum image size is ${formatBytes(policy.maxImageUploadBytes)} (${policy.maxImageUploadBytes} bytes).`;
    if (bytes > policy.effectiveMaxBytes)
        return `This image is within Arkon's ${formatBytes(policy.maxImageUploadBytes)} limit, but this server currently allows only ${formatBytes(policy.effectiveMaxBytes)}. Ask the administrator to check the upload configuration.`;
    return null;
}
