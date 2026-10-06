/**
 * A client-generated key identifying one request intent (save batch, publish
 * intent, create, title/URL change). It is reused only to retry that same intent.
 *
 * 128 bits from the platform's cryptographic generator, as 32 lowercase hex
 * characters (the same format as before: a UUID without dashes). It uses
 * crypto.getRandomValues, which every browser provides, rather than
 * crypto.randomUUID, which only exists in secure contexts (HTTPS or localhost)
 * and is therefore missing at http://arkonlaravel.test.
 */
export function newRequestKey(): string {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    let key = '';
    for (const byte of bytes) key += byte.toString(16).padStart(2, '0');
    return key;
}
