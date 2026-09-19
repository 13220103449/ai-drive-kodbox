import { createHash } from 'node:crypto';

const api = process.env.AI_DRIVE_URL;
const token = process.env.AI_DRIVE_TOKEN;
const space = process.env.AI_DRIVE_SPACE || 'personal';
if (!api || !token) {
  console.error('AI_DRIVE_URL and AI_DRIVE_TOKEN are required.');
  process.exit(2);
}

const runId = new Date().toISOString().replace(/[-:.TZ]/g, '');
const base = `/_ai_drive_acceptance_${runId}`;
const text = `AI Drive acceptance ${runId}\n`;
const sha256 = value => createHash('sha256').update(value).digest('hex');

async function request(body, expectedCode = true) {
  const response = await fetch(api, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ space, ...body })
  });
  const json = await response.json();
  if (expectedCode && (!response.ok || json.code !== true)) {
    throw new Error(`${body.action} ${body.path || ''}: HTTP ${response.status} ${JSON.stringify(json)}`);
  }
  if (!expectedCode && json.code !== false) {
    throw new Error(`${body.action} ${body.path || ''}: expected code=false, got ${JSON.stringify(json)}`);
  }
  return { response, json };
}

async function upload(folder, name, content) {
  const form = new FormData();
  form.set('action', 'upload');form.set('space', space);form.set('path', folder);form.set('name', name);
  form.set('file', new Blob([content], { type: 'text/plain' }), name);
  const response = await fetch(api, { method: 'POST', headers: { Authorization: `Bearer ${token}` }, body: form });
  const json = await response.json();
  if (!response.ok || json.code !== true) throw new Error(`upload: HTTP ${response.status} ${JSON.stringify(json)}`);
  return json;
}

try {
  await request({ action: 'whoami' });
  await request({ action: 'capabilities' });
  await request({ action: 'mkdir', path: `${base}/source` });
  await upload(`${base}/source`, 'payload.txt', text);

  const listed = await request({ action: 'list', path: `${base}/source` });
  if (!listed.json.data.files.some(item => item.name === 'payload.txt')) throw new Error('list did not return payload.txt');
  const stat = await request({ action: 'stat', path: `${base}/source/payload.txt` });
  if (stat.json.data.size !== Buffer.byteLength(text)) throw new Error('stat size mismatch');
  const read = await request({ action: 'read', path: `${base}/source/payload.txt` });
  if (sha256(read.json.data.content) !== sha256(text)) throw new Error('read SHA-256 mismatch');

  await request({ action: 'rename', path: `${base}/source/payload.txt`, to: 'must-not-work.txt' }, false);
  await request({ action: 'rename', path: `${base}/source/payload.txt`, newName: 'renamed.txt' });
  await request({ action: 'copy', path: `${base}/source/renamed.txt`, to: `${base}/copy/deep/copied.txt` });
  await request({ action: 'stat', path: `${base}/copy/deep/copied.txt` });

  await request({ action: 'mkdir', path: `${base}/empty` });
  await request({ action: 'delete', path: `${base}/empty` });
  const missing = await request({ action: 'stat', path: `${base}/empty` }, false);
  if (missing.response.status !== 404) throw new Error(`deleted empty folder returned HTTP ${missing.response.status}, expected 404`);

  const shared = await request({ action: 'share', path: `${base}/copy/deep` });
  if (!shared.json.data.shareHash || !shared.json.data.url) throw new Error('share did not return shareHash and url');
  console.log(JSON.stringify({ ok: true, base, sha256: sha256(text), checks: 14 }, null, 2));
} finally {
  try { await request({ action: 'delete', path: base }); } catch (error) { console.error(`cleanup warning: ${error.message}`); }
}
