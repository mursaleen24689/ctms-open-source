const HEADER_ALIASES = {
  sample: ['sample', 'sample ref', 'sample reference', 'reference number', 'sd no', 'sample id', 'specimen', 'specimen id'],
  parameter: ['parameter', 'test parameter', 'element', 'element symbol', 'analyte'],
  range: ['range', 'specified range', 'requirement', 'specification'],
  observed: ['observed', 'observed value', 'result', 'reading', 'value', 'measured value'],
  remarks: ['remarks', 'comment', 'comments', 'note'],
};

export function normalizeImportHeader(value) {
  return String(value || '').toLowerCase().replace(/[%()[\]{}._-]/g, ' ').replace(/\s+/g, ' ').trim();
}

export function parseInstrumentCsv(text) {
  const source = String(text || '').replace(/^\uFEFF/, '');
  const firstLine = source.split(/\r?\n/, 1)[0] || '';
  let delimiter = ',';
  let bestCount = -1;
  for (const candidate of [',', '\t', ';']) {
    let count = 0;
    let quoted = false;
    for (let i = 0; i < firstLine.length; i += 1) {
      if (firstLine[i] === '"' && firstLine[i + 1] === '"' && quoted) i += 1;
      else if (firstLine[i] === '"') quoted = !quoted;
      else if (firstLine[i] === candidate && !quoted) count += 1;
    }
    if (count > bestCount) { bestCount = count; delimiter = candidate; }
  }
  const rows = [];
  let row = [];
  let cell = '';
  let quoted = false;
  for (let i = 0; i < source.length; i += 1) {
    const char = source[i];
    if (char === '"' && quoted && source[i + 1] === '"') { cell += '"'; i += 1; }
    else if (char === '"') quoted = !quoted;
    else if (char === delimiter && !quoted) { row.push(cell.trim()); cell = ''; }
    else if ((char === '\n' || char === '\r') && !quoted) {
      if (char === '\r' && source[i + 1] === '\n') i += 1;
      row.push(cell.trim());
      if (row.some(value => value !== '')) rows.push(row);
      row = []; cell = '';
    } else cell += char;
  }
  row.push(cell.trim());
  if (row.some(value => value !== '')) rows.push(row);
  if (quoted) throw new Error('The CSV file has an unclosed quote.');
  if (rows.length < 2) throw new Error('The file needs a header row and at least one reading.');
  if (rows.length > 5001) throw new Error('The file is too large. Import up to 5,000 readings at a time.');
  const headers = rows[0];
  if (headers.length > 40) throw new Error('The file has too many columns. Import up to 40 columns at a time.');
  if (headers.some(header => !header)) throw new Error('Every column must have a header.');
  if (new Set(headers.map(normalizeImportHeader)).size !== headers.length) throw new Error('The file contains duplicate column headers.');
  return { headers, rows: rows.slice(1).map(values => headers.map((_, index) => values[index] || '')), delimiter };
}

export function findInstrumentHeader(headers, kind) {
  const aliases = (HEADER_ALIASES[kind] || []).map(normalizeImportHeader);
  return headers.findIndex(header => aliases.includes(normalizeImportHeader(header)));
}
