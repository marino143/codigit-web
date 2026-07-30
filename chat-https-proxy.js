#!/usr/bin/env node
/**
 * HTTPS proxy za privatni chat: prima TLS promet na portu 8443
 * (Tailscale certifikat) i prosljeđuje ga PHP serveru na 127.0.0.1:8080.
 * Postoji jer `tailscale serve` na ovoj macOS verziji ne sprema konfiguraciju.
 */
'use strict';

const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');

// Hostname se čita iz imena certifikata u tls/ mapi — ništa nije tvrdo ukodirano
const TLS_DIR = path.join(__dirname, 'tls');
const crtFile = fs.existsSync(TLS_DIR) && fs.readdirSync(TLS_DIR).find(f => f.endsWith('.crt'));
if (!crtFile) {
    console.error('No TLS certificate found. Run:  mkdir -p ' + TLS_DIR + ' && cd ' + TLS_DIR + ' && tailscale cert <your-machine>.<tailnet>.ts.net');
    process.exit(1);
}
const HOST = crtFile.replace(/\.crt$/, '');
const CERT = path.join(TLS_DIR, HOST + '.crt');
const KEY = path.join(TLS_DIR, HOST + '.key');
const LISTEN_PORT = 8443;
const TARGET = { host: '127.0.0.1', port: 8080 };

function tlsOptions() {
    return { cert: fs.readFileSync(CERT), key: fs.readFileSync(KEY) };
}

const server = https.createServer(tlsOptions(), (req, res) => {
    const headers = { ...req.headers, 'x-forwarded-proto': 'https' };
    const proxied = http.request(
        { ...TARGET, path: req.url, method: req.method, headers },
        pres => {
            res.writeHead(pres.statusCode, pres.headers);
            pres.pipe(res);
        }
    );
    proxied.on('error', () => {
        res.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' });
        res.end('Chat server is not available (502).');
    });
    req.pipe(proxied);
});

// Nakon obnove certifikata (tjedni launchd job) novi cert se učita bez restarta
fs.watch(TLS_DIR, () => {
    setTimeout(() => {
        try { server.setSecureContext(tlsOptions()); } catch (e) { /* pola zapisa — čekamo idući event */ }
    }, 1000);
});

server.listen(LISTEN_PORT, '0.0.0.0', () => {
    console.log(`HTTPS proxy: https://${HOST}:${LISTEN_PORT} -> http://${TARGET.host}:${TARGET.port}`);
});
