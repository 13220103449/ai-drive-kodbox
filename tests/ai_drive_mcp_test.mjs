import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawn } from 'node:child_process';
import { createInterface } from 'node:readline';
import { createServer } from 'node:http';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const files = new Map();
const folders = new Set(['/']);
let corruptDownloads = false;
const server = createServer(async (request, response) => {
  const url = new URL(request.url, 'http://localhost');
  const chunks = [];
  for await (const chunk of request) chunks.push(chunk);
  const body = Buffer.concat(chunks);
  const sendJson = (data, code = true, status = 200) => {
    response.writeHead(status, {'content-type': 'application/json'});
    response.end(JSON.stringify({code, data}));
  };
  if (url.searchParams.get('action') === 'download') {
    const filePath = `/${url.searchParams.get('path') || ''}`.replace(/\/{2,}/g, '/');
    const content = files.get(filePath);
    if (!content) return sendJson('not found', false, 404);
    response.writeHead(200, {'content-type': 'application/octet-stream'});
    return response.end(corruptDownloads ? Buffer.from('corrupted') : content);
  }
  if (request.method === 'POST' && (request.headers['content-type'] || '').includes('multipart/form-data')) {
    const folder = `/${url.searchParams.get('path') || ''}`.replace(/\/{2,}/g, '/').replace(/\/$/, '');
    const name = url.searchParams.get('name');
    const boundary = request.headers['content-type'].match(/boundary=([^;]+)/)?.[1];
    const raw = body.toString('binary');
    const start = raw.indexOf('\r\n\r\n');
    const end = raw.lastIndexOf('\r\n--');
    if (!boundary || start < 0 || end < start) return sendJson('invalid multipart', false, 400);
    files.set(`${folder}/${name}`.replace(/^\/\//, '/'), Buffer.from(raw.slice(start + 4, end), 'binary'));
    return sendJson({path: `${folder}/${name}`});
  }
  let input;
  try { input = JSON.parse(body.toString()); } catch { return sendJson('invalid JSON', false, 400); }
  const inputPath = `/${String(input.path || '').replace(/^\/+/, '')}`.replace(/\/{2,}/g, '/').replace(/\/$/, '') || '/';
  if (input.action === 'mkdir') {
    folders.add(inputPath);
    return sendJson(inputPath);
  }
  if (input.action === 'stat') {
    const content = files.get(inputPath);
    if (content) return sendJson({path: inputPath, size: content.length, type: 'file'});
    if (folders.has(inputPath)) return sendJson({path: inputPath, size: 0, type: 'folder'});
    return sendJson('path not found', false, 404);
  }
  if (input.action === 'delete') return sendJson({deleted: true, recycled: true});
  return sendJson({ok: true});
});

await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const address = server.address();
const tempDir = await fs.mkdtemp(path.join(os.tmpdir(), 'ai-drive-mcp-test-'));
const sourceFile = path.join(tempDir, 'binary.dat');
const expected = Buffer.from(Array.from({length: 256 * 1024}, (_, i) => (i * 131 + 17) % 256));
await fs.writeFile(sourceFile, expected);
const child = spawn(process.execPath, [fileURLToPath(new URL('../integrations/ai-drive-mcp/server.mjs', import.meta.url))], {
  env: {...process.env, AI_DRIVE_URL: `http://127.0.0.1:${address.port}`, AI_DRIVE_TOKEN: 'test-token'},
  stdio: ['pipe', 'pipe', 'pipe']
});
const lines = createInterface({input: child.stdout});
const pending = new Map();
lines.on('line', line => {
  let message;
  try { message = JSON.parse(line); } catch { return; }
  const resolve = pending.get(message.id);
  if (resolve) { pending.delete(message.id); resolve(message); }
});
let nextId = 0;
function rpc(method, params = {}) {
  const id = ++nextId;
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`MCP request timed out: ${method}`)); }, 5000);
    pending.set(id, message => { clearTimeout(timeout); resolve(message); });
    child.stdin.write(`${JSON.stringify({jsonrpc: '2.0', id, method, params})}\n`);
  });
}

try {
  const listed = await rpc('tools/list');
  const deleteTool = listed.result.tools.find(tool => tool.name === 'ai_drive_delete');
  assert.match(deleteTool.description, /回收站/);
  assert.match(deleteTool.description, /不会永久删除/);

  const uploaded = await rpc('tools/call', {name: 'ai_drive_upload', arguments: {localPath: sourceFile, remotePath: '/reports/2026/binary.dat'}});
  assert.equal(uploaded.result.isError, undefined);
  const uploadResult = JSON.parse(uploaded.result.content[0].text);
  const digest = createHash('sha256').update(expected).digest('hex');
  assert.equal(uploadResult.verification.verified, true);
  assert.equal(uploadResult.verification.size, expected.length);
  assert.equal(uploadResult.verification.sha256, digest);
  assert.ok(folders.has('/reports/2026'), 'MCP should create missing parent folders');

  corruptDownloads = true;
  const mismatch = await rpc('tools/call', {name: 'ai_drive_write_text', arguments: {path: '/reports/2026/text.txt', content: 'expected'}});
  assert.equal(mismatch.result.isError, true, 'MCP must reject read-back checksum mismatches');
  assert.match(mismatch.result.content[0].text, /verification failed/);

  console.log('AI Drive MCP integrity tests passed (soft-delete wording, recursive destination creation, binary SHA-256 read-back, mismatch rejection).');
} finally {
  child.kill();
  await new Promise(resolve => server.close(resolve));
  await fs.rm(tempDir, {recursive: true, force: true});
}
