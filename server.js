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
const { execSync } = require('child_process');

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

const server = http.createServer((req, res) => {
  const urlPath = req.url.split('?')[0];

  if (urlPath === '/api.php' || urlPath === '/api') {
    try {
      const output = execSync('php api.php', { cwd: __dirname, encoding: 'utf8' });
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(output);
    } catch (err) {
      const fallbackPath = path.join(__dirname, 'data', 'mock-data.json');
      if (fs.existsSync(fallbackPath)) {
        res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
        res.end(fs.readFileSync(fallbackPath, 'utf8'));
      } else {
        res.writeHead(500, { 'Content-Type': 'application/json; charset=utf-8' });
        res.end(JSON.stringify({ ok: false, message: err.message }));
      }
    }
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
  console.log(`📊 API Endpoint:       http://localhost:${PORT}/api.php`);
  console.log(`=============================================================`);
});
