import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
const root = new URL('../../Public/', import.meta.url);
createServer(async (request, response) => {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    if (pathname === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(
            `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/CSS/editor.css"></head><body><form><div class="forum-rich-editor" data-loading="Loading" data-ready="Ready" data-error="Load failed"><div class="forum-editor-controls"></div><textarea name="text" id="editor">&lt;p&gt;&lt;strong&gt;Bold&lt;/strong&gt; &lt;em&gt;Italic&lt;/em&gt; &lt;u&gt;Underline&lt;/u&gt; 🙂 👨‍👩‍👧‍👦&lt;/p&gt;&lt;blockquote data-post="42"&gt;&lt;p&gt;Quote&lt;/p&gt;&lt;/blockquote&gt;&lt;ul&gt;&lt;li&gt;Item&lt;/li&gt;&lt;/ul&gt;&lt;pre&gt;&lt;code class="language-php"&gt;echo 42;&lt;/code&gt;&lt;/pre&gt;</textarea><div class="forum-editor-host"></div><p role="status"></p></div><button id="submit">Submit</button></form><script>document.querySelector('form').addEventListener('submit', event => {event.preventDefault();document.body.dataset.submitted = new FormData(event.target).get('text')})</script><script type="module" src="/Editor/editor.js"></script></body></html>`,
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
