#!/usr/bin/env node
import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';

const base = (process.env.AI_DRIVE_URL || '').replace(/\/$/, '');
const token = process.env.AI_DRIVE_TOKEN || '';
const space = process.env.AI_DRIVE_SPACE || 'personal';
if (!base || !token) {
  process.stderr.write('AI_DRIVE_URL and AI_DRIVE_TOKEN are required\n');
  process.exit(1);
}
const endpoint = `${base}/index.php?plugin/aiDrive/api`;

async function api(action, args = {}) {
  const response = await fetch(endpoint, {
    method: 'POST',
    headers: {Authorization: `Bearer ${token}`, 'Content-Type': 'application/json'},
    body: JSON.stringify({action, space, ...args})
  });
  const result = await response.json();
  if (!response.ok || !result.code) throw new Error(result.data || `HTTP ${response.status}`);
  return result.data;
}

async function uploadBuffer(remotePath, bytes, filename) {
  const rawPath = String(remotePath || '');
  if (rawPath.split('/').includes('..')) throw new Error('remotePath must not contain parent-directory segments');
  const normalizedPath = path.posix.normalize(`/${rawPath.replace(/^\/+/, '')}`);
  const targetName = path.posix.basename(normalizedPath);
  if (!targetName || targetName === '.' || targetName === '/') throw new Error('remotePath must include a file name');
  const folder = path.posix.dirname(normalizedPath);
  if (folder !== '/') await api('mkdir', {path: folder});
  const form = new FormData();
  form.append('file', new Blob([bytes]), targetName || filename);
  const uploadFolder = folder === '/' ? '' : folder;
  const url = `${endpoint}&action=upload&space=${encodeURIComponent(space)}&path=${encodeURIComponent(uploadFolder)}&name=${encodeURIComponent(targetName)}`;
  const response = await fetch(url, {method: 'POST', headers: {Authorization: `Bearer ${token}`}, body: form});
  const result = await response.json();
  if (!response.ok || !result.code) throw new Error(result.data || `HTTP ${response.status}`);

  const stat = await api('stat', {path: normalizedPath});
  const expectedSize = Buffer.byteLength(bytes);
  if (Number(stat.size) !== expectedSize) {
    throw new Error(`upload verification failed: expected ${expectedSize} bytes, found ${Number(stat.size)}`);
  }
  const downloadUrl = `${endpoint}&action=download&space=${encodeURIComponent(space)}&path=${encodeURIComponent(normalizedPath)}`;
  const downloaded = await fetch(downloadUrl, {headers: {Authorization: `Bearer ${token}`}});
  if (!downloaded.ok || !downloaded.body) throw new Error(`upload verification download failed: HTTP ${downloaded.status}`);
  const digest = createHash('sha256');
  let downloadedSize = 0;
  for await (const chunk of downloaded.body) {
    downloadedSize += chunk.length;
    digest.update(chunk);
  }
  const expectedSha256 = createHash('sha256').update(bytes).digest('hex');
  const actualSha256 = digest.digest('hex');
  if (downloadedSize !== expectedSize || actualSha256 !== expectedSha256) {
    throw new Error(`upload verification failed: SHA-256 or size mismatch for ${normalizedPath}`);
  }
  return {...result.data, verification: {size: expectedSize, sha256: actualSha256, verified: true}};
}

const tools = [
  ['ai_drive_list', '列出 Agent 网盘目录', {path: {type: 'string', description: '相对当前绑定空间的路径，根目录为空'}}],
  ['ai_drive_stat', '读取文件或目录属性', {path: {type: 'string'}}],
  ['ai_drive_read_text', '读取小型文本文件', {path: {type: 'string'}, maxBytes: {type: 'integer'}}],
  ['ai_drive_write_text', '创建或覆盖文本文件', {path: {type: 'string'}, content: {type: 'string'}}],
  ['ai_drive_upload', '把 Agent 所在机器的本地文件上传到网盘', {localPath: {type: 'string'}, remotePath: {type: 'string'}}],
  ['ai_drive_download', '把网盘文件下载到 Agent 所在机器', {remotePath: {type: 'string'}, localPath: {type: 'string'}}],
  ['ai_drive_mkdir', '新建目录', {path: {type: 'string'}}],
  ['ai_drive_rename', '重命名文件或目录', {path: {type: 'string'}, name: {type: 'string'}}],
  ['ai_drive_move', '移动到目标目录', {path: {type: 'string'}, to: {type: 'string'}}],
  ['ai_drive_copy', '复制到目标目录', {path: {type: 'string'}, to: {type: 'string'}}],
  ['ai_drive_delete', '将文件或目录移入网盘回收站（可恢复；不会永久删除）', {path: {type: 'string'}}],
  ['ai_drive_share', '创建公开分享链接', {path: {type: 'string'}, title: {type: 'string'}, password: {type: 'string'}, timeTo: {type: 'integer'}}]
].map(([name, description, properties]) => ({
  name, description,
  inputSchema: {type: 'object', properties, required: Object.keys(properties).filter(k => !['path', 'maxBytes', 'title', 'password', 'timeTo'].includes(k) || ['remotePath', 'localPath'].includes(k))}
}));
for (const tool of tools) {
  if (tool.name !== 'ai_drive_list') tool.inputSchema.required = Object.keys(tool.inputSchema.properties).filter(k => !['maxBytes','title','password','timeTo'].includes(k));
}

async function callTool(name, a) {
  if (name === 'ai_drive_list') return api('list', {path: a.path || ''});
  if (name === 'ai_drive_stat') return api('stat', a);
  if (name === 'ai_drive_read_text') return api('read', {path: a.path, maxBytes: a.maxBytes || 2 * 1024 * 1024});
  if (name === 'ai_drive_write_text') return uploadBuffer(a.path, new TextEncoder().encode(a.content), path.posix.basename(a.path));
  if (name === 'ai_drive_upload') return uploadBuffer(a.remotePath, await fs.readFile(a.localPath), path.posix.basename(a.remotePath));
  if (name === 'ai_drive_download') {
    const response = await fetch(`${endpoint}&action=download&space=${encodeURIComponent(space)}&path=${encodeURIComponent(a.remotePath)}`, {headers: {Authorization: `Bearer ${token}`}});
    if (!response.ok) throw new Error(`download failed: HTTP ${response.status}`);
    const bytes = Buffer.from(await response.arrayBuffer());
    const stat = await api('stat', {path: a.remotePath});
    if (Number(stat.size) !== bytes.length) throw new Error(`download verification failed: expected ${Number(stat.size)} bytes, received ${bytes.length}`);
    await fs.mkdir(path.dirname(path.resolve(a.localPath)), {recursive: true});
    await fs.writeFile(a.localPath, bytes);
    return {saved: a.localPath, size: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex'), verified: true};
  }
  const action = name.replace('ai_drive_', '');
  return api(action, a);
}

function send(message) { process.stdout.write(`${JSON.stringify(message)}\n`); }
let buffer = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => {
  buffer += chunk;
  let newline;
  while ((newline = buffer.indexOf('\n')) >= 0) {
    const line = buffer.slice(0, newline).trim(); buffer = buffer.slice(newline + 1);
    if (!line) continue;
    Promise.resolve().then(async () => {
      const request = JSON.parse(line);
      if (!Object.hasOwn(request, 'id')) return;
      if (request.method === 'initialize') return {protocolVersion: '2024-11-05', capabilities: {tools: {}}, serverInfo: {name: 'ai-drive-mcp', version: '0.2.0'}};
      if (request.method === 'ping') return {};
      if (request.method === 'tools/list') return {tools};
      if (request.method === 'tools/call') {
        try {
          const data = await callTool(request.params.name, request.params.arguments || {});
          return {content: [{type: 'text', text: JSON.stringify(data, null, 2)}]};
        } catch (error) {
          return {isError: true, content: [{type: 'text', text: error.message}]};
        }
      }
      throw new Error(`method not found: ${request.method}`);
    }).then(result => send({jsonrpc: '2.0', id: JSON.parse(line).id, result}), error => send({jsonrpc: '2.0', id: JSON.parse(line).id, error: {code: -32603, message: error.message}}));
  }
});
