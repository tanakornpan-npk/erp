/**
 * Local Node.js Server & Data Sync Utility
 * ระบบติดตามและรายงานผลการจัดซื้อจัดจ้างงบลงทุนเงินแผ่นดิน ปี 2569
 *
 * Usage:
 *   node server.js          -> Starts local web server at http://localhost:3000
 *   node server.js --sync   -> Fetches latest Google Sheet data and updates data/mock-data.json & data/mock-data.js
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const { execSync, spawnSync } = require('child_process');

const PORT = process.env.PORT || 3000;

function syncData() {
  console.log('กำลังดึงข้อมูลล่าสุดจาก Google Sheets ผ่าน api.php...');
  if (!fs.existsSync(path.join(__dirname, 'data'))) {
    fs.mkdirSync(path.join(__dirname, 'data'), { recursive: true });
  }
  const output = execSync('php api.php', { cwd: __dirname, encoding: 'utf8' });
  const parsed = JSON.parse(output);
  if (!parsed.ok) {
    throw new Error('API Error: ' + parsed.message);
  }
  fs.writeFileSync(path.join(__dirname, 'data', 'mock-data.json'), JSON.stringify(parsed, null, 2), 'utf8');
  fs.writeFileSync(
    path.join(__dirname, 'data', 'mock-data.js'),
    'window.__MOCK_DASHBOARD_DATA__ = ' + JSON.stringify(parsed) + ';\n',
    'utf8'
  );
  console.log(`อัปเดตข้อมูลสำเร็จ! จำนวน ${parsed.records.length} รายการ (บันทึกใน data/mock-data.json และ data/mock-data.js)`);
  return parsed;
}

if (process.argv.includes('--sync')) {
  syncData();
  process.exit(0);
}

const MIME_TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.csv': 'text/csv; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml'
};

function runPhpScript(scriptName, req, rawBody) {
  const urlParts = req.url.split('?');
  const queryString = urlParts[1] || '';
  const authToken = req.headers['x-auth-token'] || '';

  const env = Object.assign({}, process.env, {
    REQUEST_METHOD: req.method,
    HTTP_X_QUERY_STRING: queryString,
    HTTP_X_AUTH_TOKEN: authToken,
    HTTP_X_RAW_BODY: rawBody,
    HTTP_X_ENFORCE_AUTH: '1'
  });

  const result = spawnSync('php ' + scriptName, {
    cwd: __dirname,
    env,
    shell: true,
    encoding: 'utf8',
    maxBuffer: 10 * 1024 * 1024
  });

  return result.stdout || JSON.stringify({ ok: false, message: result.stderr || 'PHP execution failed' });
}

const server = http.createServer((req, res) => {
  const urlPath = req.url.split('?')[0];

  // Block direct access to sensitive files in /data/ (except mock-data.js for offline preview)
  if (urlPath.startsWith('/data/') && urlPath !== '/data/mock-data.js') {
    res.writeHead(403, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify({ ok: false, message: 'Forbidden: Direct access to data files is not allowed' }));
    return;
  }

  if (urlPath === '/auth.php' || urlPath === '/api.php' || urlPath === '/api') {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', () => {
      const script = urlPath === '/auth.php' ? 'auth.php' : 'api.php';
      const out = runPhpScript(script, req, body);
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(out);
    });
    return;
  }

  let filePath = path.join(__dirname, urlPath === '/' ? 'index.html' : urlPath);
  if (!filePath.startsWith(__dirname)) {
    res.writeHead(403);
    res.end('Forbidden');
    return;
  }

  if (fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
    const ext = path.extname(filePath).toLowerCase();
    res.writeHead(200, { 'Content-Type': MIME_TYPES[ext] || 'application/octet-stream' });
    fs.createReadStream(filePath).pipe(res);
  } else {
    res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('404 Not Found');
  }
});

server.listen(PORT, () => {
  console.log(`=============================================================`);
  console.log(`🚀 ระบบพร้อมใช้งานที่: http://localhost:${PORT}`);
  console.log(`🔐 Auth Endpoint:      http://localhost:${PORT}/auth.php`);
  console.log(`📊 API Endpoint:       http://localhost:${PORT}/api.php`);
  console.log(`=============================================================`);
});
