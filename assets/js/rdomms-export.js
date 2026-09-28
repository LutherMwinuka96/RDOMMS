/**
 * Shared RDOMMS export helper — PDF default, Word/Excel where supported.
 */
(function (global) {
  const DEFAULT_FORMAT = 'pdf';

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function closeTag(name) {
    return '<' + '/' + name + '>';
  }

  function normalizeRows(rows) {
    return (rows || []).map((row) => (Array.isArray(row) ? row : Object.values(row)));
  }

  function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    setTimeout(() => {
      URL.revokeObjectURL(url);
      if (link.parentNode) link.parentNode.removeChild(link);
    }, 500);
  }

  function buildTableHtml(title, subtitle, headers, rows, options) {
    const opts = options || {};
    const headerCells = headers.map((h) => `<th>${escapeHtml(h)}</th>`).join('');
    const bodyRows = normalizeRows(rows).map((row, index) => {
      const cells = row.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('');
      const alt = index % 2 === 0 ? ' style="background:#f9fafb;"' : '';
      return `<tr${alt}>${cells}</tr>`;
    }).join('');

    return [
      '<div style="font-family:Arial,Helvetica,sans-serif;padding:20px;color:#16201a;">',
      `<h2 style="color:#21482d;margin:0 0 8px;">${escapeHtml(title || 'RDO|MMS Export')}</h2>`,
      subtitle ? `<p style="margin:0 0 16px;color:#66786f;font-size:13px;">${escapeHtml(subtitle)}</p>` : '',
      '<table style="border-collapse:collapse;width:100%;font-size:12px;">',
      `<tr style="background:#21482d;color:#fff;">${headerCells}</tr>`,
      bodyRows,
      '</table>',
      '</div>'
    ].join('');
  }

  function exportCsv(headers, rows, filename) {
    const safeRows = normalizeRows(rows).map((row) =>
      row.map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(',')
    );
    const csv = [headers.map((h) => `"${String(h).replace(/"/g, '""')}"`).join(','), ...safeRows].join('\n');
    downloadBlob(new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8;' }), `${filename}.csv`);
  }

  function exportExcel(headers, rows, filename, sheetName) {
    const data = [headers, ...normalizeRows(rows)];

    if (typeof global.XLSX !== 'undefined') {
      const workbook = global.XLSX.utils.book_new();
      const worksheet = global.XLSX.utils.aoa_to_sheet(data);
      global.XLSX.utils.book_append_sheet(workbook, worksheet, (sheetName || 'Export').slice(0, 31));
      global.XLSX.writeFile(workbook, `${filename}.xlsx`);
      return;
    }

    let html = '<table><thead><tr>';
    headers.forEach((h) => { html += `<th>${escapeHtml(h)}</th>`; });
    html += '</tr></thead><tbody>';
    normalizeRows(rows).forEach((row) => {
      html += '<tr>';
      row.forEach((cell) => { html += `<td>${escapeHtml(cell)}</td>`; });
      html += '</tr>';
    });
    html += '</tbody></table>';
    downloadBlob(new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' }), `${filename}.xls`);
  }

  function exportWord(title, subtitle, headers, rows, filename) {
    const headerRow = headers.map((h) => `<th>${escapeHtml(h)}</th>`).join('');
    const bodyRows = normalizeRows(rows).map((row) => {
      const cells = row.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('');
      return `<tr>${cells}</tr>`;
    }).join('');

    const html = [
      '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">',
      '<head><meta charset="utf-8">',
      `<title>${escapeHtml(title || 'Export')}</title>`,
      '<style>body{font-family:Arial,sans-serif}table{border-collapse:collapse;width:100%}th,td{border:1px solid #333;padding:8px}th{background:#21482d;color:#fff}</style>',
      '</head><body>',
      `<h2>${escapeHtml(title || 'RDO|MMS Export')}</h2>`,
      subtitle ? `<p>${escapeHtml(subtitle)}</p>` : '',
      `<table><tr>${headerRow}</tr>${bodyRows}</table>`,
      '</body></html>'
    ].join('');

    downloadBlob(new Blob(['\uFEFF', html], { type: 'application/msword' }), `${filename}.doc`);
  }

  function exportPdfPrint(title, subtitle, headers, rows, filename) {
    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${escapeHtml(title || 'Export')}</title>
      <style>body{font-family:Arial,sans-serif;padding:24px}h1{font-size:20px;color:#21482d}p{color:#66786f}
      table{width:100%;border-collapse:collapse;font-size:11px}th,td{border:1px solid #ccc;padding:6px;text-align:left}
      th{background:#21482d;color:#fff}@media print{body{padding:0}}</style></head><body>
      <h1>${escapeHtml(title || 'RDO|MMS Export')}</h1>
      ${subtitle ? `<p>${escapeHtml(subtitle)}</p>` : ''}
      ${buildTableHtml('', '', headers, rows).replace(/^<div[^>]*>|<\/div>$/g, '')}
      <script>window.onload=function(){window.print();};<\/script></body></html>`;

    const win = global.open('', '_blank');
    if (!win) {
      throw new Error('Pop-up blocked. Allow pop-ups to export PDF.');
    }
    win.document.write(html);
    win.document.close();
  }

  function exportPdfHtml2pdf(title, subtitle, headers, rows, filename, options) {
    const opts = options || {};
    const element = document.createElement('div');
    element.innerHTML = buildTableHtml(title, subtitle, headers, rows, opts);
    document.body.appendChild(element);

    const settings = {
      margin: opts.margin ?? 10,
      filename: `${filename}.pdf`,
      image: { type: 'jpeg', quality: 0.98 },
      html2canvas: { scale: 2 },
      jsPDF: { unit: 'mm', format: 'a4', orientation: opts.orientation || 'landscape' }
    };

    return global.html2pdf().set(settings).from(element).save().finally(() => {
      if (element.parentNode) element.parentNode.removeChild(element);
    });
  }

  function exportPdf(title, subtitle, headers, rows, filename, options) {
    if (typeof global.html2pdf === 'function') {
      return exportPdfHtml2pdf(title, subtitle, headers, rows, filename, options);
    }
    exportPdfPrint(title, subtitle, headers, rows, filename);
    return Promise.resolve();
  }

  function exportData(payload) {
    const format = String(payload.format || DEFAULT_FORMAT).toLowerCase();
    const filename = payload.filename || `rdomms-export-${new Date().toISOString().slice(0, 10)}`;
    const title = payload.title || 'RDO|MMS Export';
    const orgName = global.RDOMMSSettings?.getOrganizationName?.('');
    const orgSuffix = orgName ? ` | ${orgName}` : '';
    const subtitle = payload.subtitle || `Generated: ${new Date().toLocaleString()}${orgSuffix}`;
    const headers = payload.headers || [];
    const rows = payload.rows || [];

    if (!headers.length) {
      throw new Error('Export headers are required.');
    }

    switch (format) {
      case 'pdf':
        return exportPdf(title, subtitle, headers, rows, filename, payload.pdf || {});
      case 'excel':
      case 'xlsx':
        exportExcel(headers, rows, filename, payload.sheetName);
        return Promise.resolve();
      case 'word':
      case 'doc':
        exportWord(title, subtitle, headers, rows, filename);
        return Promise.resolve();
      case 'csv':
        exportCsv(headers, rows, filename);
        return Promise.resolve();
      default:
        throw new Error(`Unsupported export format: ${format}`);
    }
  }

  function getFormatFromSelect(selectEl) {
    if (!selectEl) return DEFAULT_FORMAT;
    return String(selectEl.value || DEFAULT_FORMAT).toLowerCase();
  }

  function wireButton(button, getPayload, formatSelect, onComplete) {
    if (!button) return;
    button.addEventListener('click', async () => {
      try {
        const payload = typeof getPayload === 'function' ? getPayload() : getPayload;
        if (!payload) return;
        const format = getFormatFromSelect(formatSelect);
        await exportData({ ...payload, format });
        if (typeof onComplete === 'function') onComplete(format);
      } catch (error) {
        if (typeof onComplete === 'function') onComplete(null, error);
        else throw error;
      }
    });
  }

  function renderFormatSelect(id, options) {
    const opts = options || {};
    const includeCsv = opts.includeCsv === true;
    const parts = [
      '<option value="pdf" selected>PDF</option>',
      '<option value="word">Word</option>',
      '<option value="excel">Excel</option>'
    ];
    if (includeCsv) parts.push('<option value="csv">CSV</option>');
    return `<select id="${id}" class="${opts.className || 'export-format-select'}">${parts.join('')}</select>`;
  }

  global.RDOMMSExport = {
    DEFAULT_FORMAT,
    exportData,
    exportPdf,
    exportExcel,
    exportWord,
    exportCsv,
    wireButton,
    renderFormatSelect,
    buildTableHtml
  };
})(typeof window !== 'undefined' ? window : this);
