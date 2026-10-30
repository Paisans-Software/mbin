/**
 * @param {RequestInfo} url
 * @param {RequestInit} options
 * @returns {Promise<Response>}
 */
export async function fetch(url = '', options = {}) {
    if ('object' === typeof url && null !== url) {
        options = url;
        url = options.url;
    }

    options = { ...options };
    options.credentials = options.credentials || 'same-origin';
    options.redirect = options.redirect || 'error';
    options.headers = {
        ...options.headers,
        'X-Requested-With': 'XMLHttpRequest',
    };

    return window.fetch(url, options);
}

/**
 * POSTs a body like fetch() does, but reports upload progress, which fetch() cannot.
 * Resolves to a Response so callers can use ok() and .json() as usual.
 *
 * @param {string} url
 * @param {FormData} body
 * @param {function(ProgressEvent): void} onProgress
 * @returns {Promise<Response>}
 */
export function postWithProgress(url, body, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.responseType = 'blob';
        xhr.upload.addEventListener('progress', onProgress);
        xhr.addEventListener('load', () => {
            // Match fetch()'s redirect: 'error' in fetch() above.
            if (xhr.responseURL && xhr.responseURL !== new URL(url, window.location.href).href) {
                reject(new TypeError('Unexpected redirect'));

                return;
            }
            const body = [204, 205, 304].includes(xhr.status) ? null : xhr.response;
            resolve(new Response(body, { status: xhr.status, statusText: xhr.statusText }));
        });
        xhr.addEventListener('error', () => reject(new TypeError('Network request failed')));
        xhr.addEventListener('abort', () => reject(new TypeError('Network request aborted')));
        xhr.send(body);
    });
}

export async function ok(response) {
    if (!response.ok) {
        const e = new Error(response.statusText);
        e.response = response;

        throw e;
    }

    return response;
}

/**
 * Throws the response if not ok, otherwise, call .json()
 * @param {Response} response
 * @return {Promise<any>}
 */
export function ThrowResponseIfNotOk(response) {
    if (!response.ok) {
        throw response;
    }
    return response.json();
}
