import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
const root = new URL('../../Public/', import.meta.url);
createServer(async (request, response) => {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    if (pathname === '/gallery') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(
            `<!doctype html><html lang="de"><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/CSS/gallery.css"></head><body><div class="tx-typo3forum"><div class="tx-typo3forum-post"><a data-forum-gallery href="/gallery-one.png"><img width="80" height="60" alt="First" src="/gallery-one.png"></a><a data-forum-gallery href="/gallery-two.png"><img width="80" height="60" alt="Second" src="/gallery-two.png"></a><a data-forum-gallery href="/gallery-one.png"><img width="80" height="60" alt="Duplicate attachment" src="/gallery-one.png"></a></div><div class="tx-typo3forum-post"><a data-forum-gallery href="/missing.png"><img width="80" height="60" alt="Missing" src="/gallery-one.png"></a></div></div><script type="module" src="/Editor/gallery.js"></script></body></html>`,
        );
        return;
    }
    if (['/gallery-one.png', '/gallery-two.png'].includes(pathname)) {
        response
            .writeHead(200, { 'Content-Type': 'image/png' })
            .end(
                Buffer.from(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jS1kAAAAASUVORK5CYII=',
                    'base64',
                ),
            );
        return;
    }
    if (pathname === '/submit-fixture') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(
            `<!doctype html><html lang="de"><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/CSS/submit.css"><style>.btn {display:inline-block}</style></head><body><div class="tx-typo3forum"><form id="send" method="post" action="/sent"><input name="subject" required><button id="primary" type="submit" name="action" value="save">Beitrag erstellen</button></form><input id="external" form="send" type="submit" name="action" value="external" class="btn"></div><script type="module">import '/Editor/submit.js'; window.submitGuardReady = true;</script></body></html>`,
        );
        return;
    }
    if (pathname === '/sent') {
        request.resume();
        setTimeout(
            () =>
                response
                    .writeHead(200, { 'Content-Type': 'text/html' })
                    .end('<p>Sent</p>'),
            500,
        );
        return;
    }
    if (pathname === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(
            `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/CSS/editor.css"></head><body><form><div class="forum-rich-editor" data-loading="Loading" data-ready="Ready" data-error="Load failed"><div class="forum-editor-controls"></div><textarea name="text" id="editor">&lt;p&gt;&lt;strong&gt;Bold&lt;/strong&gt; &lt;em&gt;Italic&lt;/em&gt; &lt;u&gt;Underline&lt;/u&gt; 🙂 👨‍👩‍👧‍👦&lt;/p&gt;&lt;blockquote data-post="42"&gt;&lt;p&gt;Quote&lt;/p&gt;&lt;/blockquote&gt;&lt;ul&gt;&lt;li&gt;Item&lt;/li&gt;&lt;/ul&gt;&lt;pre&gt;&lt;code class="language-php"&gt;echo 42;&lt;/code&gt;&lt;/pre&gt;</textarea><div class="forum-editor-host"></div><p role="status"></p></div><input type="file" name="newAttachments[]" multiple><button id="submit">Submit</button></form><script>document.querySelector('form').addEventListener('submit', event => {event.preventDefault();document.body.dataset.submitted = new FormData(event.target).get('text')})</script><script type="module" src="/Editor/editor.js"></script></body></html>`,
        );
        return;
    }
    if (
        !/^\/(?:Editor|CSS)\/[a-zA-Z0-9_./-]+$/.test(pathname) ||
        pathname.includes('..')
    ) {
        response.writeHead(404).end();
        return;
    }
    try {
        const contents = await readFile(new URL('.' + pathname, root));
        response.setHeader(
            'Content-Type',
            pathname.endsWith('.js')
                ? 'text/javascript'
                : pathname.endsWith('.css')
                  ? 'text/css'
                  : 'application/octet-stream',
        );
        response.end(contents);
    } catch {
        response.writeHead(404).end();
    }
}).listen(8791, '127.0.0.1');
