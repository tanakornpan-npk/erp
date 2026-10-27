/**
 * Interactive Executive Dashboard
 * ระบบติดตามและรายงานผลการจัดซื้อจัดจ้างงบลงทุนเงินแผ่นดิน ปี 2569
 * มหาวิทยาลัยเกษตรศาสตร์ วิทยาเขตเฉลิมพระเกียรติ จังหวัดสกลนคร
 */

const CONFIG = Object.freeze({
  SPREADSHEET_ID: '1Xo-wAMivk54boKXb7MkAtq7QA1-6C1K2zCme32Ugjf0',
  SHEET_NAME: 'DATA',
  HEADER_ROW: 1,
  TIME_ZONE: 'Asia/Bangkok',
  STALE_DAYS: 30,
  AUTO_REFRESH_SECONDS: 300,
  APP_VERSION: '7.0.0',
  APP_TITLE: 'ระบบติดตามและรายงานผลการจัดซื้อจัดจ้างงบลงทุนเงินแผ่นดิน ปี 2569'
});

const HEADER_ALIASES = Object.freeze({
  owner: ['ผู้รับผิดชอบ'],
  sequence: ['ลำดับ', 'เลขที่'],
  item: ['รายการ', 'รายการจัดซื้อจัดจ้าง'],
  currentStatus: ['สถานะงานปัจจุบัน', 'สถานะปัจจุบัน'],
  department: ['ส่วนงาน', 'หน่วยงาน'],
  budget: ['งบประมาณที่ได้รับ', 'วงเงินงบประมาณ', 'งบประมาณ'],
  summaryStatus: ['สรุปสถานะการดำเนินการ', 'สรุปสถานะ'],
  stage: ['สถานะการดำเนินการ', 'ขั้นตอนการดำเนินการ'],
  recommendation: ['Action Recommendation', 'ข้อเสนอแนะ', 'ข้อเสนอแนะเพื่อดำเนินการ'],
  method: ['จัดซื้อจัดจ้างวิธี', 'วิธีจัดซื้อจัดจ้าง'],
  contracted: ['ราคา/ซื้อจ้างทำสัญญาแล้ว', 'ราคาซื้อจ้างทำสัญญาแล้ว', 'วงเงินตามสัญญา'],
  remaining: ['งบประมาณ /คงเหลือ', 'งบประมาณ/คงเหลือ', 'งบประมาณคงเหลือ']
});

const MILESTONE_HEADERS = Object.freeze([
  'จองงบประมาณ',
  'ร่างขอบเขตของงาน (TOR)',
  'รายงานขอซื้อ/ขอจ้าง',
  'ประกาศร่างเอกสารประกวดราคา',
  'ประกาศประกวดราคา',
  'พิจารณาผลประกวดราคา',
  'ประกาศผู้ชนะการเสนอราคา',
  'จัดทำสัญญาซื้อขาย/จ้าง',
  'การบริหารสัญญา',
  'สัญญาครบกำหนด',
  'ส่งมอบของ/งาน',
  'คณะกรรมการดำเนินการตรวจรับพัสดุ',
  'ส่งเอกสารเบิกจ่ายเงิน'
]);

/**
 * Web app entry point.
 * @return {GoogleAppsScript.HTML.HtmlOutput}
 */
function doGet() {
  return HtmlService.createTemplateFromFile('Index')
    .evaluate()
    .setTitle(CONFIG.APP_TITLE)
    .addMetaTag('viewport', 'width=device-width, initial-scale=1, maximum-scale=1');
}

/**
 * Reads the current spreadsheet and returns normalized dashboard data.
 * No long-lived cache is used so Refresh always reflects the latest sheet values.
 * @return {Object}
 */
function getDashboardData() {
  const startedAt = Date.now();

  try {
    const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
    const sheet = spreadsheet.getSheetByName(CONFIG.SHEET_NAME);

    if (!sheet) {
      throw new Error('ไม่พบชีตชื่อ "' + CONFIG.SHEET_NAME + '" กรุณาตรวจสอบ CONFIG.SHEET_NAME');
    }

    const lastRow = sheet.getLastRow();
    const lastColumn = sheet.getLastColumn();

    if (lastRow < CONFIG.HEADER_ROW || lastColumn < 1) {
      return buildEmptyResponse_(spreadsheet, sheet, startedAt, 'ไม่พบข้อมูลในชีต');
    }

    const range = sheet.getRange(
      CONFIG.HEADER_ROW,
      1,
      lastRow - CONFIG.HEADER_ROW + 1,
      lastColumn
    );

    // Read both raw and formatted values:
    // raw values preserve numbers/dates; formatted values preserve Thai display text.
    const rawValues = range.getValues();
    const displayValues = range.getDisplayValues();
    const headers = displayValues[0].map(function (value) {
      return cleanText_(value);
    });
    const headerMap = buildHeaderMap_(headers);

    assertRequiredHeaders_(headerMap);

    const milestoneIndexes = MILESTONE_HEADERS.map(function (header) {
      return {
        label: header,
        index: findHeaderIndex_(headerMap, [header])
      };
    }).filter(function (item) {
      return item.index > -1;
    });

    const today = startOfDay_(new Date());
    const records = [];
    let skippedBlankRows = 0;

    for (let i = 1; i < displayValues.length; i++) {
      const rawRow = rawValues[i];
      const displayRow = displayValues[i];
      const sourceRow = CONFIG.HEADER_ROW + i;

      if (isBlankDataRow_(displayRow)) {
        skippedBlankRows++;
        continue;
      }

      const record = buildRecord_(
        rawRow,
        displayRow,
        sourceRow,
        headerMap,
        milestoneIndexes,
        today
      );
      records.push(record);
    }

    const response = {
      ok: true,
      appTitle: CONFIG.APP_TITLE,
      appVersion: CONFIG.APP_VERSION,
      spreadsheetTitle: spreadsheet.getName(),
      sheetName: sheet.getName(),
      sourceUrl: spreadsheet.getUrl(),
      generatedAt: Utilities.formatDate(new Date(), CONFIG.TIME_ZONE, 'dd/MM/yyyy HH:mm:ss'),
      generatedAtIso: new Date().toISOString(),
      elapsedMs: Date.now() - startedAt,
      autoRefreshSeconds: CONFIG.AUTO_REFRESH_SECONDS,
      staleDaysThreshold: CONFIG.STALE_DAYS,
      sourceRowCount: Math.max(0, lastRow - CONFIG.HEADER_ROW),
      skippedBlankRows: skippedBlankRows,
      records: records,
      options: buildFilterOptions_(records),
      qualitySummary: buildQualitySummary_(records),
      headers: headers
    };

    return response;
  } catch (error) {
    console.error(error && error.stack ? error.stack : error);
    return {
      ok: false,
      message: error && error.message ? error.message : 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ',
      generatedAt: Utilities.formatDate(new Date(), CONFIG.TIME_ZONE, 'dd/MM/yyyy HH:mm:ss'),
      elapsedMs: Date.now() - startedAt
    };
  }
}

/**
 * Health check useful during deployment troubleshooting.
 * @return {Object}
 */
function healthCheck() {
  const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const sheet = spreadsheet.getSheetByName(CONFIG.SHEET_NAME);

  return {
    ok: Boolean(sheet),
    spreadsheet: spreadsheet.getName(),
    sheet: sheet ? sheet.getName() : null,
    lastRow: sheet ? sheet.getLastRow() : 0,
    lastColumn: sheet ? sheet.getLastColumn() : 0,
    checkedAt: Utilities.formatDate(new Date(), CONFIG.TIME_ZONE, 'dd/MM/yyyy HH:mm:ss')
  };
}

function buildRecord_(rawRow, displayRow, sourceRow, headerMap, milestoneIndexes, today) {
  const owner = getDisplayField_(displayRow, headerMap, HEADER_ALIASES.owner);
  const sequence = getDisplayField_(displayRow, headerMap, HEADER_ALIASES.sequence);
  const item = getDisplayField_(displayRow, headerMap, HEADER_ALIASES.item);
  const currentStatus = getDisplayField_(displayRow, headerMap, HEADER_ALIASES.currentStatus);
  const department = getDisplayField_(displayRow, headerMap, HEADER_ALIASES.department);
  const summaryStatus = getDisplayField_(displayRow, headerMap, HEADER_ALIASES.summaryStatus);
  const stage = getDisplayField_(displayRow, headerMap, HEADER_ALIASES.stage);
  const recommendation = getDisplayField_(displayRow, headerMap, HEADER_ALIASES.recommendation);
  const method = getDisplayField_(displayRow, headerMap, HEADER_ALIASES.method);

  const budget = getNumberField_(rawRow, displayRow, headerMap, HEADER_ALIASES.budget);
  const contracted = getNumberField_(rawRow, displayRow, headerMap, HEADER_ALIASES.contracted);
  const statedRemaining = getNumberField_(rawRow, displayRow, headerMap, HEADER_ALIASES.remaining);
  const calculatedRemaining = Math.max(0, budget - contracted);
  const remaining = statedRemaining !== null ? statedRemaining : calculatedRemaining;

  const milestones = milestoneIndexes.map(function (milestone) {
    const rawValue = rawRow[milestone.index];
    const displayValue = cleanText_(displayRow[milestone.index]);
    const dates = extractDates_(rawValue, displayValue);

    return {
      label: milestone.label,
      value: displayValue,
      validDateIso: dates.length ? dates[dates.length - 1].toISOString() : null,
      hasSuspiciousDate: hasSuspiciousDate_(displayValue, dates)
    };
  }).filter(function (milestone) {
    return milestone.value !== '';
  });

  const latestDate = getLatestMilestoneDate_(milestones);
  const latestUpdateIso = latestDate ? latestDate.toISOString() : null;
  const latestUpdate = latestDate
    ? Utilities.formatDate(latestDate, CONFIG.TIME_ZONE, 'dd/MM/yyyy')
    : '';
  const staleDays = latestDate ? daysBetween_(latestDate, today) : null;

  const complete = isComplete_(summaryStatus, stage);
  const progress = calculateProgress_(stage, complete);
  const dataIssues = validateRecord_({
    owner: owner,
    item: item,
    department: department,
    budget: budget,
    contracted: contracted,
    remaining: remaining,
    calculatedRemaining: calculatedRemaining,
    summaryStatus: summaryStatus,
    milestones: milestones
  });

  const riskLevel = calculateRiskLevel_({
    complete: complete,
    recommendation: recommendation
  });
  const trackingStatus = complete
    ? 'เสร็จแล้ว'
    : (recommendation || 'ปกติ');

  return {
    id: String(sourceRow),
    sourceRow: sourceRow,
    sequence: sequence || String(sourceRow - CONFIG.HEADER_ROW),
    owner: owner || 'ไม่ระบุ',
    item: item || 'ไม่ระบุรายการ',
    currentStatus: currentStatus || 'ไม่ระบุ',
    department: department || 'ไม่ระบุ',
    budget: roundMoney_(budget),
    summaryStatus: summaryStatus || 'ไม่ระบุ',
    stage: stage || 'ไม่ระบุ',
    recommendation: recommendation,
    method: method || 'ไม่ระบุ',
    contracted: roundMoney_(contracted),
    remaining: roundMoney_(remaining),
    progress: progress,
    complete: complete,
    latestUpdate: latestUpdate,
    latestUpdateIso: latestUpdateIso,
    staleDays: staleDays,
    attention: !complete && Boolean(recommendation),
    riskLevel: riskLevel,
    trackingStatus: trackingStatus,
    dataIssues: dataIssues,
    milestones: milestones
  };
}

function buildHeaderMap_(headers) {
  const map = {};
  headers.forEach(function (header, index) {
    const normalized = normalizeHeader_(header);
    if (normalized && map[normalized] === undefined) {
      map[normalized] = index;
    }
  });
  return map;
}

function assertRequiredHeaders_(headerMap) {
  const required = [
    ['ผู้รับผิดชอบ', HEADER_ALIASES.owner],
    ['รายการ', HEADER_ALIASES.item],
    ['ส่วนงาน', HEADER_ALIASES.department],
    ['งบประมาณที่ได้รับ', HEADER_ALIASES.budget],
    ['สถานะการดำเนินการ', HEADER_ALIASES.stage]
  ];

  const missing = required.filter(function (entry) {
    return findHeaderIndex_(headerMap, entry[1]) === -1;
  }).map(function (entry) {
    return entry[0];
  });

  if (missing.length) {
    throw new Error('ไม่พบหัวคอลัมน์ที่จำเป็น: ' + missing.join(', '));
  }
}

function findHeaderIndex_(headerMap, aliases) {
  for (let i = 0; i < aliases.length; i++) {
    const key = normalizeHeader_(aliases[i]);
    if (Object.prototype.hasOwnProperty.call(headerMap, key)) {
      return headerMap[key];
    }
  }
  return -1;
}

function getDisplayField_(displayRow, headerMap, aliases) {
  const index = findHeaderIndex_(headerMap, aliases);
  return index > -1 ? cleanText_(displayRow[index]) : '';
}

function getNumberField_(rawRow, displayRow, headerMap, aliases) {
  const index = findHeaderIndex_(headerMap, aliases);
  if (index === -1) return null;
  return parseNumber_(rawRow[index], displayRow[index]);
}

function parseNumber_(rawValue, displayValue) {
  if (typeof rawValue === 'number' && isFinite(rawValue)) {
    return rawValue;
  }

  const text = thaiDigitsToArabic_(String(displayValue || rawValue || ''))
    .replace(/\s/g, '')
    .replace(/,/g, '')
    .replace(/[฿บาท]/g, '');

  if (!text) return null;

  const isNegative = /^\(.*\)$/.test(text);
  const normalized = text.replace(/[()]/g, '');
  const value = Number(normalized);

  if (!isFinite(value)) return null;
  return isNegative ? -value : value;
}

function extractDates_(rawValue, displayValue) {
  const dates = [];

  if (Object.prototype.toString.call(rawValue) === '[object Date]' && !isNaN(rawValue.getTime())) {
    dates.push(startOfDay_(rawValue));
    return dates;
  }

  const text = thaiDigitsToArabic_(cleanText_(displayValue || rawValue));
  const regex = /(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/g;
  let match;

  while ((match = regex.exec(text)) !== null) {
    let day = Number(match[1]);
    let month = Number(match[2]);
    let year = Number(match[3]);

    if (year < 100) year += 2500;
    if (year >= 2400) year -= 543;

    const date = new Date(year, month - 1, day);
    if (
      date.getFullYear() === year &&
      date.getMonth() === month - 1 &&
      date.getDate() === day
    ) {
      dates.push(startOfDay_(date));
    }
  }

  dates.sort(function (a, b) {
    return a.getTime() - b.getTime();
  });
  return dates;
}

function hasSuspiciousDate_(displayValue, dates) {
  const text = thaiDigitsToArabic_(cleanText_(displayValue));
  const dateLike = /\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}/.test(text);

  if (dateLike && dates.length === 0) return true;

  return dates.some(function (date) {
    const year = date.getFullYear();
    return year < 2020 || year > 2100;
  });
}

function getLatestMilestoneDate_(milestones) {
  const validDates = milestones.filter(function (m) { return m.label !== 'สัญญาครบกำหนด'; }).map(function (milestone) {
    return milestone.validDateIso ? new Date(milestone.validDateIso) : null;
  }).filter(function (date) {
    return date && !isNaN(date.getTime()) && date.getFullYear() >= 2020 && date.getFullYear() <= 2100;
  });

  if (!validDates.length) return null;

  return new Date(Math.max.apply(null, validDates.map(function (date) {
    return date.getTime();
  })));
}

function validateRecord_(record) {
  const issues = [];

  if (!record.owner) issues.push('ไม่ระบุผู้รับผิดชอบ');
  if (!record.item) issues.push('ไม่ระบุรายการ');
  if (!record.department) issues.push('ไม่ระบุส่วนงาน');
  if (record.budget === null || record.budget <= 0) issues.push('งบประมาณไม่ถูกต้องหรือไม่ระบุ');
  if (!record.summaryStatus) issues.push('ไม่ระบุสรุปสถานะ');

  if (
    record.budget !== null &&
    record.contracted !== null &&
    record.contracted > record.budget + 1
  ) {
    issues.push('วงเงินตามสัญญาสูงกว่างบประมาณ');
  }

  if (
    record.budget !== null &&
    record.contracted !== null &&
    record.remaining !== null &&
    Math.abs(record.calculatedRemaining - record.remaining) > 1.01
  ) {
    issues.push('ยอดงบประมาณคงเหลือไม่สัมพันธ์กับงบประมาณและวงเงินสัญญา');
  }

  if (record.milestones.some(function (milestone) { return milestone.hasSuspiciousDate; })) {
    issues.push('พบรูปแบบวันที่ที่ควรตรวจสอบ');
  }

  return issues;
}

function calculateRiskLevel_(record) {
  if (record.complete) return 'complete';

  const recommendation = cleanText_(record.recommendation);
  if (!recommendation) return 'normal';

  // ใช้ Action Recommendation ในชีตเป็นเกณฑ์สถานะติดตามหลัก
  // ระดับสูงจะแสดงเฉพาะเมื่อในข้อความระบุความเสี่ยงสูง/วิกฤต/เร่งด่วนมากอย่างชัดเจน
  if (/(ความเสี่ยงสูง|วิกฤต|เร่งด่วนมาก)/.test(recommendation)) {
    return 'high';
  }

  return 'medium';
}

function calculateProgress_(stage, complete) {
  if (complete) return 100;

  const normalized = normalizeHeader_(stage);
  const stages = [
    { aliases: ['จองงบประมาณ'], value: 8 },
    { aliases: ['ร่างขอบเขตของงานtor', 'ร่างขอบเขตของงาน'], value: 15 },
    { aliases: ['รายงานขอซื้อขอจ้าง'], value: 25 },
    { aliases: ['ประกาศร่างเอกสารประกวดราคา'], value: 35 },
    { aliases: ['ประกาศประกวดราคา'], value: 45 },
    { aliases: ['พิจารณาผลประกวดราคา'], value: 55 },
    { aliases: ['ประกาศผู้ชนะการเสนอราคา'], value: 65 },
    { aliases: ['จัดทำสัญญาซื้อขายจ้าง'], value: 75 },
    { aliases: ['การบริหารสัญญา'], value: 82 },
    { aliases: ['ส่งมอบของงาน'], value: 90 },
    { aliases: ['คณะกรรมการดำเนินการตรวจรับพัสดุ', 'ตรวจรับพัสดุ'], value: 95 },
    { aliases: ['ส่งเอกสารเบิกจ่ายเงิน'], value: 100 }
  ];

  for (let i = 0; i < stages.length; i++) {
    if (stages[i].aliases.some(function (alias) { return normalized.indexOf(alias) > -1; })) {
      return stages[i].value;
    }
  }

  return 0;
}

function isComplete_(summaryStatus, stage) {
  const text = cleanText_(summaryStatus + ' ' + stage).toLowerCase();
  return /(เสร็จ|เบิกจ่ายเงินแล้ว|ส่งเอกสารเบิกจ่ายเงิน)/.test(text);
}

function buildFilterOptions_(records) {
  return {
    owners: uniqueSorted_(records.map(function (record) { return record.owner; })),
    departments: uniqueSorted_(records.map(function (record) { return record.department; })),
    summaryStatuses: uniqueSorted_(records.map(function (record) { return record.summaryStatus; })),
    stages: uniqueSorted_(records.map(function (record) { return record.stage; })),
    methods: uniqueSorted_(records.map(function (record) { return record.method; }))
  };
}

function buildQualitySummary_(records) {
  return {
    recordsWithIssues: records.filter(function (record) { return record.dataIssues.length > 0; }).length,
    suspiciousDates: records.filter(function (record) {
      return record.dataIssues.indexOf('พบรูปแบบวันที่ที่ควรตรวจสอบ') > -1;
    }).length,
    missingLatestUpdate: records.filter(function (record) { return !record.latestUpdateIso; }).length,
    highRisk: records.filter(function (record) { return record.riskLevel === 'high'; }).length,
    mediumRisk: records.filter(function (record) { return record.riskLevel === 'medium'; }).length
  };
}

function buildEmptyResponse_(spreadsheet, sheet, startedAt, message) {
  return {
    ok: true,
    appTitle: CONFIG.APP_TITLE,
    spreadsheetTitle: spreadsheet.getName(),
    sheetName: sheet.getName(),
    sourceUrl: spreadsheet.getUrl(),
    generatedAt: Utilities.formatDate(new Date(), CONFIG.TIME_ZONE, 'dd/MM/yyyy HH:mm:ss'),
    generatedAtIso: new Date().toISOString(),
    elapsedMs: Date.now() - startedAt,
    autoRefreshSeconds: CONFIG.AUTO_REFRESH_SECONDS,
    staleDaysThreshold: CONFIG.STALE_DAYS,
    sourceRowCount: 0,
    skippedBlankRows: 0,
    records: [],
    options: {
      owners: [],
      departments: [],
      summaryStatuses: [],
      stages: [],
      methods: []
    },
    qualitySummary: {
      recordsWithIssues: 0,
      suspiciousDates: 0,
      missingLatestUpdate: 0,
      highRisk: 0,
      mediumRisk: 0
    },
    headers: [],
    message: message
  };
}

function uniqueSorted_(values) {
  const seen = {};
  const result = [];

  values.forEach(function (value) {
    const clean = cleanText_(value);
    if (!clean || seen[clean]) return;
    seen[clean] = true;
    result.push(clean);
  });

  return result.sort(function (a, b) {
    return a.localeCompare(b, 'th');
  });
}

function isBlankDataRow_(displayRow) {
  return displayRow.every(function (value) {
    return cleanText_(value) === '';
  });
}

function normalizeHeader_(value) {
  return thaiDigitsToArabic_(cleanText_(value))
    .toLowerCase()
    .replace(/[\s\-_/().]+/g, '')
    .replace(/[“”"'`]/g, '');
}

function cleanText_(value) {
  if (value === null || value === undefined) return '';
  return String(value)
    .replace(/[\r\n\t]+/g, ' ')
    .replace(/\s{2,}/g, ' ')
    .trim();
}

function thaiDigitsToArabic_(value) {
  const thaiDigits = '๐๑๒๓๔๕๖๗๘๙';
  return String(value).replace(/[๐-๙]/g, function (digit) {
    return String(thaiDigits.indexOf(digit));
  });
}

function startOfDay_(date) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function daysBetween_(fromDate, toDate) {
  const diff = startOfDay_(toDate).getTime() - startOfDay_(fromDate).getTime();
  return Math.max(0, Math.floor(diff / 86400000));
}

function roundMoney_(value) {
  if (value === null || !isFinite(value)) return 0;
  return Math.round((value + Number.EPSILON) * 100) / 100;
}
