#!/usr/bin/env node
import fs from 'node:fs/promises';
import path from 'node:path';

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
  const form = new FormData();
  form.append('file', new Blob([bytes]), filename);
  const folder = remotePath.includes('/') ? remotePath.slice(0, remotePath.lastIndexOf('/')) : '';
  const url = `${endpoint}&action=upload&space=${encodeURIComponent(space)}&path=${encodeURIComponent(folder)}&name=${encodeURIComponent(filename)}`;
  const response = await fetch(url, {method: 'POST', headers: {Authorization: `Bearer ${token}`}, body: form});
  const result = await response.json();
  if (!response.ok || !result.code) throw new Error(result.data || `HTTP ${response.status}`);
  return result.data;
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
  ['ai_drive_delete', '永久删除文件或目录', {path: {type: 'string'}}],
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
  if (name === 'ai_drive_write_text') return uploadBuffer(a.path, new TextEncoder().encode(a.content), path.basename(a.path));
  if (name === 'ai_drive_upload') return uploadBuffer(a.remotePath, await fs.readFile(a.localPath), path.basename(a.remotePath));
  if (name === 'ai_drive_download') {
    const response = await fetch(`${endpoint}&action=download&space=${encodeURIComponent(space)}&path=${encodeURIComponent(a.remotePath)}`, {headers: {Authorization: `Bearer ${token}`}});
    if (!response.ok) throw new Error(`download failed: HTTP ${response.status}`);
    await fs.writeFile(a.localPath, Buffer.from(await response.arrayBuffer()));
    return {saved: a.localPath};
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
